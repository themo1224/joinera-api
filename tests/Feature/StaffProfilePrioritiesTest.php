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
                'priorities' => ['industry', 'city', 'province'],
            ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $candidate->id,
        ]);

        $this->assertSame(
            ['industry', 'city', 'province'],
            $candidate->fresh()->profile->priorities,
        );

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry', 'city', 'province'],
            ]);
    }

    public function test_staff_put_with_reordered_priorities_persists_that_order(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile/priorities')
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['province', 'industry', 'city'],
            ]);

        $this->assertSame(
            ['province', 'industry', 'city'],
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
                'priorities' => ['industry', 'city', 'province'],
            ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['city', 'province', 'industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['city', 'province', 'industry'],
            ]);

        $this->assertSame(
            ['city', 'province', 'industry'],
            $candidate->fresh()->profile->priorities,
        );
    }

    public function test_staff_put_with_fourth_key_or_duplicate_is_422_and_order_stays(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city', 'language'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'industry'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame(
            ['province', 'industry', 'city'],
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
                'priorities' => ['province', 'industry', 'city'],
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
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_changing_staff_put_dispatches_priorities_changed_event(): void
    {
        Event::fake([ProfilePrioritiesChanged::class]);

        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        Event::assertDispatched(ProfilePrioritiesChanged::class, function (ProfilePrioritiesChanged $event) use ($candidate): bool {
            return $event->userId === $candidate->id
                && $event->priorities === ['province', 'industry', 'city']
                && $event::NAME === 'profile.priorities_changed';
        });
    }

    public function test_identical_staff_put_does_not_dispatch_priorities_changed_event(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        Event::fake([ProfilePrioritiesChanged::class]);

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['province', 'industry', 'city'],
            ]);

        Event::assertNotDispatched(ProfilePrioritiesChanged::class);
    }
}
