<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Enums\DocGenerationRule;
use App\Jobs\PrepareApplicationDocsJob;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-13 Staff/Admin ops HTTP for docs (download + force generate).
 * Candidates have no panel: Candidate callers get 403, not a download route.
 *
 * @see docs/api/IMPLEMENTATION_PHASES.md P0-13
 */
class StaffApplicationDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'joinera.docs.disk' => 'local',
            'joinera.docs.regenerate_max_per_hour' => 5,
            'joinera.queues.docs' => 'docs',
            'joinera.ai.driver' => 'fake',
        ]);
    }

    public function test_staff_can_download_cv_and_cover_pdfs(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplicationWithArtifacts();

        foreach ([DocArtifactKind::Cv, DocArtifactKind::Cover] as $kind) {
            $response = $this->actingAs($staff, 'sanctum')
                ->get('/api/v1/applications/'.$application->id.'/docs/'.$kind->value);

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->streamedContent());

            $payload = strtolower($response->streamedContent().json_encode($response->headers->all()));
            $this->assertStringNotContainsString('price', $payload);
            $this->assertStringNotContainsString('payment', $payload);
            $this->assertStringNotContainsString('"free"', $payload);
            $this->assertStringNotContainsString('llm_prompt_tokens', $payload);
        }
    }

    public function test_candidate_cannot_download_docs(): void
    {
        $candidate = User::factory()->create();
        $application = $this->makeApplicationWithArtifacts(owner: $candidate);

        $this->actingAs($candidate, 'sanctum')
            ->get('/api/v1/applications/'.$application->id.'/docs/cover')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_download_missing_kind_or_application_is_404(): void
    {
        $staff = User::factory()->staff()->create();
        $application = $this->makeApplicationWithArtifacts();

        $this->actingAs($staff, 'sanctum')
            ->get('/api/v1/applications/'.$application->id.'/docs/unknown')
            ->assertNotFound();

        $this->actingAs($staff, 'sanctum')
            ->get('/api/v1/applications/999999/docs/cover')
            ->assertNotFound();

        DocArtifact::query()
            ->where('application_id', $application->id)
            ->where('kind', DocArtifactKind::Cover)
            ->delete();

        $this->actingAs($staff, 'sanctum')
            ->get('/api/v1/applications/'.$application->id.'/docs/cover')
            ->assertNotFound();
    }

    public function test_generate_while_queued_preparing_or_applying_dispatches_force_job(): void
    {
        Queue::fake([PrepareApplicationDocsJob::class]);
        $staff = User::factory()->staff()->create();

        foreach ([
            ApplicationStatus::Queued,
            ApplicationStatus::PreparingDocs,
            ApplicationStatus::Applying,
        ] as $status) {
            $application = $status === ApplicationStatus::Queued
                ? Application::factory()->queued()->create()
                : Application::factory()->create(['status' => $status]);

            $this->actingAs($staff, 'sanctum')
                ->postJson('/api/v1/applications/'.$application->id.'/docs/generate')
                ->assertStatus(202)
                ->assertJsonPath('success', true)
                ->assertJsonPath('applicationId', $application->id)
                ->assertJsonMissing(['price' => true, 'payment' => true, 'free' => true]);

            Queue::assertPushed(PrepareApplicationDocsJob::class, function (PrepareApplicationDocsJob $job) use ($application): bool {
                return $job->applicationId === $application->id && $job->force === true;
            });
        }
    }

    public function test_generate_for_submitted_returns_409(): void
    {
        Queue::fake([PrepareApplicationDocsJob::class]);

        $staff = User::factory()->staff()->create();
        $application = Application::factory()->create([
            'status' => ApplicationStatus::Submitted,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/docs/generate')
            ->assertStatus(409)
            ->assertJsonPath('code', 'docs_generate_not_allowed');

        Queue::assertNothingPushed();
    }

    public function test_generate_when_rate_limited_returns_409(): void
    {
        Queue::fake([PrepareApplicationDocsJob::class]);

        $staff = User::factory()->staff()->create();
        $application = Application::factory()->applying()->create();

        config(['joinera.docs.regenerate_max_per_hour' => 1]);
        Cache::put('docs:prepare:'.$application->id, 1, now()->addHour());

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/docs/generate')
            ->assertStatus(409)
            ->assertJsonPath('code', 'docs_rate_limited');

        Queue::assertNothingPushed();
    }

    public function test_docs_approve_route_remains_404(): void
    {
        $staff = User::factory()->staff()->create();
        $application = Application::factory()->applying()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/docs/approve')
            ->assertNotFound();
    }

    public function test_guest_cannot_download_or_generate(): void
    {
        $application = $this->makeApplicationWithArtifacts();

        $this->getJson('/api/v1/applications/'.$application->id.'/docs/cover')
            ->assertUnauthorized();

        $this->postJson('/api/v1/applications/'.$application->id.'/docs/generate')
            ->assertUnauthorized();
    }

    public function test_admin_can_generate_docs(): void
    {
        Queue::fake([PrepareApplicationDocsJob::class]);
        $admin = User::factory()->admin()->create();
        $application = Application::factory()->preparingDocs()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applications/'.$application->id.'/docs/generate')
            ->assertStatus(202);

        Queue::assertPushed(PrepareApplicationDocsJob::class);
    }

    private function makeApplicationWithArtifacts(?User $owner = null): Application
    {
        $user = $owner ?? User::factory()->create();
        $listing = Listing::factory()->create();

        $application = Application::factory()
            ->for($user)
            ->for($listing)
            ->applying()
            ->create([
                'site' => (string) $listing->source,
            ]);

        foreach ([DocArtifactKind::Cv, DocArtifactKind::Cover] as $kind) {
            $path = sprintf('docs/%d/%s.pdf', $application->id, $kind->value);
            Storage::disk('local')->put($path, "%PDF-1.4\n%".$kind->value."\n%%EOF\n");

            DocArtifact::factory()->create([
                'application_id' => $application->id,
                'kind' => $kind,
                'rule' => DocGenerationRule::CoverOnly,
                'cache_hit' => $kind === DocArtifactKind::Cv,
                'disk' => 'local',
                'path' => $path,
                'mime' => 'application/pdf',
                'size' => 24,
                'llm_model' => 'fake/llm',
                'llm_prompt_tokens' => 1,
                'llm_completion_tokens' => 1,
                'llm_total_tokens' => 2,
            ]);
        }

        return $application;
    }
}
