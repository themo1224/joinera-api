<?php

namespace Tests\Feature;

use App\Events\ProfilePrioritiesChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StaffProfilePrioritiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_get_returns_default_order_and_persists(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $candidate->id,
        ]);

        $this->assertSame(
            ['industry'],
            $candidate->fresh()->profile->priorities,
        );

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);
    }

    public function test_staff_put_with_industry_priority_persists(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->assertSame(
            ['industry'],
            $candidate->fresh()->profile->priorities,
        );
    }

    public function test_admin_can_get_and_put_candidate_priorities(): void
    {
        $candidate = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->assertSame(
            ['industry'],
            $candidate->fresh()->profile->priorities,
        );
    }

    public function test_staff_put_with_legacy_or_extra_keys_is_422_and_order_stays(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry', 'city', 'province'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['city'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame(
            ['industry'],
            $candidate->fresh()->profile->priorities,
        );
    }

    public function test_candidate_cannot_use_staff_priorities_route(): void
    {
        $candidate = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($candidate, 'sanctum')
            ->getJson('/api/v1/users/'.$other->id.'/profile/priorities')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($candidate, 'sanctum')
            ->putJson('/api/v1/users/'.$other->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_cannot_get_or_put_priorities_for_staff_or_admin_target(): void
    {
        $staffActor = User::factory()->staff()->create();
        $staffTarget = User::factory()->staff()->create();
        $adminTarget = User::factory()->admin()->create();

        $this->actingAs($staffActor, 'sanctum')
            ->getJson('/api/v1/users/'.$staffTarget->id.'/profile/priorities')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->actingAs($staffActor, 'sanctum')
            ->putJson('/api/v1/users/'.$adminTarget->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_identical_staff_put_does_not_dispatch_priorities_changed_event(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk();

        Event::fake([ProfilePrioritiesChanged::class]);

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        Event::assertNotDispatched(ProfilePrioritiesChanged::class);
    }
}
