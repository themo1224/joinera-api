<?php

namespace Tests\Feature;

use App\Contracts\ModeBBrowserPort;
use App\Enums\ApplicationStatus;
use App\Enums\ApplyAttemptKind;
use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\MatchStatus;
use App\Enums\SiteIdentityState;
use App\Jobs\PrepareApplicationDocsJob;
use App\Jobs\SubmitApplicationJob;
use App\Models\Application;
use App\Models\ApplyAttempt;
use App\Models\CandidateListingMatch;
use App\Models\CvAsset;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use App\Services\Apply\HttpModeBBrowserAdapter;
use App\Services\Docs\PrepareApplicationDocuments;
use App\Services\QueueSelectedCandidateListingMatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Love-path proof: proposed → Queue selected → docs → Mode B HTTP for arbeitsagentur.de.
 * Playwright mock board smoke stays in browser-worker (`npm run test:arbeitsagentur-mock`).
 */
class ArbeitsagenturDeLovePathPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'joinera.docs.disk' => 'local',
            'joinera.docs.regenerate_max_per_hour' => 5,
            'joinera.queues.apply' => 'apply',
            'joinera.queues.docs' => 'docs',
            'joinera.mode_b.allow_board_apply' => true,
            'joinera.ai.driver' => 'fake',
            'joinera.mode_b.driver' => 'http',
            'joinera.mode_b.worker_url' => 'http://127.0.0.1:3911',
            'joinera.mode_b.worker_secret' => 'dev-shared-secret',
            'joinera.apply.cap' => 10,
            'joinera.sites.v1' => ['azubiyo', 'ausbildung.de', 'arbeitsagentur.de'],
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
        ]);
    }

    public function test_queue_selected_docs_then_http_mode_b_reaches_submitted(): void
    {
        Queue::fake([PrepareApplicationDocsJob::class, SubmitApplicationJob::class]);

        [$staff, $applicant, $match] = $this->makeProposedArbeitsagenturMatch();

        $result = app(QueueSelectedCandidateListingMatches::class)->handle(
            $applicant,
            [$match->id],
            $staff,
        );

        $this->assertCount(1, $result['applications']);
        $application = $result['applications'][0];
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertSame('arbeitsagentur.de', $application->site);
        $this->assertSame(MatchStatus::Queued, $match->fresh()->status);

        Queue::assertPushed(PrepareApplicationDocsJob::class);

        app(PrepareApplicationDocuments::class)->handle($application->id);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Applying, $application->status);
        $this->assertNull($application->docs_failure_code);
        $this->assertTrue($application->docArtifacts()->where('kind', 'cv')->exists());
        $this->assertTrue($application->docArtifacts()->where('kind', 'cover')->exists());

        Http::fake([
            'http://127.0.0.1:3911/submit' => Http::response([
                'outcome' => 'success',
                'confirmation_reference' => 'ba-love-path-mock-'.$application->id,
            ], 200),
        ]);

        $this->app->instance(ModeBBrowserPort::class, $this->app->make(HttpModeBBrowserAdapter::class));

        $this->app->call([new SubmitApplicationJob($application->id), 'handle']);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);

        $attempt = ApplyAttempt::query()->where('application_id', $application->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame(ApplyAttemptKind::Success, $attempt->kind);
        $this->assertSame('ba-love-path-mock-'.$application->id, $attempt->confirmation_reference);

        Http::assertSent(function ($request) use ($application, $applicant): bool {
            return $request->url() === 'http://127.0.0.1:3911/submit'
                && $request['user_id'] === $applicant->id
                && $request['site_id'] === 'arbeitsagentur.de'
                && $request['application_id'] === $application->id
                && $request->hasHeader('X-Mode-B-Worker-Secret', 'dev-shared-secret');
        });
    }

    /**
     * @return array{0: User, 1: User, 2: CandidateListingMatch}
     */
    private function makeProposedArbeitsagenturMatch(): array
    {
        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create(['name' => 'BA Love Path Candidate']);

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => 'IT',
            'city' => 'Berlin',
            'province' => 'BE',
            'language' => 'de',
            'education' => 'Abitur',
            'desired_path' => 'ausbildung',
        ]);
        $profile->approval = ApprovalStatus::Approved;
        $profile->approved_by = $staff->id;
        $profile->approved_at = now();
        $profile->save();

        CvAsset::factory()->for($applicant)->create();
        MailboxIdentity::factory()->for($applicant)->ready()->create([
            'domain' => 'gmx.de',
            'address' => 'ba.love.path.'.uniqid('', true).'@gmx.de',
            'state' => MailboxState::Ready,
        ]);

        SiteIdentity::factory()->for($applicant)->healthy()->create([
            'site' => 'arbeitsagentur.de',
            'site_login' => 'ba.love.path',
            'secret' => 'secret',
            'state' => SiteIdentityState::Healthy,
            'paused' => false,
        ]);

        $listing = Listing::factory()->forSource('arbeitsagentur.de')->create([
            'title' => 'BA Love Path Stelle',
            'category' => 'IT',
            'country' => 'DE',
            'city' => 'Berlin',
            'url' => 'https://www.arbeitsagentur.de/jobsuche/stelle/love-path',
            'apply_url' => 'https://www.arbeitsagentur.de/jobsuche/stelle/love-path',
            'education_requirement' => null,
            'language_requirement' => null,
        ]);

        $match = CandidateListingMatch::factory()
            ->for($applicant)
            ->for($listing)
            ->proposed()
            ->create();

        return [$staff, $applicant->fresh(['profile', 'cvAsset', 'mailboxIdentity']) ?? $applicant, $match];
    }
}
