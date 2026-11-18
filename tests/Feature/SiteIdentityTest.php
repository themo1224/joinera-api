<?php

namespace Tests\Feature;

use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_sites_v1_yields_409_and_zero_rows(): void
    {
        config(['joinera.sites.v1' => []]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'anna@example.com',
                'secret' => 'secret-password',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'sites_unconfigured');

        $this->assertDatabaseCount('site_identities', 0);
    }

    public function test_owner_can_store_login_and_get_omits_secret(): void
    {
        $this->configureSites(['stepstone']);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'anna@example.com',
                'secret' => 'secret-password',
            ])
            ->assertCreated()
            ->assertJsonPath('site', 'stepstone')
            ->assertJsonPath('site_login', 'anna@example.com')
            ->assertJsonPath('state', 'healthy')
            ->assertJsonMissingPath('secret');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/site-identities')
            ->assertOk();

        $payload = $response->json('data.0');
        $this->assertSame('stepstone', $payload['site']);
        $this->assertSame('anna@example.com', $payload['site_login']);
        $this->assertSame('healthy', $payload['state']);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('password', $payload);

        $row = SiteIdentity::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('secret-password', $row->secret);
        $this->assertNotSame('secret-password', $row->getRawOriginal('secret'));
    }

    public function test_second_user_may_reuse_same_site_and_site_login(): void
    {
        $this->configureSites(['stepstone']);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'shared@example.com',
                'secret' => 'one',
            ])
            ->assertCreated();

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'shared@example.com',
                'secret' => 'two',
            ])
            ->assertCreated();

        $this->assertSame(2, SiteIdentity::query()->count());
    }

    public function test_site_outside_sites_v1_is_422(): void
    {
        $this->configureSites(['stepstone']);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'indeed',
                'site_login' => 'anna@example.com',
                'secret' => 'secret-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_site');

        $this->assertDatabaseCount('site_identities', 0);
    }

    public function test_another_user_cannot_see_this_users_identities(): void
    {
        $this->configureSites(['stepstone']);

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'owner@example.com',
                'secret' => 'secret-password',
            ])
            ->assertCreated();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/site-identities')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/users/'.$owner->id.'/site-identities')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_replacing_secret_for_same_user_and_site_keeps_one_row(): void
    {
        $this->configureSites(['stepstone']);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'anna@example.com',
                'secret' => 'first-secret',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'anna@example.com',
                'secret' => 'second-secret',
            ])
            ->assertOk()
            ->assertJsonPath('site', 'stepstone')
            ->assertJsonMissingPath('secret');

        $this->assertSame(1, SiteIdentity::query()->where('user_id', $user->id)->count());

        $row = SiteIdentity::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('second-secret', $row->secret);
        $this->assertNotSame('second-secret', $row->getRawOriginal('secret'));
    }

    public function test_staff_metadata_get_has_no_secret(): void
    {
        $this->configureSites(['stepstone']);

        $user = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/site-identities', [
                'site' => 'stepstone',
                'site_login' => 'anna@example.com',
                'secret' => 'secret-password',
            ])
            ->assertCreated();

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/site-identities')
            ->assertOk()
            ->assertJsonPath('availableSites', ['stepstone']);

        $payload = $response->json('data.0');
        $this->assertSame(['site', 'site_login', 'state'], array_keys($payload));
        $this->assertArrayNotHasKey('secret', $payload);
    }

    public function test_admin_can_store_site_identity_for_applicant(): void
    {
        $this->configureSites(['azubiyo', 'ausbildung.de']);

        $applicant = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$applicant->id.'/site-identities', [
                'site' => 'azubiyo',
                'site_login' => 'staff-try@example.com',
                'secret' => 'nope',
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/users/'.$applicant->id.'/site-identities', [
                'site' => 'azubiyo',
                'site_login' => 'anna@example.com',
                'secret' => 'secret-password',
            ])
            ->assertCreated()
            ->assertJsonPath('site', 'azubiyo')
            ->assertJsonPath('site_login', 'anna@example.com')
            ->assertJsonMissingPath('secret');

        $this->assertDatabaseHas('site_identities', [
            'user_id' => $applicant->id,
            'site' => 'azubiyo',
            'site_login' => 'anna@example.com',
        ]);
    }

    /**
     * @param  list<string>  $sites
     */
    private function configureSites(array $sites): void
    {
        config(['joinera.sites.v1' => $sites]);
    }
}
