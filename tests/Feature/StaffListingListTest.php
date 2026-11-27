<?php

namespace Tests\Feature;

use App\Enums\ListingKind;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffListingListTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_lists_listings_with_ops_payload(): void
    {
        $staff = User::factory()->staff()->create();
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'title' => 'Nursing Ausbildung',
            'company' => 'Klinik Nord',
            'city' => 'Munich',
            'kind' => ListingKind::Ausbildung,
            'apply_url' => 'https://company.example/apply/1',
            'category' => 'Nursing',
            'country' => 'DE',
            'language_requirement' => 'B1',
            'education_requirement' => 'Mittlere Reife',
            'description' => 'Long description should be omitted from list.',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('offset', 0)
            ->assertJsonPath('limit', 20)
            ->assertJsonPath('listings.0.id', $listing->id)
            ->assertJsonPath('listings.0.source', 'azubiyo')
            ->assertJsonPath('listings.0.externalId', $listing->external_id)
            ->assertJsonPath('listings.0.kind', 'ausbildung')
            ->assertJsonPath('listings.0.category', 'Nursing')
            ->assertJsonPath('listings.0.title', 'Nursing Ausbildung')
            ->assertJsonPath('listings.0.company', 'Klinik Nord')
            ->assertJsonPath('listings.0.city', 'Munich')
            ->assertJsonPath('listings.0.country', 'DE')
            ->assertJsonPath('listings.0.applyUrl', 'https://company.example/apply/1')
            ->assertJsonPath('listings.0.languageRequirement', 'B1')
            ->assertJsonPath('listings.0.educationRequirement', 'Mittlere Reife')
            ->assertJsonMissingPath('listings.0.description')
            ->assertJsonStructure(['listings' => [['url', 'firstSeenAt', 'lastSeenAt', 'postedAt']]]);
    }

    public function test_staff_shows_listing_with_description(): void
    {
        $staff = User::factory()->staff()->create();
        $listing = Listing::factory()->forSource('ausbildung.de')->create([
            'title' => 'IT Ausbildung',
            'description' => 'Full listing body for detail.',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings/'.$listing->id)
            ->assertOk()
            ->assertJsonPath('id', $listing->id)
            ->assertJsonPath('source', 'ausbildung.de')
            ->assertJsonPath('title', 'IT Ausbildung')
            ->assertJsonPath('description', 'Full listing body for detail.');
    }

    public function test_admin_can_list_listings(): void
    {
        $admin = User::factory()->admin()->create();
        Listing::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_list_filters_by_source_kind_and_search(): void
    {
        $staff = User::factory()->staff()->create();

        Listing::factory()->forSource('azubiyo')->job()->create([
            'title' => 'Berlin Nursing Role',
            'company' => 'Alpha Klinik',
            'city' => 'Berlin',
        ]);
        Listing::factory()->forSource('ausbildung.de')->ausbildung()->create([
            'title' => 'Hamburg IT Role',
            'company' => 'Beta GmbH',
            'city' => 'Hamburg',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?source=azubiyo')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('listings.0.source', 'azubiyo');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?kind=ausbildung')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('listings.0.kind', 'ausbildung');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?search=Alpha')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('listings.0.company', 'Alpha Klinik');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?search=Hamburg')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('listings.0.city', 'Hamburg');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?search=Nursing')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('listings.0.title', 'Berlin Nursing Role');
    }

    public function test_list_rejects_invalid_kind(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?kind=not_a_kind')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_user_role_cannot_list_listings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/listings')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_guest_cannot_list_listings(): void
    {
        $this->getJson('/api/v1/listings')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_list_paginates_with_page_and_limit(): void
    {
        $staff = User::factory()->staff()->create();
        Listing::factory()->count(3)->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings?page=2&limit=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('offset', 2)
            ->assertJsonPath('limit', 2)
            ->assertJsonCount(1, 'listings');
    }

    public function test_show_returns_404_for_missing_listing(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/listings/999999')
            ->assertNotFound();
    }
}
