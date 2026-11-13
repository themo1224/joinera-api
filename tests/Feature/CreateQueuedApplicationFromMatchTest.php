<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\CvAsset;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\User;
use App\Services\CreateQueuedApplicationFromMatch;
use App\Services\UpsertCandidateListingMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateQueuedApplicationFromMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
            'joinera.sites.v1' => ['stepstone'],
            'joinera.queues.match' => 'match',
        ]);
    }

    public function test_eligible_queued_match_creates_one_application_queued(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = Listing::factory()->forSource('ausbildung.de')->create([
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertSame(1, Application::query()->count());

        $application = Application::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertSame($applicant->id, $application->user_id);
        $this->assertSame($listing->id, $application->listing_id);
        $this->assertSame($match->id, $application->candidate_listing_match_id);
        $this->assertSame('ausbildung.de', $application->site);
        $this->assertNotSame(ApplicationStatus::Submitted, $application->status);

        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::Matched->value
                && $event->to === ApplicationStatus::Queued->value;
        });
    }

    public function test_repeat_upsert_returns_same_application_id(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = $this->createHardPassListing();

        app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);
        $first = Application::query()->first();
        $this->assertNotNull($first);

        Event::assertDispatchedTimes(ApplicationStatusChanged::class, 1);

        app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertSame(1, Application::query()->count());
        $second = Application::query()->first();
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(ApplicationStatus::Queued, $second->status);

        Event::assertDispatchedTimes(ApplicationStatusChanged::class, 1);
    }

    public function test_hard_skipped_match_creates_no_application(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = Listing::factory()->create([
            'category' => 'Nursing',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Skipped, $match->status);
        $this->assertSame(MatchHardSkipReason::Industry, $match->hard_skip_reason);
        $this->assertSame(0, Application::query()->count());

        $fromService = app(CreateQueuedApplicationFromMatch::class)->handle($match);
        $this->assertNull($fromService);
        $this->assertSame(0, Application::query()->count());
    }

    public function test_soft_mismatch_still_creates_queued_application(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, language: 'fa', city: 'Berlin');
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => 'de C1',
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertIsArray($match->soft_signals);
        $this->assertSame('language_code_mismatch', $match->soft_signals['language_level']['reason']);

        $application = Application::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertSame('azubiyo', $application->site);
    }

    public function test_force_queued_match_fixture_creates_application_via_shared_service(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create();
        $listing = Listing::factory()->forSource('stepstone')->create();

        $match = CandidateListingMatch::factory()
            ->for($applicant)
            ->for($listing)
            ->forceQueuedBy($staff)
            ->create();

        $application = app(CreateQueuedApplicationFromMatch::class)->handle($match);

        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Queued, $application->status);
        $this->assertSame($match->id, $application->candidate_listing_match_id);
        $this->assertSame('stepstone', $application->site);
        $this->assertSame(1, Application::query()->count());

        Event::assertDispatched(ApplicationStatusChanged::class, function (ApplicationStatusChanged $event) use ($application): bool {
            return $event->applicationId === $application->id
                && $event->from === ApplicationStatus::Matched->value
                && $event->to === ApplicationStatus::Queued->value;
        });
    }

    public function test_existing_matched_application_is_promoted_once(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = $this->createHardPassListing();

        $match = CandidateListingMatch::factory()
            ->for($applicant)
            ->for($listing)
            ->queued()
            ->create();

        $existing = Application::factory()
            ->forMatch($match)
            ->matched()
            ->create();

        $first = app(CreateQueuedApplicationFromMatch::class)->handle($match);
        $second = app(CreateQueuedApplicationFromMatch::class)->handle($match);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($existing->id, $first->id);
        $this->assertSame($existing->id, $second->id);
        $this->assertSame(ApplicationStatus::Queued, $second->status);
        $this->assertSame(1, Application::query()->count());

        Event::assertDispatchedTimes(ApplicationStatusChanged::class, 1);
    }

    public function test_later_pipeline_status_is_not_regressed(): void
    {
        Event::fake([ApplicationStatusChanged::class]);

        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = $this->createHardPassListing();

        $match = CandidateListingMatch::factory()
            ->for($applicant)
            ->for($listing)
            ->queued()
            ->create();

        Application::factory()
            ->forMatch($match)
            ->preparingDocs()
            ->create();

        $application = app(CreateQueuedApplicationFromMatch::class)->handle($match);

        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::PreparingDocs, $application->status);
        Event::assertNotDispatched(ApplicationStatusChanged::class);
    }

    private function createHardPassListing(): Listing
    {
        return Listing::factory()->create([
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);
    }

    private function createEligibleApplicant(
        User $approver,
        string $language = 'de',
        string $city = 'Berlin',
    ): User {
        $applicant = User::factory()->create(['name' => 'Eligible Candidate']);

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => 'IT',
            'city' => $city,
            'province' => 'BE',
            'language' => $language,
        ]);
        $profile->approval = ApprovalStatus::Approved;
        $profile->approved_by = $approver->id;
        $profile->approved_at = now();
        $profile->save();

        CvAsset::factory()->for($applicant)->create();

        MailboxIdentity::factory()
            ->for($applicant)
            ->ready()
            ->create([
                'domain' => 'gmx.de',
                'address' => 'eligible.candidate.'.uniqid('', true).'@gmx.de',
                'state' => MailboxState::Ready,
            ]);

        return $applicant->fresh(['profile', 'cvAsset', 'mailboxIdentity']) ?? $applicant;
    }
}
