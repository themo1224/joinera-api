<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\Azubiyo\AzubiyoIntakeAdapter;
use App\Services\Azubiyo\FakeAzubiyoHttpClient;
use App\Services\SiteIntakeAdapterRegistry;
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

    /**
     * Detail: JobPosting JSON-LD + a[href*="bewerben"]; external_id stays list trailing token.
     */
    public function test_parse_detail_from_fixture_returns_upsert_payload(): void
    {
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

    public function test_registry_does_not_auto_register_azubiyo_until_plan_3d(): void
    {
        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);

        $this->assertInstanceOf(
            AusbildungDeIntakeAdapter::class,
            $registry->get('ausbildung.de'),
        );
        $this->assertNull($registry->get('azubiyo'));
    }

    public function test_registry_can_register_azubiyo_adapter_in_test(): void
    {
        $fake = new FakeAzubiyoHttpClient;
        $adapter = new AzubiyoIntakeAdapter($fake);
        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);

        $this->assertNull($registry->get('azubiyo'));

        $registry->register('azubiyo', $adapter);

        $this->assertSame($adapter, $registry->get('azubiyo'));
    }
}
