<?php

namespace Tests\Feature;

use App\Models\Industry;
use App\Models\SubIndustry;
use App\Models\User;
use Database\Seeders\IndustryCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffIndustryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_lists_active_industry_tree(): void
    {
        $this->seed(IndustryCatalogSeeder::class);
        $staff = User::factory()->staff()->create();

        $archived = Industry::query()->where('slug', 'koch')->firstOrFail();
        $archived->update(['archived_at' => now()]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/industries')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['slug' => 'koch'])
            ->assertJsonFragment(['slug' => 'mfa'])
            ->assertJsonFragment(['slug' => 'hausarztpraxis']);
    }

    public function test_staff_can_include_archived(): void
    {
        $staff = User::factory()->staff()->create();
        $industry = Industry::factory()->archived()->create(['slug' => 'archived-path']);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/industries?includeArchived=1')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $industry->id,
                'slug' => 'archived-path',
            ]);
    }

    public function test_staff_creates_updates_and_archives_industry(): void
    {
        $staff = User::factory()->staff()->create();

        $create = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries', [
                'slug' => 'New_Path',
                'labelEn' => 'New path',
                'labelDe' => 'Neuer Weg',
                'sortOrder' => 42,
            ])
            ->assertCreated()
            ->assertJsonPath('industry.slug', 'new_path')
            ->assertJsonPath('industry.labelEn', 'New path')
            ->assertJsonPath('industry.labelDe', 'Neuer Weg')
            ->assertJsonPath('industry.sortOrder', 42);

        $id = (int) $create->json('industry.id');

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/industries/'.$id, [
                'labelEn' => 'Updated path',
            ])
            ->assertOk()
            ->assertJsonPath('industry.labelEn', 'Updated path');

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries/'.$id.'/archive')
            ->assertOk()
            ->assertJsonPath('industry.archivedAt', fn ($value) => is_string($value) && $value !== '');

        $this->assertNotNull(Industry::query()->findOrFail($id)->archived_at);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries/'.$id.'/unarchive')
            ->assertOk()
            ->assertJsonPath('industry.archivedAt', null);
    }

    public function test_staff_creates_updates_and_archives_sub_industry(): void
    {
        $staff = User::factory()->staff()->create();
        $industry = Industry::factory()->create(['slug' => 'parent-path']);

        $create = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries/'.$industry->id.'/sub-industries', [
                'slug' => 'Focus_One',
                'labelEn' => 'Focus one',
                'labelDe' => 'Schwerpunkt eins',
            ])
            ->assertCreated()
            ->assertJsonPath('subIndustry.slug', 'focus_one')
            ->assertJsonPath('subIndustry.industryId', $industry->id);

        $subId = (int) $create->json('subIndustry.id');

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/sub-industries/'.$subId, [
                'labelDe' => 'Schwerpunkt aktualisiert',
            ])
            ->assertOk()
            ->assertJsonPath('subIndustry.labelDe', 'Schwerpunkt aktualisiert');

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/sub-industries/'.$subId.'/archive')
            ->assertOk();

        $this->assertNotNull(SubIndustry::query()->findOrFail($subId)->archived_at);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/sub-industries/'.$subId.'/unarchive')
            ->assertOk()
            ->assertJsonPath('subIndustry.archivedAt', null);
    }

    public function test_archiving_industry_archives_active_children(): void
    {
        $staff = User::factory()->staff()->create();
        $industry = Industry::factory()->create();
        $child = SubIndustry::factory()->for($industry)->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries/'.$industry->id.'/archive')
            ->assertOk();

        $this->assertNotNull($child->fresh()->archived_at);
    }

    public function test_candidate_cannot_list_industries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/industries')
            ->assertForbidden();
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        Industry::factory()->create(['slug' => 'taken']);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/industries', [
                'slug' => 'taken',
                'labelEn' => 'Taken',
                'labelDe' => 'Taken',
            ])
            ->assertUnprocessable();
    }
}
