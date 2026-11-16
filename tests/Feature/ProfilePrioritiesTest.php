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
                'priorities' => ['industry'],
            ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
        ]);

        $this->assertSame(
            ['industry'],
            $user->fresh()->profile->priorities,
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);
    }

    public function test_put_with_industry_priority_persists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->assertSame(
            ['industry'],
            $user->fresh()->profile->priorities,
        );
    }

    public function test_put_with_legacy_or_extra_keys_is_422_and_order_stays(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry', 'city', 'province'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['city'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame(
            ['industry'],
            $user->fresh()->profile->priorities,
        );
    }

    public function test_another_users_put_by_id_is_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->putJson('/api/v1/profile/'.$owner->id.'/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertNotFound();
    }

    public function test_staff_put_on_priorities_is_403(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_identical_put_does_not_dispatch_priorities_changed_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk();

        Event::fake([ProfilePrioritiesChanged::class]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/priorities', [
                'priorities' => ['industry'],
            ])
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        Event::assertNotDispatched(ProfilePrioritiesChanged::class);
    }

    public function test_legacy_stored_priorities_coerce_to_industry_on_get(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->priorities = ['province', 'industry', 'city'];
        $profile->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/priorities')
            ->assertOk()
            ->assertExactJson([
                'priorities' => ['industry'],
            ]);

        $this->assertSame(
            ['industry'],
            $user->fresh()->profile->priorities,
        );
    }
}
