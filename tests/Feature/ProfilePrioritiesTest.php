<?php

namespace Tests\Feature;

use App\Events\ProfilePrioritiesChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProfilePrioritiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_get_returns_default_order_and_persists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry', 'city', 'province'],
            ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
        ]);

        $this->assertSame(
            ['industry', 'city', 'province'],
            $user->fresh()->profile->priorities,
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry', 'city', 'province'],
            ]);
    }

    public function test_put_with_reordered_priorities_persists_that_order(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['province', 'industry', 'city'],
            ]);

        $this->assertSame(
            ['province', 'industry', 'city'],
            $user->fresh()->profile->priorities,
        );
    }

    public function test_put_with_fourth_key_or_duplicate_is_422_and_order_stays(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city', 'language'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'industry'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame(
            ['province', 'industry', 'city'],
            $user->fresh()->profile->priorities,
        );
    }

    public function test_another_users_put_by_id_is_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->putJson('/api/v1/profile/'.$owner->id.'/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertNotFound();
    }

    public function test_staff_put_on_priorities_is_403(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_changing_put_dispatches_priorities_changed_event(): void
    {
        Event::fake([ProfilePrioritiesChanged::class]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        Event::assertDispatched(ProfilePrioritiesChanged::class, function (ProfilePrioritiesChanged $event) use ($user): bool {
            return $event->userId === $user->id
                && $event->priorities === ['province', 'industry', 'city']
                && $event::NAME === 'profile.priorities_changed';
        });
    }

    public function test_identical_put_does_not_dispatch_priorities_changed_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk();

        Event::fake([ProfilePrioritiesChanged::class]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['province', 'industry', 'city'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['province', 'industry', 'city'],
            ]);

        Event::assertNotDispatched(ProfilePrioritiesChanged::class);
    }
}
