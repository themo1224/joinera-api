<?php

namespace Tests\Feature;

use App\Enums\IntakeRunSiteStatus;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use App\Models\Listing;
use App\Services\ArbeitsagenturDe\ArbeitsagenturDeIntakeAdapter;
use App\Services\ArbeitsagenturDe\FakeArbeitsagenturDeHttpClient;
use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\Azubiyo\AzubiyoIntakeAdapter;
use App\Services\SiteIntakeAdapterRegistry;
use App\Services\ThrowingSiteIntakeAdapter;
use App\Services\UpsertListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ArbeitsagenturDeIntakeAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_http_client_returns_jobs_and_ausbildung_search_fixtures(): void
    {
        $fake = new FakeArbeitsagenturDeHttpClient;

        $jobs = $fake->get(
            'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v6/jobs?angebotsart=1&page=1&size=25',
        );
        $ausbildung = $fake->get(
            'https://rest.arbeitsagentur.de/infosysbub/absuche/pc/v1/ausbildungsangebot?sty=0&page=0&size=20',
        );

        $this->assertStringContainsString('jobs_search', $jobs);
        $this->assertStringContainsString('10000-1184867112-S', $jobs);
        $this->assertStringContainsString('ausbildung_search', $ausbildung);
        $this->assertStringContainsString('"id": 17885', $ausbildung);
    }

    public function test_fake_http_client_returns_detail_fixtures(): void
    {
        $fake = new FakeArbeitsagenturDeHttpClient;

        $jobsDetail = $fake->get(
            'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v4/jobdetails/'.rawurlencode(base64_encode('10000-1184867112-S')),
        );
        $ausbildungDetail = $fake->get(
            'https://rest.arbeitsagentur.de/infosysbub/absuche/pc/v1/ausbildungsangebot/17885',
        );

        $this->assertStringContainsString('jobs_detail', $jobsDetail);
        $this->assertStringContainsString('stellenangebotsTitel', $jobsDetail);
        $this->assertStringContainsString('ausbildung_detail', $ausbildungDetail);
        $this->assertStringContainsString('Musteranbieter', $ausbildungDetail);
    }

    public function test_adapter_is_instantiable_and_search_uses_fake_fixture(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $result = $adapter->search();

        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertCount(2, $result['pages']);
        $this->assertSame('jobs', $result['pages'][0]['channel']);
        $this->assertSame('ausbildung', $result['pages'][1]['channel']);
        $this->assertStringContainsString('jobs_search', $result['body']);
    }

    public function test_parse_list_from_fixture_yields_stable_external_ids_urls_and_kinds(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());

        // jobs: 2 valid + 1 broken; ausbildung: 2 valid + 1 broken → 4
        $this->assertCount(4, $items);

        $jobs = array_values(array_filter($items, fn (array $i): bool => ($i['kind'] ?? null) === 'job'));
        $ausbildung = array_values(array_filter($items, fn (array $i): bool => ($i['kind'] ?? null) === 'ausbildung'));

        $this->assertCount(2, $jobs);
        $this->assertCount(2, $ausbildung);

        foreach ($items as $item) {
            $this->assertNotSame('', $item['external_id'] ?? '');
            $this->assertStringStartsWith('https://www.arbeitsagentur.de/', $item['url'] ?? '');
            $this->assertContains($item['kind'], ['job', 'ausbildung']);
            $this->assertNotSame('', $item['title'] ?? '');
            $this->assertNotSame('', $item['company'] ?? '');
            $this->assertNotSame('', $item['city'] ?? '');
        }

        $jobIds = array_column($jobs, 'external_id');
        $this->assertContains('10000-1184867112-S', $jobIds);
        $this->assertContains('10000-1183999289-S', $jobIds);
        $this->assertStringContainsString('/jobsuche/jobdetail/', $jobs[0]['url']);

        $ausbildungIds = array_column($ausbildung, 'external_id');
        $this->assertContains('17885', $ausbildungIds);
        $this->assertContains('17886', $ausbildungIds);
        $this->assertStringContainsString('/ausbildungssuche/ausbildungsangebot/', $ausbildung[0]['url']);
        $this->assertSame('67071 Ludwigshafen am Rhein', $ausbildung[0]['city']);
    }

    public function test_parse_list_unions_unique_external_ids_across_pages(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 5]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $search = $adapter->search();
        $items = $adapter->parseList($search);

        // jobs pages 1+2 then empty; ausbildung pages 1+2 then empty → 4 pages; 4+4 items
        $this->assertCount(4, $search['pages']);
        $this->assertCount(8, $items);

        $externalIds = array_column($items, 'external_id');
        $this->assertSame($externalIds, array_values(array_unique($externalIds)));
        $this->assertContains('10000-1184867112-S', $externalIds);
        $this->assertContains('10001-1002716922-S', $externalIds);
        $this->assertContains('17885', $externalIds);
        $this->assertContains('19001', $externalIds);
    }

    public function test_search_stops_early_when_later_pages_add_no_new_ids(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 5]);

        $fake = (new FakeArbeitsagenturDeHttpClient)->withPathOverrides([
            'page=2' => 'jobs_search_page_1.json',
            'page=3' => 'jobs_search_page_1.json',
            'page=4' => 'jobs_search_page_1.json',
            'page=5' => 'jobs_search_page_1.json',
            'page=1&size=20' => 'ausbildung_search_page_1.json',
            'page=2&size=20' => 'ausbildung_search_page_1.json',
            'page=3&size=20' => 'ausbildung_search_page_1.json',
            'page=4&size=20' => 'ausbildung_search_page_1.json',
        ]);
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $search = $adapter->search();

        // Each channel: page1 + duplicate page2 → stop (2 pages × 2 channels)
        $this->assertCount(4, $search['pages']);
        $this->assertCount(4, $fake->requestedUrls);
        $this->assertCount(4, $adapter->parseList($search));
    }

    public function test_parse_detail_jobs_from_fixture_returns_upsert_payload(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());
        $job = collect($items)->firstWhere('kind', 'job');
        $this->assertNotNull($job);

        $detail = $adapter->parseDetail($job);

        $this->assertSame($job['external_id'], $detail['external_id']);
        $this->assertSame('10000-1184867112-S', $detail['external_id']);
        $this->assertSame('job', $detail['kind']);
        $this->assertNotSame('', $detail['title'] ?? '');
        $this->assertNotSame('', $detail['company'] ?? '');
        $this->assertNotSame('', $detail['city'] ?? '');
        $this->assertSame($job['url'], $detail['url']);
        $this->assertStringStartsWith('https://', $detail['apply_url'] ?? '');
        $this->assertNotSame('', $detail['description'] ?? '');
        $this->assertNull($detail['language_requirement']);
        $this->assertNull($detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('2021-07-25', $detail['posted_at']);
        $this->assertSame('Elektrotechnik', $detail['category']);
        $this->assertArrayNotHasKey('user_id', $detail);
        $this->assertArrayNotHasKey('site_identity_id', $detail);
    }

    public function test_parse_detail_ausbildung_from_fixture_returns_upsert_payload(): void
    {
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());
        $row = collect($items)->firstWhere('external_id', '17885');
        $this->assertNotNull($row);

        $detail = $adapter->parseDetail($row);

        $this->assertSame('17885', $detail['external_id']);
        $this->assertSame('ausbildung', $detail['kind']);
        $this->assertStringContainsString('Physiotherapeut', $detail['title'] ?? '');
        $this->assertSame('Musteranbieter', $detail['company']);
        $this->assertSame('67071 Ludwigshafen am Rhein', $detail['city']);
        $this->assertSame($row['url'], $detail['url']);
        $this->assertStringStartsWith('https://', $detail['apply_url'] ?? '');
        $this->assertNotSame('', $detail['description'] ?? '');
        $this->assertNotNull($detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertArrayNotHasKey('session_id', $detail);
    }

    public function test_parse_detail_education_requirement_null_when_absent(): void
    {
        $fake = (new FakeArbeitsagenturDeHttpClient)->withPathOverrides([
            '/ausbildungsangebot/17886' => 'ausbildung_detail_without_education.json',
        ]);
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);

        $detail = $adapter->parseDetail([
            'external_id' => '17886',
            'url' => 'https://www.arbeitsagentur.de/ausbildungssuche/ausbildungsangebot/17886',
            'title' => 'Fachinformatiker/in für Anwendungsentwicklung',
            'company' => 'Tech Ausbildung Berlin',
            'city' => '10115 Berlin',
            'kind' => 'ausbildung',
        ]);

        $this->assertNull($detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('17886', $detail['external_id']);
    }

    public function test_parse_detail_then_upsert_creates_once_and_dedupes_by_external_id(): void
    {
        Event::fake();
        config(['joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1]);

        $fake = new FakeArbeitsagenturDeHttpClient;
        $adapter = new ArbeitsagenturDeIntakeAdapter($fake);
        $upsert = $this->app->make(UpsertListing::class);

        $items = $adapter->parseList($adapter->search());
        $job = collect($items)->firstWhere('kind', 'job');
        $detail = $adapter->parseDetail($job);

        $first = $upsert->handle('arbeitsagentur.de', $detail);
        $this->assertNotNull($first);
        $this->assertTrue($first['created']);

        $second = $upsert->handle('arbeitsagentur.de', $detail);
        $this->assertNotNull($second);
        $this->assertFalse($second['created']);
        $this->assertTrue($first['listing']->is($second['listing']));
        $this->assertSame(1, Listing::query()->where('source', 'arbeitsagentur.de')->count());
        $this->assertSame('DE', $first['listing']->country);
        $this->assertSame('job', $first['listing']->kind->value);
        $this->assertNotNull($first['listing']->apply_url);
        $this->assertNotNull($first['listing']->description);
    }

    public function test_registry_registers_arbeitsagentur_de_with_peer_boards(): void
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
        $this->assertInstanceOf(
            ArbeitsagenturDeIntakeAdapter::class,
            $registry->get('arbeitsagentur.de'),
        );
    }

    public function test_default_sites_v1_includes_arbeitsagentur_de(): void
    {
        $sites = config('joinera.sites.v1');

        $this->assertContains('ausbildung.de', $sites);
        $this->assertContains('azubiyo', $sites);
        $this->assertContains('arbeitsagentur.de', $sites);
    }

    public function test_intake_run_with_arbeitsagentur_de_creates_listings_via_fake_http(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['arbeitsagentur.de'],
            'joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1,
        ]);

        $this->artisan('intake:run')->assertSuccessful();

        $site = IntakeRunSite::query()->where('site', 'arbeitsagentur.de')->first();
        $this->assertNotNull($site);
        $this->assertSame(IntakeRunSiteStatus::Ok, $site->status);
        $this->assertGreaterThan(0, $site->listings_created);
        $this->assertSame(
            $site->listings_created,
            Listing::query()->where('source', 'arbeitsagentur.de')->count(),
        );
        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
        $this->assertGreaterThan(0, Listing::query()->where('source', 'arbeitsagentur.de')->where('kind', 'job')->count());
        $this->assertGreaterThan(0, Listing::query()->where('source', 'arbeitsagentur.de')->where('kind', 'ausbildung')->count());
    }

    public function test_intake_run_with_three_boards_creates_listings_from_each_source(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'azubiyo', 'arbeitsagentur.de'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
            'joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1,
        ]);

        $this->artisan('intake:run')->assertSuccessful();

        foreach (['ausbildung.de', 'azubiyo', 'arbeitsagentur.de'] as $source) {
            $site = IntakeRunSite::query()->where('site', $source)->first();
            $this->assertNotNull($site, $source);
            $this->assertSame(IntakeRunSiteStatus::Ok, $site->status, $source);
            $this->assertGreaterThan(0, Listing::query()->where('source', $source)->count(), $source);
        }
    }

    public function test_intake_run_one_board_throwing_still_completes_sibling_boards(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'azubiyo', 'arbeitsagentur.de'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
            'joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1,
        ]);

        $this->app->make(SiteIntakeAdapterRegistry::class)
            ->register('arbeitsagentur.de', new ThrowingSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $aaSite = IntakeRunSite::query()->where('site', 'arbeitsagentur.de')->first();
        $this->assertNotNull($aaSite);
        $this->assertSame(IntakeRunSiteStatus::Failed, $aaSite->status);
        $this->assertNotEmpty($aaSite->error);
        $this->assertSame(0, $aaSite->listings_created);

        $ausbildungSite = IntakeRunSite::query()->where('site', 'ausbildung.de')->first();
        $azubiyoSite = IntakeRunSite::query()->where('site', 'azubiyo')->first();

        $this->assertNotNull($ausbildungSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $ausbildungSite->status);
        $this->assertGreaterThan(0, $ausbildungSite->listings_created);

        $this->assertNotNull($azubiyoSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $azubiyoSite->status);
        $this->assertGreaterThan(0, $azubiyoSite->listings_created);

        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
        $this->assertSame(0, Listing::query()->where('source', 'arbeitsagentur.de')->count());
    }

    public function test_intake_run_loops_three_sites_when_middle_throws(): void
    {
        Event::fake();

        config([
            'joinera.sites.v1' => ['ausbildung.de', 'arbeitsagentur.de', 'azubiyo'],
            'joinera.sites.adapters.ausbildung_de.max_pages' => 1,
            'joinera.sites.adapters.azubiyo.max_pages' => 1,
            'joinera.sites.adapters.arbeitsagentur_de.max_pages' => 1,
        ]);

        $this->app->make(SiteIntakeAdapterRegistry::class)
            ->register('arbeitsagentur.de', new ThrowingSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $ausbildungSite = IntakeRunSite::query()->where('site', 'ausbildung.de')->first();
        $aaSite = IntakeRunSite::query()->where('site', 'arbeitsagentur.de')->first();
        $azubiyoSite = IntakeRunSite::query()->where('site', 'azubiyo')->first();

        $this->assertNotNull($ausbildungSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $ausbildungSite->status);
        $this->assertGreaterThan(0, $ausbildungSite->listings_created);

        $this->assertNotNull($aaSite);
        $this->assertSame(IntakeRunSiteStatus::Failed, $aaSite->status);
        $this->assertNotEmpty($aaSite->error);
        $this->assertSame(0, $aaSite->listings_created);

        $this->assertNotNull($azubiyoSite);
        $this->assertSame(IntakeRunSiteStatus::Ok, $azubiyoSite->status);
        $this->assertGreaterThan(0, $azubiyoSite->listings_created);

        $this->assertNotNull(IntakeRun::query()->first()?->finished_at);
        $this->assertGreaterThan(0, Listing::query()->where('source', 'ausbildung.de')->count());
        $this->assertSame(0, Listing::query()->where('source', 'arbeitsagentur.de')->count());
        $this->assertGreaterThan(0, Listing::query()->where('source', 'azubiyo')->count());
    }
}
