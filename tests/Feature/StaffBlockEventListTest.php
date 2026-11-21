<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BlockEvent;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffBlockEventListTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_list_blocks(): void
    {
        $this->getJson('/api/v1/ops/blocks')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_user_role_cannot_list_blocks(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/ops/blocks')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_lists_open_block_alerts_by_default(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create(['name' => 'Paused Applicant']);
        $other = User::factory()->create(['name' => 'Cleared Applicant']);

        $openApp = Application::factory()->for($candidate)->applying()->create(['site' => 'azubiyo']);
        $closedApp = Application::factory()->for($other)->create(['site' => 'azubiyo']);

        SiteIdentity::factory()->for($candidate)->paused()->create(['site' => 'azubiyo']);
        SiteIdentity::factory()->for($other)->create(['site' => 'azubiyo', 'paused' => false]);

        $openEvent = BlockEvent::factory()->create([
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'application_id' => $openApp->id,
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);
        BlockEvent::factory()->resolved()->create([
            'user_id' => $other->id,
            'site' => 'azubiyo',
            'application_id' => $closedApp->id,
            'observed_kind' => BlockEvent::KIND_TWO_FACTOR,
        ]);

        $identity = SiteIdentity::query()
            ->where('user_id', $candidate->id)
            ->where('site', 'azubiyo')
            ->firstOrFail();

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/blocks')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('openCount', 1)
            ->assertJsonPath('alerts.0.id', $openEvent->id)
            ->assertJsonPath('alerts.0.applicationId', $openApp->id)
            ->assertJsonPath('alerts.0.applicantId', $candidate->id)
            ->assertJsonPath('alerts.0.applicantName', 'Paused Applicant')
            ->assertJsonPath('alerts.0.siteId', 'azubiyo')
            ->assertJsonPath('alerts.0.siteIdentityId', $identity->id)
            ->assertJsonPath('alerts.0.kind', 'captcha')
            ->assertJsonPath('alerts.0.open', true)
            ->assertJsonPath('alerts.0.needsHelp', true)
            ->assertJsonStructure(['alerts' => [['createdAt', 'needsHelpMessage', 'companyApplyUrl']]]);
    }

    public function test_opens_false_returns_closed_alerts(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $other = User::factory()->create();

        SiteIdentity::factory()->for($candidate)->paused()->create(['site' => 'azubiyo']);
        SiteIdentity::factory()->for($other)->create(['site' => 'azubiyo', 'paused' => false]);

        BlockEvent::factory()->create([
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);
        $closed = BlockEvent::factory()->resolved()->create([
            'user_id' => $other->id,
            'site' => 'azubiyo',
            'observed_kind' => BlockEvent::KIND_LOGIN_WALL,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/blocks?opens=false')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('openCount', 1)
            ->assertJsonPath('alerts.0.id', $closed->id)
            ->assertJsonPath('alerts.0.open', false);
    }

    public function test_kinds_and_search_filters(): void
    {
        $staff = User::factory()->staff()->create();
        $alice = User::factory()->create(['name' => 'Alice Blocked']);
        $bob = User::factory()->create(['name' => 'Bob Blocked']);

        SiteIdentity::factory()->for($alice)->paused()->create(['site' => 'azubiyo']);
        SiteIdentity::factory()->for($bob)->paused()->create(['site' => 'ausbildung']);

        BlockEvent::factory()->create([
            'user_id' => $alice->id,
            'site' => 'azubiyo',
            'observed_kind' => BlockEvent::KIND_CAPTCHA,
            'resolved_at' => null,
        ]);
        BlockEvent::factory()->create([
            'user_id' => $bob->id,
            'site' => 'ausbildung',
            'observed_kind' => BlockEvent::KIND_TWO_FACTOR,
            'resolved_at' => null,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/blocks?kinds=two_factor&search=Bob')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('alerts.0.applicantName', 'Bob Blocked')
            ->assertJsonPath('alerts.0.kind', 'two_factor');
    }

    public function test_admin_can_list_blocks(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();
        SiteIdentity::factory()->for($candidate)->paused()->create(['site' => 'azubiyo']);
        BlockEvent::factory()->create([
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'resolved_at' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ops/blocks')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_open_block_without_site_identity_is_listed(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create(['name' => 'Company Only']);
        $app = Application::factory()->for($candidate)->applying()->create(['site' => 'azubiyo']);

        $event = BlockEvent::factory()->create([
            'user_id' => $candidate->id,
            'site' => 'azubiyo',
            'application_id' => $app->id,
            'observed_kind' => BlockEvent::KIND_COOKIE_WALL,
            'resolved_at' => null,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ops/blocks')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('openCount', 1)
            ->assertJsonPath('alerts.0.id', $event->id)
            ->assertJsonPath('alerts.0.kind', 'cookie_wall')
            ->assertJsonPath('alerts.0.open', true)
            ->assertJsonPath('alerts.0.siteIdentityId', null);
    }
}
