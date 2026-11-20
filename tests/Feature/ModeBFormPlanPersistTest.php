<?php

namespace Tests\Feature;

use App\Contracts\ModeBBrowserPort;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\User;
use App\Services\Apply\ModeBBrowserResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 10 — form plan from Mode B worker is stored on the ApplyAttempt.
 */
class ModeBFormPlanPersistTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_fail_persists_form_plan_from_worker_result(): void
    {
        config(['joinera.queues.apply' => 'apply']);

        $formPlan = [
            'version' => 1,
            'ready' => true,
            'page' => ['url' => 'https://karriere.firma.example.test/apply', 'title' => 'Apply'],
            'answers' => [
                [
                    'field_id' => 'vorname',
                    'label' => 'Vorname',
                    'status' => 'mapped',
                    'source' => 'candidate.name.first',
                    'value' => 'Ada',
                ],
            ],
            'missing' => [],
        ];

        $this->app->instance(ModeBBrowserPort::class, new class($formPlan) implements ModeBBrowserPort
        {
            /** @param  array<string, mixed>  $formPlan */
            public function __construct(private array $formPlan) {}

            public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
            {
                return ModeBBrowserResult::identityMismatch('form_plan_ready', $this->formPlan);
            }
        });

        $application = $this->makeReadyApplication();

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::HardFail, $attempt->kind);
        $this->assertSame('form_plan_ready', $attempt->stop_reason);
        $this->assertIsArray($attempt->form_plan);
        $this->assertTrue($attempt->form_plan['ready'] ?? false);
        $this->assertSame('Ada', $attempt->form_plan['answers'][0]['value'] ?? null);
    }

    public function test_success_persists_form_plan_and_confirmation(): void
    {
        config(['joinera.queues.apply' => 'apply']);

        $formPlan = [
            'version' => 1,
            'ready' => true,
            'fill' => [
                'typed' => ['vorname', 'email'],
                'chosen' => ['stadt'],
                'attached' => ['lebenslauf'],
            ],
            'answers' => [
                [
                    'field_id' => 'vorname',
                    'label' => 'Vorname',
                    'status' => 'mapped',
                    'value' => 'Ada',
                ],
            ],
            'missing' => [],
        ];

        $this->app->instance(ModeBBrowserPort::class, new class($formPlan) implements ModeBBrowserPort
        {
            /** @param  array<string, mixed>  $formPlan */
            public function __construct(private array $formPlan) {}

            public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
            {
                return ModeBBrowserResult::success('company-mock-1', $this->formPlan);
            }
        });

        $application = $this->makeReadyApplication();

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Success, $attempt->kind);
        $this->assertSame('company-mock-1', $attempt->confirmation_reference);
        $this->assertNull($attempt->stop_reason);
        $this->assertIsArray($attempt->form_plan);
        $this->assertSame('Ada', $attempt->form_plan['answers'][0]['value'] ?? null);
        $this->assertContains('vorname', $attempt->form_plan['fill']['typed'] ?? []);
    }

    public function test_missing_info_also_persists_form_plan(): void
    {
        config(['joinera.queues.apply' => 'apply']);

        $formPlan = [
            'version' => 1,
            'ready' => false,
            'missing' => [
                ['field_id' => 'telefon', 'label' => 'Telefon', 'reason' => 'no_candidate_mapping'],
            ],
            'answers' => [],
        ];

        $this->app->instance(ModeBBrowserPort::class, new class($formPlan) implements ModeBBrowserPort
        {
            /** @param  array<string, mixed>  $formPlan */
            public function __construct(private array $formPlan) {}

            public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
            {
                return ModeBBrowserResult::identityMismatch('missing_info:Telefon', $this->formPlan);
            }
        });

        $application = $this->makeReadyApplication();

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame('missing_info:Telefon', $attempt->stop_reason);
        $this->assertFalse($attempt->form_plan['ready'] ?? true);
        $this->assertSame('telefon', $attempt->form_plan['missing'][0]['field_id'] ?? null);
    }

    private function makeReadyApplication(): Application
    {
        $approver = User::factory()->staff()->create();
        $applicant = User::factory()->create();

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->approval = ApprovalStatus::Approved;
        $profile->approved_by = $approver->id;
        $profile->approved_at = now();
        $profile->save();

        MailboxIdentity::factory()->ready()->for($applicant)->create();

        $listing = Listing::factory()->forSource('azubiyo')->create([
            'url' => 'https://www.azubiyo.de/stellen/form-plan',
            'apply_url' => 'https://karriere.firma.example.test/form-plan/apply',
        ]);

        $application = Application::factory()
            ->for($applicant)
            ->for($listing)
            ->create([
                'site' => $listing->source,
                'status' => ApplicationStatus::Applying,
                'docs_failure_code' => null,
            ]);

        DocArtifact::factory()->cv()->create(['application_id' => $application->id]);
        DocArtifact::factory()->cover()->create(['application_id' => $application->id]);

        return $application;
    }
}
