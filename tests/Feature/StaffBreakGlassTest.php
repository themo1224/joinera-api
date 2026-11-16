<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\SiteIdentity;
use App\Models\StaffAccessAudit;
use App\Models\User;
use App\Services\BreakGlassRevealSiteSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StaffBreakGlassTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_break_glass(): void
    {
        $identity = SiteIdentity::factory()->create();

        $this->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
            'reason' => 'Need to finish apply',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_user_role_cannot_break_glass_and_writes_no_audit(): void
    {
        $user = User::factory()->create();
        $identity = SiteIdentity::factory()->paused()->create(['secret' => 'site-password']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => 'Need to finish apply',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseCount('staff_access_audits', 0);
    }

    public function test_staff_break_glass_returns_secret_and_one_audit(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staff@example.test']);
        $candidate = User::factory()->create();
        $application = Application::factory()->for($candidate)->applying()->create(['site' => 'azubiyo']);
        $identity = SiteIdentity::factory()->for($candidate)->paused()->create([
            'site' => 'azubiyo',
            'secret' => 'site-password-once',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => 'CAPTCHA blocked Mode B',
                'applicationId' => $application->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('secret', 'site-password-once')
            ->assertJsonStructure(['auditId']);

        $this->assertDatabaseCount('staff_access_audits', 1);
        $this->assertDatabaseHas('staff_access_audits', [
            'actor_id' => $staff->id,
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'site_identity_id' => $identity->id,
            'application_id' => $application->id,
            'reason' => 'CAPTCHA blocked Mode B',
        ]);

        $identity->refresh();
        $this->assertTrue($identity->paused);
    }

    public function test_empty_reason_is_422_and_writes_no_audit(): void
    {
        $staff = User::factory()->staff()->create();
        $identity = SiteIdentity::factory()->paused()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => '   ',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_access_audits', 0);
    }

    public function test_rate_limit_is_429_and_writes_no_audit(): void
    {
        $staff = User::factory()->staff()->create();
        $identity = SiteIdentity::factory()->paused()->create(['secret' => 'pw']);

        $key = 'break-glass:'.$staff->id;
        RateLimiter::clear($key);
        for ($i = 0; $i < BreakGlassRevealSiteSecret::RATE_MAX; $i++) {
            RateLimiter::hit($key, BreakGlassRevealSiteSecret::RATE_DECAY_SECONDS);
        }

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => 'Another reveal',
            ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');

        $this->assertDatabaseCount('staff_access_audits', 0);
    }

    public function test_audit_write_failure_returns_no_secret_and_no_partial_audit(): void
    {
        $staff = User::factory()->staff()->create();
        $identity = SiteIdentity::factory()->paused()->create(['secret' => 'pw']);

        StaffAccessAudit::creating(function (): void {
            throw new \RuntimeException('forced audit failure');
        });

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => 'Should fail closed',
            ])
            ->assertStatus(500)
            ->assertJsonPath('code', 'audit_failed')
            ->assertJsonMissing(['secret']);

        $this->assertDatabaseCount('staff_access_audits', 0);
    }

    public function test_mismatched_application_is_not_found(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $other = User::factory()->create();
        $identity = SiteIdentity::factory()->for($candidate)->paused()->create(['site' => 'azubiyo']);
        $wrongApp = Application::factory()->for($other)->create(['site' => 'azubiyo']);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/site-identities/'.$identity->id.'/break-glass', [
                'reason' => 'Wrong app',
                'applicationId' => $wrongApp->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->assertDatabaseCount('staff_access_audits', 0);
    }
}
