<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_returns_a_user_and_me_shows_that_role(): void
    {
        $response = $this->postJson('/api/v1/signup', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'user')
            ->assertJsonPath('user.email', 'ada@example.com');

        $this->assertTrue(Hash::check('password', User::query()->firstOrFail()->password));

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'id' => $response->json('user.id'),
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'role' => 'user',
            ]);
    }

    public function test_signup_rejects_a_role_and_creates_nobody(): void
    {
        $response = $this->postJson('/api/v1/signup', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_signup_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/signup', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_user_cannot_create_staff(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('panel')->plainTextToken)
            ->postJson('/api/v1/staff-users', $this->staffPayload('sam@example.com', 'staff'))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseMissing('users', ['email' => 'sam@example.com']);
    }

    public function test_an_admin_can_create_staff_and_that_staff_cannot_create_staff(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withToken($admin->createToken('panel')->plainTextToken)
            ->postJson('/api/v1/staff-users', $this->staffPayload('sam@example.com', 'staff'))
            ->assertCreated()
            ->assertJsonPath('role', 'staff');

        $staff = User::query()->where('email', 'sam@example.com')->firstOrFail();

        $this->flushAuth();

        $this->withToken($staff->createToken('panel')->plainTextToken)
            ->postJson('/api/v1/staff-users', $this->staffPayload('other@example.com', 'staff'))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_an_admin_can_create_another_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withToken($admin->createToken('panel')->plainTextToken)
            ->postJson('/api/v1/staff-users', $this->staffPayload('boss@example.com', 'admin'))
            ->assertCreated()
            ->assertJsonPath('role', 'admin');

        $this->assertDatabaseHas('users', [
            'email' => 'boss@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_the_sixth_failed_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401)
                ->assertJsonPath('code', 'invalid_credentials');
        }

        $this->postJson('/api/v1/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $response = $this->postJson('/api/v1/signup', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertCreated();

        $token = $response->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->flushAuth();

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    private function flushAuth(): void
    {
        Auth::forgetGuards();
    }

    /**
     * @return array{name: string, email: string, password: string, role: string}
     */
    private function staffPayload(string $email, string $role): array
    {
        return [
            'name' => 'Sam Staff',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
        ];
    }
}
