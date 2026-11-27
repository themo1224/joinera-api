<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\DocArtifact;
use App\Models\IntakeRun;
use App\Models\IntakeRunSite;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffOpsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_read_ops_report(): void
    {
        $this->getJson('/api/v1/ops/reports')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_user_role_cannot_read_ops_report(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_reads_ops_report_with_intake_pause_and_doc_cost(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $other = User::factory()->create();

        $run = IntakeRun::factory()->finished()->create([
            'started_at' => now()->subHours(3),
            'finished_at' => now()->subHours(2),
        ]);
        IntakeRunSite::factory()->ok()->create([
            'intake_run_id' => $run->id,
            'site' => 'azubiyo',
            'listings_created' => 12,
        ]);
        IntakeRunSite::factory()->failed()->create([
            'intake_run_id' => $run->id,
            'site' => 'ausbildung',
            'listings_created' => 0,
        ]);

        Application::factory()->for($candidate)->queued()->create(['site' => 'azubiyo']);
        Application::factory()->for($candidate)->applying()->create(['site' => 'azubiyo']);
        Application::factory()->for($candidate)->create([
            'status' => ApplicationStatus::Submitted,
            'site' => 'azubiyo',
        ]);

        $failing = Application::factory()->for($candidate)->applying()->create(['site' => 'azubiyo']);
        ApplyAttempt::factory()->create([
            'application_id' => $failing->id,
            'kind' => ApplyAttemptKind::HardFail,
        ]);
        ApplyAttempt::factory()->create([
            'application_id' => $failing->id,
            'kind' => ApplyAttemptKind::Retryable,
        ]);
        ApplyAttempt::factory()->create([
            'application_id' => $failing->id,
            'kind' => ApplyAttemptKind::Success,
        ]);
        ApplyAttempt::factory()->create([
            'application_id' => $failing->id,
            'kind' => ApplyAttemptKind::Challenge,
            'stop_reason' => 'captcha',
        ]);

        BlockEvent::factory()->create([
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'application_id' => $failing->id,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);

        SiteIdentity::factory()->for($candidate)->paused()->create(['site' => 'azubiyo']);
        SiteIdentity::factory()->for($other)->paused()->create(['site' => 'azubiyo']);
        SiteIdentity::factory()->for($candidate)->create(['site' => 'ausbildung', 'paused' => false]);

        $appForDocs = Application::factory()->for($candidate)->preparingDocs()->create();
        DocArtifact::factory()->cv()->withCost(100)->create(['application_id' => $appForDocs->id]);
        DocArtifact::factory()->cover()->withCost(250)->create(['application_id' => $appForDocs->id]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertOk()
            ->assertJsonPath('queue.queued', 1)
            ->assertJsonPath('queue.preparingDocs', 1)
            ->assertJsonPath('queue.applying', 2)
            ->assertJsonPath('queue.submitted', 1)
            ->assertJsonPath('queue.hardFailAttempts', 1)
            ->assertJsonPath('queue.retryableAttempts', 1)
            ->assertJsonPath('blocks.openPauseCount', 2)
            ->assertJsonPath('blocks.openBlockEventCount', 1)
            ->assertJsonPath('blocks.staffHelpCount', 1)
            ->assertJsonPath('applyOutcomes.successAttempts', 1)
            ->assertJsonPath('applyOutcomes.challengeAttempts', 1)
            ->assertJsonPath('applyOutcomes.successRate', 0.25)
            ->assertJsonPath('applyOutcomes.challengeRate', 0.25)
            ->assertJsonPath('docCostUnits', 350)
            ->assertJsonStructure([
                'generatedAt',
                'intake' => [['siteId', 'lastRunAt', 'newListingCount', 'errorCount']],
                'queue' => ['queued', 'preparingDocs', 'applying', 'submitted', 'hardFailAttempts', 'retryableAttempts'],
                'blocks' => ['openPauseCount', 'openBlockEventCount', 'staffHelpCount', 'pausesBySite', 'pausedApplicantIds', 'challengesByKind'],
                'applyOutcomes' => ['successAttempts', 'challengeAttempts', 'hardFailAttempts', 'retryableAttempts', 'successRate', 'challengeRate', 'staffHelpRate'],
                'funnel' => [['status', 'count']],
                'docCostUnits',
            ]);

        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('secret', $payload);
        $json = $response->getContent();
        $this->assertIsString($json);
        $this->assertStringNotContainsString('demo-site-secret', $json);
        $this->assertStringNotContainsString('password', $json);

        $intakeBySite = collect($payload['intake'])->keyBy('siteId');
        $this->assertSame(12, $intakeBySite['azubiyo']['newListingCount']);
        $this->assertSame(0, $intakeBySite['azubiyo']['errorCount']);
        $this->assertSame(0, $intakeBySite['ausbildung']['newListingCount']);
        $this->assertSame(1, $intakeBySite['ausbildung']['errorCount']);

        $pauseBySite = collect($payload['blocks']['pausesBySite'])->keyBy('siteId');
        $this->assertSame(2, $pauseBySite['azubiyo']['openPauseCount']);
        $this->assertEqualsCanonicalizing(
            [$candidate->id, $other->id],
            $payload['blocks']['pausedApplicantIds']
        );
    }

    public function test_admin_can_read_ops_report(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertOk()
            ->assertJsonPath('intake', [])
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.applying', 0)
            ->assertJsonPath('queue.submitted', 0)
            ->assertJsonPath('blocks.openPauseCount', 0)
            ->assertJsonPath('docCostUnits', null);
    }

    public function test_empty_state_has_empty_intake_and_zero_queue(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertOk()
            ->assertJsonPath('intake', [])
            ->assertJsonPath('queue.queued', 0)
            ->assertJsonPath('queue.applying', 0)
            ->assertJsonPath('queue.submitted', 0)
            ->assertJsonPath('queue.hardFailAttempts', 0)
            ->assertJsonPath('queue.retryableAttempts', 0)
            ->assertJsonPath('blocks.openPauseCount', 0)
            ->assertJsonPath('blocks.pausesBySite', [])
            ->assertJsonPath('blocks.pausedApplicantIds', [])
            ->assertJsonPath('docCostUnits', null);
    }

    public function test_doc_cost_sums_only_artifacts_with_cost(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $application = Application::factory()->for($candidate)->preparingDocs()->create();

        DocArtifact::factory()->cv()->withCost(40)->create(['application_id' => $application->id]);
        DocArtifact::factory()->cover()->create([
            'application_id' => $application->id,
            'cost_minor_units' => null,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertOk()
            ->assertJsonPath('docCostUnits', 40);
    }

    public function test_intake_uses_latest_run_per_site(): void
    {
        $staff = User::factory()->staff()->create();

        $older = IntakeRun::factory()->finished()->create([
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addHour(),
        ]);
        $newer = IntakeRun::factory()->finished()->create([
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
        ]);

        IntakeRunSite::factory()->ok()->create([
            'intake_run_id' => $older->id,
            'site' => 'azubiyo',
            'listings_created' => 99,
        ]);
        IntakeRunSite::factory()->ok()->create([
            'intake_run_id' => $newer->id,
            'site' => 'azubiyo',
            'listings_created' => 7,
        ]);
        IntakeRunSite::factory()->failed()->create([
            'intake_run_id' => $newer->id,
            'site' => 'indeed',
            'listings_created' => 0,
        ]);

        $payload = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/reports')
            ->assertOk()
            ->json();

        $intakeBySite = collect($payload['intake'])->keyBy('siteId');
        $this->assertCount(2, $payload['intake']);
        $this->assertSame(7, $intakeBySite['azubiyo']['newListingCount']);
        $this->assertSame(0, $intakeBySite['azubiyo']['errorCount']);
        $this->assertSame(0, $intakeBySite['indeed']['newListingCount']);
        $this->assertSame(1, $intakeBySite['indeed']['errorCount']);
    }
}
