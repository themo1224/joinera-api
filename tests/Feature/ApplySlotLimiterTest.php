<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BlockEvent;
use App\Models\User;
use App\Services\Apply\ApplySlotLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class ApplySlotLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'joinera.anti_block.jitter_min_seconds' => null,
            'joinera.anti_block.jitter_max_seconds' => null,
            'joinera.anti_block.max_per_hour_user_site' => null,
            'joinera.anti_block.cooldown_seconds_after_block' => null,
        ]);
    }

    public function test_null_layers_allow_slot_and_mark_unconfigured(): void
    {
        $limiter = app(ApplySlotLimiter::class);
        $slot = $limiter->requestSlot(1, 'azubiyo');

        $this->assertTrue($slot['allowed']);
        $this->assertNull($slot['code']);
        $this->assertNull($slot['jitterSeconds']);
        $this->assertFalse($slot['layers']['max_per_hour_user_site']['configured']);
        $this->assertSame('user:1|site:azubiyo', $slot['profileKey']);
    }

    public function test_profile_key_differs_by_user_and_site(): void
    {
        $limiter = app(ApplySlotLimiter::class);

        $this->assertNotSame(
            $limiter->profileKey(1, 'azubiyo'),
            $limiter->profileKey(2, 'azubiyo'),
        );
        $this->assertNotSame(
            $limiter->profileKey(1, 'azubiyo'),
            $limiter->profileKey(1, 'ausbildung.de'),
        );
        $this->assertStringContainsString('user:1', $limiter->profileKey(1, 'azubiyo'));
    }

    public function test_hourly_cap_denies_second_slot(): void
    {
        config(['joinera.anti_block.max_per_hour_user_site' => 1]);
        $limiter = app(ApplySlotLimiter::class);

        $first = $limiter->requestSlot(9, 'azubiyo');
        $second = $limiter->requestSlot(9, 'azubiyo');

        $this->assertTrue($first['allowed']);
        $this->assertFalse($second['allowed']);
        $this->assertSame(ApplySlotLimiter::DENY_CAP, $second['code']);
    }

    public function test_cooldown_after_open_block(): void
    {
        config(['joinera.anti_block.cooldown_seconds_after_block' => 3600]);
        $user = User::factory()->create();
        $app = Application::factory()->for($user)->applying()->create(['site' => 'azubiyo']);
        BlockEvent::factory()->create([
            'user_id' => $user->id,
            'site' => 'azubiyo',
            'application_id' => $app->id,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);

        $slot = app(ApplySlotLimiter::class)->requestSlot((int) $user->id, 'azubiyo');

        $this->assertFalse($slot['allowed']);
        $this->assertSame(ApplySlotLimiter::DENY_COOLDOWN, $slot['code']);
    }

    public function test_jitter_draws_inside_band_and_varies(): void
    {
        config([
            'joinera.anti_block.jitter_min_seconds' => 1,
            'joinera.anti_block.jitter_max_seconds' => 3,
        ]);
        $limiter = app(ApplySlotLimiter::class);

        $draws = [];
        for ($i = 0; $i < 20; $i++) {
            $slot = $limiter->requestSlot(50 + $i, 'azubiyo');
            $this->assertTrue($slot['allowed']);
            $this->assertNotNull($slot['jitterSeconds']);
            $limiter->assertJitterInBand((int) $slot['jitterSeconds']);
            $draws[] = $slot['jitterSeconds'];
        }

        $this->assertGreaterThan(1, count(array_unique($draws)));
    }

    public function test_assert_jitter_rejects_outside_band(): void
    {
        config([
            'joinera.anti_block.jitter_min_seconds' => 2,
            'joinera.anti_block.jitter_max_seconds' => 4,
        ]);
        $limiter = app(ApplySlotLimiter::class);

        $this->expectException(InvalidArgumentException::class);
        $limiter->assertJitterInBand(9);
    }

    public function test_intake_ticket_denied(): void
    {
        $slot = app(ApplySlotLimiter::class)->requestSlot(1, 'azubiyo', isIntake: true);

        $this->assertFalse($slot['allowed']);
        $this->assertSame(ApplySlotLimiter::DENY_INTAKE_SESSION, $slot['code']);
    }

    public function test_user_can_read_own_apply_limits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apply-limits?site=azubiyo')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('userId', $user->id)
            ->assertJsonPath('layers.max_per_hour_user_site.configured', false);
    }

    public function test_other_user_cannot_read_apply_limits(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/apply-limits?site=azubiyo&user_id='.$other->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }
}
