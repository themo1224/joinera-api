<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\AusbildungDe\FakeAusbildungDeHttpClient;
use App\Services\SiteIntakeAdapterRegistry;
use App\Services\UpsertListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AusbildungDeIntakeAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_http_client_returns_search_fixture_bytes(): void
    {
        $fake = new FakeAusbildungDeHttpClient;

        $body = $fake->get('https://www.ausbildung.de/suche/');

        $this->assertStringContainsString('data-joinera-fixture="search"', $body);
    }

    public function test_fake_http_client_returns_detail_fixture_bytes(): void
    {
        $fake = new FakeAusbildungDeHttpClient;

        $body = $fake->get('https://www.ausbildung.de/stelle/beispiel-1/');

        $this->assertStringContainsString('data-joinera-fixture="detail"', $body);
    }

    public function test_adapter_is_instantiable_and_search_uses_fake_fixture(): void
    {
        $fake = new FakeAusbildungDeHttpClient;
        $adapter = new AusbildungDeIntakeAdapter($fake);

        $result = $adapter->search();

        $this->assertArrayHasKey('body', $result);
        $this->assertStringContainsString('data-joinera-fixture="search"', $result['body']);
    }

    public function test_registry_can_register_ausbildung_de_adapter(): void
    {
        $fake = new FakeAusbildungDeHttpClient;
        $adapter = new AusbildungDeIntakeAdapter($fake);
        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);

        $registry->register('ausbildung.de', $adapter);

        $this->assertSame($adapter, $registry->get('ausbildung.de'));
    }

    /**
     * Selectors: [data-testid="jp-card"], a[href*="/stellen/"], jp-title/jp-customer/jp-branches.
     * external_id = trailing UUID on /stellen/{slug}-{uuid}/.
     */
    public function test_parse_list_from_fixture_yields_stable_external_ids_and_absolute_urls(): void
    {
        $fake = new FakeAusbildungDeHttpClient;
        $adapter = new AusbildungDeIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());

        // Fixture has 3 valid jp-cards + 1 broken card without UUID — broken is skipped.
        $this->assertCount(3, $items);
        $this->assertGreaterThanOrEqual(1, count($items));

        $externalIds = [];

        foreach ($items as $item) {
            $this->assertNotSame('', $item['external_id'] ?? '');
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $item['external_id'],
            );
            $this->assertStringStartsWith('https://', $item['url'] ?? '');
            $this->assertStringContainsString('/stellen/', $item['url']);
            $this->assertSame('ausbildung', $item['kind'] ?? null);
            $this->assertNotSame('', $item['title'] ?? '');
            $this->assertNotSame('', $item['company'] ?? '');
            $this->assertNotSame('', $item['city'] ?? '');
            $this->assertStringNotContainsString('bei ', (string) $item['company']);

            $externalIds[] = $item['external_id'];
        }

        $this->assertSame($externalIds, array_unique($externalIds));
        $this->assertContains('fb079a87-e145-457c-9bda-8f471d8541d6', $externalIds);
        $this->assertSame('08209 Auerbach/Vogtl.', $items[0]['city']);
    }

    /**
     * Detail: JobPosting JSON-LD + a[data-testid="application-button"]; external_id stays list UUID.
     */
    public function test_parse_detail_from_fixture_returns_upsert_payload(): void
    {
        $fake = new FakeAusbildungDeHttpClient;
        $adapter = new AusbildungDeIntakeAdapter($fake);

        $items = $adapter->parseList($adapter->search());
        $listItem = $items[0];
        $detail = $adapter->parseDetail($listItem);

        $this->assertSame($listItem['external_id'], $detail['external_id']);
        $this->assertNotSame('f07bdefe-0699-4f30-b89b-16757541fdba', $detail['external_id']);
        $this->assertSame('ausbildung', $detail['kind']);
        $this->assertNotSame('', $detail['title'] ?? '');
        $this->assertNotSame('', $detail['company'] ?? '');
        $this->assertNotSame('', $detail['city'] ?? '');
        $this->assertSame($listItem['url'], $detail['url']);
        $this->assertStringStartsWith('https://', $detail['apply_url'] ?? '');
        $this->assertNotSame($detail['url'], $detail['apply_url']);
        $this->assertNotSame('', $detail['description'] ?? '');
        $this->assertNull($detail['language_requirement']);
        $this->assertSame('Mittlere Reife', $detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('2026-11-09T12:04:16.857Z', $detail['posted_at']);
        $this->assertNull($detail['category']);
        $this->assertArrayNotHasKey('user_id', $detail);
        $this->assertArrayNotHasKey('site_identity_id', $detail);
    }

    public function test_parse_detail_education_requirement_null_when_absent(): void
    {
        $noEduUrl = 'https://www.ausbildung.de/stellen/ausbildung-kaufmann-frau-im-einzelhandel-m-w-d-bei-norma-lebensmittelfilialbetrieb-stiftung-co-kg-in-treuen-f74973f2-b9ad-46be-896e-d316ea313d77/';
        $fake = (new FakeAusbildungDeHttpClient)->withPathOverrides([
            'f74973f2-b9ad-46be-896e-d316ea313d77' => 'detail_without_education.html',
        ]);
        $adapter = new AusbildungDeIntakeAdapter($fake);

        $detail = $adapter->parseDetail([
            'external_id' => 'f74973f2-b9ad-46be-896e-d316ea313d77',
            'url' => $noEduUrl,
            'title' => 'Ausbildung Kaufmann/-frau im Einzelhandel (m/w/d)',
            'company' => 'Norma Lebensmittelfilialbetrieb Stiftung & Co. KG',
            'city' => '08541 Treuen',
            'kind' => 'ausbildung',
        ]);

        $this->assertNull($detail['education_requirement']);
        $this->assertSame('DE', $detail['country']);
        $this->assertSame('f74973f2-b9ad-46be-896e-d316ea313d77', $detail['external_id']);
    }

    public function test_parse_detail_then_upsert_creates_once_and_dedupes_by_external_id(): void
    {
        Event::fake();

        $fake = new FakeAusbildungDeHttpClient;
        $adapter = new AusbildungDeIntakeAdapter($fake);
        $upsert = $this->app->make(UpsertListing::class);

        $items = $adapter->parseList($adapter->search());
        $detail = $adapter->parseDetail($items[0]);

        $first = $upsert->handle('ausbildung.de', $detail);
        $this->assertNotNull($first);
        $this->assertTrue($first['created']);

        $second = $upsert->handle('ausbildung.de', $detail);
        $this->assertNotNull($second);
        $this->assertFalse($second['created']);
        $this->assertTrue($first['listing']->is($second['listing']));
        $this->assertSame(1, Listing::query()->where('source', 'ausbildung.de')->count());
        $this->assertSame('Mittlere Reife', $first['listing']->education_requirement);
        $this->assertSame('DE', $first['listing']->country);
        $this->assertNotNull($first['listing']->apply_url);
        $this->assertNotNull($first['listing']->description);
        $this->assertNotNull($first['listing']->posted_at);
    }
}
