<?php

namespace Tests\Feature;

use App\Services\AusbildungDe\AusbildungDeIntakeAdapter;
use App\Services\Azubiyo\AzubiyoIntakeAdapter;
use App\Services\Azubiyo\FakeAzubiyoHttpClient;
use App\Services\SiteIntakeAdapterRegistry;
use Tests\TestCase;

class AzubiyoIntakeAdapterTest extends TestCase
{
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
        $this->assertSame([], $adapter->parseDetail([]));
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
