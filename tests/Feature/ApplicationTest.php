<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\CandidateListingMatch;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_status_enum_values(): void
    {
        $this->assertSame('matched', ApplicationStatus::Matched->value);
        $this->assertSame('queued', ApplicationStatus::Queued->value);
        $this->assertSame('preparing_docs', ApplicationStatus::PreparingDocs->value);
        $this->assertSame('applying', ApplicationStatus::Applying->value);
        $this->assertSame('submitted', ApplicationStatus::Submitted->value);
        $this->assertSame('reply_received', ApplicationStatus::ReplyReceived->value);
        $this->assertSame('interview', ApplicationStatus::Interview->value);
        $this->assertSame('rejected', ApplicationStatus::Rejected->value);
        $this->assertSame('offer', ApplicationStatus::Offer->value);
    }

    public function test_create_persists_with_default_matched_status_and_site_from_listing(): void
    {
        $listing = Listing::factory()->forSource('ausbildung.de')->create();
        $application = Application::factory()->for($listing)->create();

        $this->assertInstanceOf(ApplicationStatus::class, $application->status);
        $this->assertSame(ApplicationStatus::Matched, $application->status);
        $this->assertSame('ausbildung.de', $application->site);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'user_id' => $application->user_id,
            'listing_id' => $listing->id,
            'site' => 'ausbildung.de',
            'status' => 'matched',
        ]);
    }

    public function test_user_and_listing_pair_is_unique(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        Application::factory()->for($user)->for($listing)->create();

        $this->expectException(QueryException::class);

        Application::factory()->for($user)->for($listing)->create();
    }

    public function test_preparing_docs_status_is_assignable(): void
    {
        $application = Application::factory()->create();

        $application->status = ApplicationStatus::PreparingDocs;
        $application->save();

        $application->refresh();

        $this->assertSame(ApplicationStatus::PreparingDocs, $application->status);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'preparing_docs',
        ]);
    }

    public function test_relations_to_user_listing_match_and_inverses(): void
    {
        $candidate = User::factory()->create();
        $listing = Listing::factory()->forSource('azubiyo')->create();
        $match = CandidateListingMatch::factory()
            ->for($candidate)
            ->for($listing)
            ->create();

        $application = Application::factory()
            ->forMatch($match)
            ->create();

        $this->assertTrue($application->user->is($candidate));
        $this->assertTrue($application->listing->is($listing));
        $this->assertTrue($application->candidateListingMatch->is($match));
        $this->assertSame('azubiyo', $application->site);
        $this->assertTrue($candidate->applications()->whereKey($application->id)->exists());
        $this->assertTrue($listing->applications()->whereKey($application->id)->exists());
        $this->assertTrue($match->application->is($application));
    }

    public function test_migration_default_status_is_matched_when_omitted(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->forSource('arbeitsagentur.de')->create();

        $id = Application::query()->insertGetId([
            'user_id' => $user->id,
            'listing_id' => $listing->id,
            'site' => $listing->source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $application = Application::query()->findOrFail($id);

        $this->assertSame(ApplicationStatus::Matched, $application->status);
    }
}
