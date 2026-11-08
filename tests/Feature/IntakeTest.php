<?php

namespace Tests\Feature;

use App\Enums\ListingKind;
use App\Events\ListingCreated;
use App\Jobs\RecordIntakeSiteRepeatedFailureAlert;
use App\Jobs\RunSiteIntakeJob;
use App\Models\AdminAlert;
use App\Models\IntakeRun;
use App\Models\Listing;
use App\Models\User;
use App\Services\FixtureSiteIntakeAdapter;
use App\Services\SiteIntakeAdapterRegistry;
use App\Services\ThrowingSiteIntakeAdapter;
use App\Services\UpsertListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_crawl_inserts_job_and_ausbildung_and_emits_listing_created_twice(): void
    {
        Event::fake([ListingCreated::class]);

        $this->registerFixtureSite('fixture_a');
        config(['joinera.sites.v1' => ['fixture_a']]);

        $this->artisan('intake:run')->assertSuccessful();

        $this->assertSame(1, Listing::query()->where('kind', ListingKind::Job)->count());
        $this->assertSame(1, Listing::query()->where('kind', ListingKind::Ausbildung)->count());
        Event::assertDispatchedTimes(ListingCreated::class, 2);
    }

    public function test_same_external_id_on_second_run_updates_last_seen_at_only(): void
    {
        Event::fake([ListingCreated::class]);

        $this->registerFixtureSite('fixture_a');
        config(['joinera.sites.v1' => ['fixture_a']]);

        $this->artisan('intake:run')->assertSuccessful();

        $listing = Listing::query()->where('external_id', 'job-1')->first();
        $this->assertNotNull($listing);
        $firstSeen = $listing->first_seen_at->copy();
        $lastSeen = $listing->last_seen_at->copy();

        $this->travel(5)->minutes();

        Event::fake([ListingCreated::class]);
        $this->artisan('intake:run')->assertSuccessful();

        $listing->refresh();
        $this->assertTrue($listing->first_seen_at->equalTo($firstSeen));
        $this->assertTrue($listing->last_seen_at->greaterThan($lastSeen));
        $this->assertSame(1, Listing::query()->where('external_id', 'job-1')->count());
        Event::assertNotDispatched(ListingCreated::class);
    }

    public function test_curated_row_without_kind_reports_error_and_keeps_sibling(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/listings/import', [
                'rows' => [
                    [
                        'source' => 'curated',
                        'external_id' => 'c-1',
                        'kind' => 'job',
                        'title' => 'Nurse',
                        'company' => 'Klinik',
                        'city' => 'Hamburg',
                        'url' => 'https://example.test/c-1',
                        'country' => 'DE',
                    ],
                    [
                        'source' => 'curated',
                        'external_id' => 'c-2',
                        'title' => 'Missing Kind',
                        'company' => 'Klinik',
                        'city' => 'Hamburg',
                        'url' => 'https://example.test/c-2',
                        'country' => 'DE',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame('kind_required', $response->json('errors.0.code'));
        $this->assertSame(1, Listing::query()->count());
        $this->assertDatabaseHas('listings', ['external_id' => 'c-1']);
        $this->assertDatabaseMissing('listings', ['external_id' => 'c-2']);
    }

    public function test_non_de_fixture_row_is_absent_when_allow_non_de_false(): void
    {
        config([
            'joinera.sites.v1' => ['fixture_a'],
            'joinera.sites.allow_non_de' => false,
        ]);

        $adapter = (new FixtureSiteIntakeAdapter)->includeNonDe();
        $this->app->make(SiteIntakeAdapterRegistry::class)->register('fixture_a', $adapter);

        $this->artisan('intake:run')->assertSuccessful();

        $this->assertSame(2, Listing::query()->count());
        $this->assertDatabaseMissing('listings', ['external_id' => 'job-at-1']);
    }

    public function test_user_post_listings_import_is_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/listings/import', [
                'rows' => [
                    [
                        'source' => 'curated',
                        'kind' => 'job',
                        'title' => 'X',
                        'company' => 'Y',
                        'city' => 'Z',
                        'url' => 'https://example.test/x',
                    ],
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseCount('listings', 0);
    }

    public function test_site_a_throwing_still_persists_listing_from_site_b(): void
    {
        config(['joinera.sites.v1' => ['site_a', 'site_b']]);

        $registry = $this->app->make(SiteIntakeAdapterRegistry::class);
        $registry->register('site_a', new ThrowingSiteIntakeAdapter);
        $registry->register('site_b', new FixtureSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $this->assertGreaterThanOrEqual(1, Listing::query()->where('source', 'site_b')->count());
        $this->assertSame(0, Listing::query()->where('source', 'site_a')->count());
    }

    public function test_intake_payload_with_user_session_id_is_rejected_before_insert(): void
    {
        try {
            $this->app->make(UpsertListing::class)->handle('fixture_a', [
                'external_id' => 'bad-1',
                'kind' => 'job',
                'title' => 'Bad',
                'company' => 'Co',
                'city' => 'Berlin',
                'url' => 'https://example.test/bad',
                'country' => 'DE',
                'user_id' => 99,
                'session_id' => 'sess-1',
            ]);
            $this->fail('Expected IntakeIdentityContextException was not thrown.');
        } catch (\App\Exceptions\IntakeIdentityContextException) {
            // expected
        }

        $this->assertDatabaseCount('listings', 0);
    }

    public function test_empty_sites_v1_completes_with_run_row_and_zero_listings(): void
    {
        config(['joinera.sites.v1' => []]);

        $this->artisan('intake:run')->assertSuccessful();

        $run = IntakeRun::query()->first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->sites()->count());
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_intake_jobs_are_dispatched_on_intake_queue(): void
    {
        Queue::fake();

        $this->registerFixtureSite('fixture_a');
        config(['joinera.sites.v1' => ['fixture_a']]);

        $this->artisan('intake:run')->assertSuccessful();

        Queue::assertPushedOn('intake', RunSiteIntakeJob::class);
    }

    public function test_repeated_site_failure_writes_admin_alert_on_alerts_queue(): void
    {
        Queue::fake([RecordIntakeSiteRepeatedFailureAlert::class]);

        config(['joinera.sites.v1' => ['site_a']]);
        $this->app->make(SiteIntakeAdapterRegistry::class)
            ->register('site_a', new ThrowingSiteIntakeAdapter);

        $this->artisan('intake:run')->assertSuccessful();

        $firstRun = IntakeRun::query()->first();
        $this->assertNotNull($firstRun);
        $this->assertNotNull($firstRun->fresh()->finished_at);

        Queue::fake([RecordIntakeSiteRepeatedFailureAlert::class]);
        $this->artisan('intake:run')->assertSuccessful();

        Queue::assertPushedOn('alerts', RecordIntakeSiteRepeatedFailureAlert::class);

        (new RecordIntakeSiteRepeatedFailureAlert('site_a', (int) IntakeRun::query()->max('id')))->handle();

        $this->assertDatabaseHas('admin_alerts', [
            'kind' => AdminAlert::KIND_INTAKE_SITE_REPEATED_FAILURE,
            'site' => 'site_a',
        ]);
    }

    public function test_import_rejects_identity_context_without_rolling_back_siblings(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/listings/import', [
                'rows' => [
                    [
                        'source' => 'curated',
                        'external_id' => 'ok-1',
                        'kind' => 'job',
                        'title' => 'Ok',
                        'company' => 'Co',
                        'city' => 'Berlin',
                        'url' => 'https://example.test/ok',
                        'country' => 'DE',
                    ],
                    [
                        'source' => 'curated',
                        'external_id' => 'bad-1',
                        'kind' => 'job',
                        'title' => 'Bad',
                        'company' => 'Co',
                        'city' => 'Berlin',
                        'url' => 'https://example.test/bad',
                        'country' => 'DE',
                        'site_identity_id' => 1,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('imported'));
        $this->assertSame('intake_identity_context', $response->json('errors.0.code'));
        $this->assertSame(1, Listing::query()->count());
    }

    private function registerFixtureSite(string $site): void
    {
        $this->app->make(SiteIntakeAdapterRegistry::class)
            ->register($site, new FixtureSiteIntakeAdapter);
    }
}
