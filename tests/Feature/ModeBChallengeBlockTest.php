<?php

namespace Tests\Feature;

use App\Contracts\ModeBBrowserPort;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\SiteIdentityState;
use App\Jobs\RecordApplyChallengeAlert;
use App\Jobs\SubmitApplicationJob;
use App\Models\AdminAlert;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use App\Services\Apply\ModeBBrowserResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Plan 4 / 25 — challenge creates open BlockEvent, pauses site identity when present, queues Staff alert.
 */
class ModeBChallengeBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.queues.apply' => 'apply',
            'joinera.queues.alerts' => 'alerts',
        ]);
    }

    public function test_challenge_pauses_site_writes_block_event_and_alert(): void
    {
        Queue::fake([RecordApplyChallengeAlert::class]);

        $this->app->instance(ModeBBrowserPort::class, new class implements ModeBBrowserPort
        {
            public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
            {
                return ModeBBrowserResult::challenge('captcha');
            }
        });

        $application = $this->makeReadyApplication();
        $identity = SiteIdentity::query()
            ->where('user_id', $application->user_id)
            ->where('site', $application->site)
            ->firstOrFail();
        $this->assertFalse($identity->paused);

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Challenge, $attempt->kind);

        $this->assertSame(1, BlockEvent::query()->where('application_id', $application->id)->count());
        $block = BlockEvent::query()->where('application_id', $application->id)->first();
        $this->assertSame(BlockEvent::KIND_CAPTCHA, $block->observed_kind);
        $this->assertNull($block->resolved_at);
        $this->assertTrue($block->isOpen());
        $this->assertSame($application->user_id, $block->user_id);
        $this->assertSame($application->site, $block->site);

        $identity->refresh();
        $this->assertTrue($identity->paused);

        Queue::assertPushedOn('alerts', RecordApplyChallengeAlert::class);
        Queue::assertPushed(RecordApplyChallengeAlert::class, function (RecordApplyChallengeAlert $job) use ($application, $block): bool {
            return $job->userId === $application->user_id
                && $job->site === $application->site
                && $job->applicationId === $application->id
                && $job->observedKind === BlockEvent::KIND_CAPTCHA
                && $job->blockEventId === $block->id;
        });

        (new RecordApplyChallengeAlert(
            (int) $application->user_id,
            (string) $application->site,
            (int) $application->id,
            BlockEvent::KIND_CAPTCHA,
            $block->id,
        ))->handle();

        $this->assertSame(1, AdminAlert::query()->where('kind', AdminAlert::KIND_APPLY_CHALLENGE)->count());
        $alert = AdminAlert::query()->where('kind', AdminAlert::KIND_APPLY_CHALLENGE)->first();
        $this->assertTrue($alert->payload['needs_help'] ?? false);
        $this->assertStringContainsString('Needs help', (string) ($alert->payload['message'] ?? ''));
        $this->assertSame(
            'https://karriere.firma.example.test/challenge/apply',
            $alert->payload['company_apply_url'] ?? null,
        );
    }

    public function test_challenge_without_site_identity_still_opens_block_and_persists_form_plan(): void
    {
        Queue::fake([RecordApplyChallengeAlert::class]);

        $formPlan = [
            'version' => 1,
            'block' => ['kind' => 'cookie_wall', 'page_url' => 'https://karriere.firma.example.test/cookies', 'paused' => true],
            'ready' => false,
        ];

        $this->app->instance(ModeBBrowserPort::class, new class($formPlan) implements ModeBBrowserPort
        {
            /** @param  array<string, mixed>  $formPlan */
            public function __construct(private readonly array $formPlan) {}

            public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
            {
                return ModeBBrowserResult::challenge('cookie_wall', $this->formPlan);
            }
        });

        $application = $this->makeReadyApplication(withSiteIdentity: false);

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Challenge, $attempt->kind);
        $this->assertSame(BlockEvent::KIND_COOKIE_WALL, $attempt->stop_reason);
        $this->assertIsArray($attempt->form_plan);
        $this->assertTrue($attempt->form_plan['block']['paused'] ?? false);

        $block = BlockEvent::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($block);
        $this->assertSame(BlockEvent::KIND_COOKIE_WALL, $block->observed_kind);
        $this->assertNull($block->resolved_at);

        $this->assertSame(0, SiteIdentity::query()->where('user_id', $application->user_id)->count());
        Queue::assertPushed(RecordApplyChallengeAlert::class);
    }

    private function makeReadyApplication(bool $withSiteIdentity = true): Application
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
            'url' => 'https://www.azubiyo.de/stellen/challenge',
            'apply_url' => 'https://karriere.firma.example.test/challenge/apply',
        ]);

        if ($withSiteIdentity) {
            SiteIdentity::factory()
                ->for($applicant)
                ->healthy()
                ->create([
                    'site' => $listing->source,
                    'paused' => false,
                    'state' => SiteIdentityState::Healthy,
                ]);
        }

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
