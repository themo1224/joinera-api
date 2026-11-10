<?php

namespace Tests\Feature;

use App\Enums\MailboxState;
use App\Enums\SiteIdentityState;
use App\Models\MailboxIdentity;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_unset_returns_needs_staff_and_mailbox_provider_unset(): void
    {
        config([
            'joinera.mailbox.mode' => null,
            'joinera.sites.v1' => ['stepstone'],
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/identity/readiness')
            ->assertOk()
            ->assertJsonPath('readiness', 'needs_staff')
            ->assertJsonPath('reason', 'mailbox_provider_unset');
    }

    public function test_mailbox_ready_plus_healthy_unpaused_site_returns_ready(): void
    {
        $this->configureReadyEnvironment();

        $user = User::factory()->create();
        $this->createReadyMailbox($user);
        $this->createHealthySiteIdentity($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/identity/readiness')
            ->assertOk()
            ->assertJsonPath('readiness', 'ready')
            ->assertJsonPath('reason', 'ready');
    }

    public function test_mailbox_ready_and_all_sites_paused_returns_blocked(): void
    {
        $this->configureReadyEnvironment();

        $user = User::factory()->create();
        $this->createReadyMailbox($user);

        $identity = $this->createHealthySiteIdentity($user);
        $identity->paused = true;
        $identity->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/identity/readiness')
            ->assertOk()
            ->assertJsonPath('readiness', 'blocked')
            ->assertJsonPath('reason', 'all_sites_paused');
    }

    public function test_user_put_identity_readiness_is_405(): void
    {
        $this->configureReadyEnvironment();

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/identity/readiness', ['readiness' => 'ready'])
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }

    public function test_another_user_cannot_read_this_readiness(): void
    {
        $this->configureReadyEnvironment();

        $owner = User::factory()->create();
        $this->createReadyMailbox($owner);
        $this->createHealthySiteIdentity($owner);

        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/identity/readiness')
            ->assertOk()
            ->assertJsonPath('readiness', 'needs_staff')
            ->assertJsonPath('reason', 'mailbox_not_ready');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/users/'.$owner->id.'/identity/readiness')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_can_get_user_identity_readiness(): void
    {
        $this->configureReadyEnvironment();

        $user = User::factory()->create();
        $this->createReadyMailbox($user);
        $this->createHealthySiteIdentity($user);

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/identity/readiness')
            ->assertOk()
            ->assertJsonPath('readiness', 'ready')
            ->assertJsonPath('reason', 'ready');
    }

    private function configureReadyEnvironment(): void
    {
        config([
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.sites.v1' => ['stepstone'],
        ]);
    }

    private function createReadyMailbox(User $user): MailboxIdentity
    {
        return MailboxIdentity::query()->create([
            'user_id' => $user->id,
            'domain' => 'gmx.de',
            'address' => 'anna.schmidt@gmx.de',
            'password' => 'gmx-secret',
            'provider' => 'gmx',
            'state' => MailboxState::Ready,
        ]);
    }

    private function createHealthySiteIdentity(User $user): SiteIdentity
    {
        return SiteIdentity::query()->create([
            'user_id' => $user->id,
            'site' => 'stepstone',
            'site_login' => $user->email,
            'secret' => 'secret-password',
            'state' => SiteIdentityState::Healthy,
            'paused' => false,
        ]);
    }
}
