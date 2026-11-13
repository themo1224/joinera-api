<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxState;
use App\Enums\MatchHardSkipReason;
use App\Enums\MatchStatus;
use App\Events\CandidateMatchRecomputeRequested;
use App\Events\ListingCreated;
use App\Events\ProfilePrioritiesChanged;
use App\Jobs\MatchListingJob;
use App\Jobs\PrepareApplicationDocsJob;
use App\Jobs\RematchCandidateJob;
use App\Models\CandidateListingMatch;
use App\Models\CvAsset;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\User;
use App\Services\MatchListingAgainstEligibleCandidates;
use App\Services\RematchCandidateAgainstListings;
use App\Services\UpsertCandidateListingMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MatchRunnerTest extends TestCase
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

        Queue::fake([PrepareApplicationDocsJob::class]);
    }

    public function test_eligible_hard_pass_writes_queued_match(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = $this->createHardPassListing();

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertNull($match->hard_skip_reason);
        $this->assertNotNull($match->queued_at);
        $this->assertSame(1, CandidateListingMatch::query()->count());
    }

    public function test_industry_mismatch_writes_skipped_with_reason(): void
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
        $this->assertNull($match->queued_at);
    }

    public function test_non_de_listing_writes_skipped_with_reason(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = Listing::factory()->create([
            'category' => 'IT',
            'country' => 'AT',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Skipped, $match->status);
        $this->assertSame(MatchHardSkipReason::NotGermany, $match->hard_skip_reason);
    }

    public function test_education_mismatch_writes_skipped_with_reason(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, education: 'Hauptschulabschluss');
        $listing = Listing::factory()->create([
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => 'Abitur',
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Skipped, $match->status);
        $this->assertSame(MatchHardSkipReason::Education, $match->hard_skip_reason);
    }

    public function test_soft_language_mismatch_alone_does_not_skip(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, language: 'fa', city: 'Berlin');
        $listing = Listing::factory()->create([
            'category' => 'IT',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => 'de C1',
        ]);

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertNull($match->hard_skip_reason);
        $this->assertIsArray($match->soft_signals);
        $this->assertSame('language_code_mismatch', $match->soft_signals['language_level']['reason']);
    }

    public function test_ineligible_candidate_creates_no_match(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff, approved: false);
        $listing = $this->createHardPassListing();

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNull($match);
        $this->assertSame(0, CandidateListingMatch::query()->count());
    }

    public function test_duplicate_listing_created_keeps_one_row(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $listing = $this->createHardPassListing();

        app(MatchListingAgainstEligibleCandidates::class)->handle($listing);
        app(MatchListingAgainstEligibleCandidates::class)->handle($listing);

        $this->assertSame(1, CandidateListingMatch::query()->count());
        $match = CandidateListingMatch::query()->first();
        $this->assertNotNull($match);
        $this->assertSame($applicant->id, $match->user_id);
        $this->assertSame($listing->id, $match->listing_id);
        $this->assertSame(MatchStatus::Queued, $match->status);
    }

    public function test_listing_created_dispatches_match_listing_job_on_match_queue(): void
    {
        Queue::fake();

        ListingCreated::dispatch(42);

        Queue::assertPushedOn('match', MatchListingJob::class, function (MatchListingJob $job): bool {
            return $job->listingId === 42;
        });
    }

    public function test_profile_priorities_changed_dispatches_rematch_job_on_match_queue(): void
    {
        Queue::fake();

        ProfilePrioritiesChanged::dispatch(7, ['city', 'industry', 'province']);

        Queue::assertPushedOn('match', RematchCandidateJob::class, function (RematchCandidateJob $job): bool {
            return $job->userId === 7;
        });
    }

    public function test_candidate_match_recompute_requested_dispatches_rematch_job_on_match_queue(): void
    {
        Queue::fake();

        CandidateMatchRecomputeRequested::dispatch(11);

        Queue::assertPushedOn('match', RematchCandidateJob::class, function (RematchCandidateJob $job): bool {
            return $job->userId === 11;
        });
    }

    public function test_force_queued_row_is_not_downgraded_on_hard_fail(): void
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

        CandidateListingMatch::factory()
            ->for($applicant)
            ->for($listing)
            ->forceQueuedBy($staff)
            ->create();

        $match = app(UpsertCandidateListingMatch::class)->handle($applicant, $listing);

        $this->assertNotNull($match);
        $this->assertSame(MatchStatus::Queued, $match->status);
        $this->assertNull($match->hard_skip_reason);
        $this->assertNotNull($match->force_queued_at);
        $this->assertSame($staff->id, $match->force_queued_by);
    }

    public function test_rematch_candidate_writes_matches_for_existing_listings(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = $this->createEligibleApplicant($staff);
        $pass = $this->createHardPassListing();
        $skip = Listing::factory()->create([
            'category' => 'Nursing',
            'country' => 'DE',
            'education_requirement' => null,
            'city' => 'Berlin',
            'language_requirement' => null,
        ]);

        app(RematchCandidateAgainstListings::class)->handle($applicant);

        $this->assertSame(2, CandidateListingMatch::query()->count());
        $this->assertSame(
            MatchStatus::Queued,
            CandidateListingMatch::query()->where('listing_id', $pass->id)->value('status'),
        );
        $this->assertSame(
            MatchStatus::Skipped,
            CandidateListingMatch::query()->where('listing_id', $skip->id)->value('status'),
        );
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
        bool $approved = true,
        bool $complete = true,
        bool $withCv = true,
        bool $withMailbox = true,
        MailboxState $mailboxState = MailboxState::Ready,
        ?string $education = null,
        string $language = 'de',
        string $city = 'Berlin',
    ): User {
        $applicant = User::factory()->create(['name' => 'Eligible Candidate']);

        /** @var Profile $profile */
        $profile = $applicant->ensureProfile();
        $profile->fill([
            'industry' => $complete ? 'IT' : null,
            'city' => $complete ? $city : null,
            'province' => $complete ? 'BE' : null,
            'language' => $complete ? $language : null,
            'education' => $education,
        ]);

        if ($approved) {
            $profile->approval = ApprovalStatus::Approved;
            $profile->approved_by = $approver->id;
            $profile->approved_at = now();
        } else {
            $profile->approval = ApprovalStatus::Pending;
            $profile->approved_by = null;
            $profile->approved_at = null;
        }

        $profile->save();

        if ($withCv) {
            CvAsset::factory()->for($applicant)->create();
        }

        if ($withMailbox) {
            $factory = MailboxIdentity::factory()->for($applicant);
            $factory = match ($mailboxState) {
                MailboxState::Ready => $factory->ready(),
                MailboxState::Failed => $factory->failed(),
                MailboxState::Pending => $factory->pending(),
            };
            $factory->create([
                'domain' => 'gmx.de',
                'address' => 'eligible.candidate.'.uniqid('', true).'@gmx.de',
                'state' => $mailboxState,
            ]);
        }

        return $applicant->fresh(['profile', 'cvAsset', 'mailboxIdentity']) ?? $applicant;
    }
}
