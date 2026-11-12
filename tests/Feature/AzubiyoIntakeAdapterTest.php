<?php

namespace Tests\Feature;

use App\Enums\IntakeRunSiteStatus;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use App\Models\Listing;
use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\Azubiyo\AzubiyoIntakeAdapter;
use App\Services\Azubiyo\FakeAzubiyoHttpClient;
use App\Services\FixtureSiteIntakeAdapter;
use App\Services\SiteIntakeAdapterRegistry;
use App\Services\ThrowingSiteIntakeAdapter;
use App\Services\UpsertListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AzubiyoIntakeAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_http_client_returns_search_fixture_bytes(): void
    {
        $fake = new FakeAzubiyoHttpClient;

        $body = $fake->get('https://www.azubiyo.de/stellenmarkt/');

        $this->assertStringContainsString('data-joinera-fixture="search"', $body);
    }

    public function test_fake_http_client_returns_detail_fixture_bytes(): void
    {
        $fake = new FakeAzubiyoHttpClient;

        $body = $fake->get(
            'https://www.azubiyo.de/stellenanzeigen/ausbildung-zum-kaufmann-fuer-bueromanagement-m-w-d_expert_66195a74/',
        );

        $this->assertStringContainsString('data-joinera-fixture="detail"', $body);
    }

    public function test_adapter_is_instantiable_and_search_uses_fake_fixture(): void
    {
        config(['joinera.sites.adapters.azubiyo.max_pages' => 1]);

        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);

        $result = $adapter->search();

        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertStringContainsString('data-joinera-fixture="search"', $result['body']);
        $this->assertCount(1, $result['pages']);
    }

    /**
     * Selectors: div.job-offer-teaser, a[href*="/stellenanzeigen/"], span.h3 / text-secondary.
     * external_id = trailing token after last _ on /stellenanzeigen/{slug}_{id}/.
     */
    public function test_parse_list_from_fixture_yields_stable_external_ids_and_absolute_urls(): void
    {
        config(['joinera.sites.adapters.azubiyo.max_pages' => 1]);

        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());

        // Fixture has 2 valid cards + 1 broken card without trailing id — broken is skipped.
        $this->assertCount(2, $items);

        $externalIds = [];

        foreach ($items as $item) {
            $this->assertNotSame('', $item['external_id'] ?? '');
            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $item['external_id']);
            $this->assertStringStartsWith('https://', $item['url'] ?? '');
            $this->assertStringContainsString('/stellenanzeigen/', $item['url']);
            $this->assertSame('ausbildung', $item['kind'] ?? null);
            $this->assertNotSame('', $item['title'] ?? '');
            $this->assertNotSame('', $item['company'] ?? '');
            $this->assertNotSame('', $item['city'] ?? '');

            $externalIds[] = $item['external_id'];
        }

        $this->assertSame($externalIds, array_values(array_unique($externalIds)));
        $this->assertContains('66195a74', $externalIds);
        $this->assertContains('bga9a4a4', $externalIds);
        $this->assertSame('21684 Stade', $items[0]['city']);
        $this->assertSame('expert SE', $items[0]['company']);
    }

    public function test_parse_list_unions_unique_external_ids_across_pages(): void
    {
        config(['joinera.sites.adapters.azubiyo.max_pages' => 5]);

        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);

        $search = $adapter->search();
        $items = $adapter->parseList($search);

        // page1: 2 valid, page2: 2 valid, page3 empty → stop. Union = 4.
        $this->assertCount(4, $items);
        $this->assertCount(2, $search['pages']);

        $externalIds = array_column($items, 'external_id');
        $this->assertSame($externalIds, array_values(array_unique($externalIds)));
        $this->assertContains('66195a74', $externalIds);
        $this->assertContains('bga9a4a4', $externalIds);
        $this->assertContains('eacaa2g6', $externalIds);
        $this->assertContains('9g2g5g8b', $externalIds);
    }

    public function test_search_stops_early_when_later_pages_add_no_new_ids(): void
    {
        config(['joinera.sites.adapters.azubiyo.max_pages' => 5]);

        // Force /stellenmarkt/2+/ to replay page 1 so dedupe finds zero new ids.
        $fake = (new FakeAzubiyoHttpClient)->withPathOverrides([
            '/stellenmarkt/2' => 'search_page_1.html',
            '/stellenmarkt/3' => 'search_page_1.html',
            '/stellenmarkt/4' => 'search_page_1.html',
            '/stellenmarkt/5' => 'search_page_1.html',
        ]);
        $adapter = new AzubiyoIntakeAdapter($fake);

        $search = $adapter->search();

        $this->assertCount(2, $search['pages']);
        $this->assertCount(2, $fake->requestedUrls);
        $this->assertCount(2, $adapter->parseList($search));
    }

    /**
     * Detail: JobPosting JSON-LD + a[href*="bewerben"]; external_id stays list trailing token.
     */
    public function test_parse_detail_from_fixture_returns_upsert_payload(): void
    {
        config(['joinera.sites.adapters.azubiyo.max_pages' => 1]);

        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());
        $listItem = $items[0];
        $detail = $adapter->parseDetail($listItem);

        $this->assertSame($listItem['external_id'], $detail['external_id']);
        $this->assertSame('66195a74', $detail['external_id']);
        $this->assertNotSame('AZY-66195A74', $detail['external_id']);
        $this->assertSame('ausbildung', $detail['kind']);
        $this->assertNotSame('', $detail['title'] ?? '');
        $this->assertNotSame('', $detail['company'] ?? '');
        $this->assertNotSame('', $detail['city'] ?? '');
        $this->assertSame($listItem['url'], $detail['url']);
        $this->assertStringStartsWith('https://', $detail['apply_url'] ?? '');
        $this->assertStringContainsString('bewerben', $detail['apply_url']);
        $this->assertNotSame($detail['url'], $detail['apply_url']);
        $this->assertNotSame('', $detail['description'] ?? '');
        $this->assertNull($detail['language_requirement']);
        $this->assertSame('Hauptschulabschluss', $detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('2026-11-02', $detail['posted_at']);
        $this->assertNull($detail['category']);
        $this->assertArrayNotHasKey('user_id', $detail);
        $this->assertArrayNotHasKey('site_identity_id', $detail);
    }

    public function test_parse_detail_education_requirement_null_when_absent(): void
    {
        $noEduUrl = 'https://www.azubiyo.de/stellenanzeigen/auszubildende-fachinformatikerin-fachinformatiker-_bundeskriminalamt_bga9a4a4/';
        $fake = (new FakeAzubiyoHttpClient)->withPathOverrides([
            'bga9a4a4' => 'detail_without_education.html',
        ]);
        $adapter = new AzubiyoIntakeAdapter($fake);

        $detail = $adapter->parseDetail([
            'external_id' => 'bga9a4a4',
            'url' => $noEduUrl,
            'title' => 'Auszubildende Fachinformatikerin/Fachinformatiker für Systemintegration (w/m/d)',
            'company' => 'Bundeskriminalamt',
            'city' => '12435 Berlin',
            'kind' => 'ausbildung',
        ]);

        $this->assertNull($detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('bga9a4a4', $detail['external_id']);
    }

    public function test_parse_detail_then_upsert_creates_once_and_dedupes_by_external_id(): void
    {
        Event::fake();
        config(['joinera.sites.adapters.azubiyo.max_pages' => 1]);

        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);
        $upsert = $this->app->make(UpsertListing::class);

        $items = $adapter->parseList($adapter->search());
        $detail = $adapter->parseDetail($items[0]);

        $first = $upsert->handle('azubiyo', $detail);
        $this->assertNotNull($first);
        $this->assertTrue($first['created']);

        $second = $upsert->handle('azubiyo', $detail);
        $this->assertNotNull($second);
        $this->assertFalse($second['created']);
        $this->assertTrue($first['listing']->is($second['listing']));
        $this->assertSame(1, Listing::query()->where('source', 'azubiyo')->count());
        $this->assertSame('Hauptschulabschluss', $first['listing']->education_requirement);
        $this->assertSame('DE', $first['listing']->country);
        $this->assertNotNull($first['listing']->apply_url);
        $this->assertNotNull($first['listing']->description);
        $this->assertNotNull($first['listing']->posted_at);
    }

    public function test_registry_registers_azubiyo_and_ausbildung_de_by_default(): void
    {
        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);

        $this->assertInstanceOf(
            AusbildungDeIntakeAdapter::class,
            $registry->get('ausbildung.de'),
        );
        $this->assertInstanceOf(
            AzubiyoIntakeAdapter::class,
            $registry->get('azubiyo'),
        );
    }

    public function test_intake_run_with_azubiyo_creates_listings_via_fake_http(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['azubiyo'],
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
        ]);

        $this->artisan('intake:run')->assertSuccessful();

        $site = IntakeRunSite::query()->where('site', 'azubiyo')->first();
        $this->assertNotNull($site);
        $this->assertSame(IntakeRunSiteStatus::Ok, $site->status);
        $this->assertGreaterThan(0, $site->listings_created);
        $this->assertSame(
            $site->listings_created,
            Listing::query()->where('source', 'azubiyo')->count(),
        );
        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
    }

    public function test_intake_run_with_both_boards_creates_listings_from_each_source(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'azubiyo'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
        ]);

        $this->artisan('intake:run')->assertSuccessful();

        $ausbildungSite = IntakeRunSite::query()->where('site', 'ausbildung.de')->first();
        $azubiyoSite = IntakeRunSite::query()->where('site', 'azubiyo')->first();

        $this->assertNotNull($ausbildungSite);
        $this->assertNotNull($azubiyoSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $ausbildungSite->status);
        $this->assertSame(IntakeRunSiteStatus::Ok, $azubiyoSite->status);
        $this->assertGreaterThan(0, Listing::query()->where('source', 'ausbildung.de')->count());
        $this->assertGreaterThan(0, Listing::query()->where('source', 'azubiyo')->count());
    }

    public function test_intake_run_one_real_board_throwing_still_completes_sibling_boards(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'azubiyo'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
        ]);

        $this->app->make(SiteIntakeAdapterRegistry::class)
            ->register('azubiyo', new ThrowingSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $azubiyoSite = IntakeRunSite::query()->where('site', 'azubiyo')->first();
        $ausbildungSite = IntakeRunSite::query()->where('site', 'ausbildung.de')->first();

        $this->assertNotNull($azubiyoSite);
        $this->assertSame(IntakeRunSiteStatus::Failed, $azubiyoSite->status);
        $this->assertNotEmpty($azubiyoSite->error);
        $this->assertSame(0, $azubiyoSite->listings_created);

        $this->assertNotNull($ausbildungSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $ausbildungSite->status);
        $this->assertGreaterThan(0, $ausbildungSite->listings_created);

        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
        $this->assertGreaterThan(0, Listing::query()->where('source', 'ausbildung.de')->count());
        $this->assertSame(0, Listing::query()->where('source', 'azubiyo')->count());
    }

    public function test_intake_run_loops_three_sites_when_middle_throws(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'azubiyo', 'fixture_c'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
        ]);

        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);
        $registry->register('azubiyo', new ThrowingSiteIntakeAdapter);
        $registry->register('fixture_c', new FixtureSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $ausbildungSite = IntakeRunSite::query()->where('site', 'ausbildung.de')->first();
        $azubiyoSite = IntakeRunSite::query()->where('site', 'azubiyo')->first();
        $fixtureSite = IntakeRunSite::query()->where('site', 'fixture_c')->first();

        $this->assertNotNull($ausbildungSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $ausbildungSite->status);
        $this->assertGreaterThan(0, $ausbildungSite->listings_created);

        $this->assertNotNull($azubiyoSite);
        $this->assertSame(IntakeRunSiteStatus::Failed, $azubiyoSite->status);
        $this->assertNotEmpty($azubiyoSite->error);
        $this->assertSame(0, $azubiyoSite->listings_created);

        $this->assertNotNull($fixtureSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $fixtureSite->status);
        $this->assertGreaterThan(0, $fixtureSite->listings_created);

        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
        $this->assertGreaterThan(0, Listing::query()->where('source', 'ausbildung.de')->count());
        $this->assertSame(0, Listing::query()->where('source', 'azubiyo')->count());
        $this->assertGreaterThan(0, Listing::query()->where('source', 'fixture_c')->count());
    }
}
