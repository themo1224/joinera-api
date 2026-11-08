<?php

namespace Tests\Feature;

use App\Contracts\DomainRegistrar;
use App\Contracts\MailboxProvider;
use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use App\Models\User;
use App\Services\FakeDomainRegistrar;
use App\Services\FakeMailboxProvider;
use App\Support\ApplyAddressNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailboxProvider $fakeProvider;

    private FakeDomainRegistrar $fakeRegistrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider = new FakeMailboxProvider;
        $this->fakeRegistrar = new FakeDomainRegistrar($this->app->make(ApplyAddressNormalizer::class));
        $this->app->instance(MailboxProvider::class, $this->fakeProvider);
        $this->app->instance(DomainRegistrar::class, $this->fakeRegistrar);
    }

    public function test_post_while_provider_unset_returns_409_and_stores_nothing(): void
    {
        config([
            'joinera.mailbox.mode' => null,
            'joinera.mailbox.provider' => null,
            'joinera.mailbox.registrar' => null,
        ]);

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertStatus(409)
            ->assertJsonPath('code', 'mailbox_provider_unset');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_post_while_registrar_unset_returns_409_and_stores_nothing(): void
    {
        config([
            'joinera.mailbox.mode' => 'managed_provider',
            'joinera.mailbox.provider' => 'mailgun_eu',
            'joinera.mailbox.registrar' => null,
        ]);

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertStatus(409)
            ->assertJsonPath('code', 'domain_registrar_unset');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_gmail_or_user_owned_request_is_422_and_stores_nothing(): void
    {
        $this->configureMailbox();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox', [
                'mode' => 'gmail',
                'address' => 'anna@gmail.com',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'mailbox_mode_rejected');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_joinera_shared_domain_request_is_422_and_stores_nothing(): void
    {
        $this->configureMailbox();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox', [
                'domain' => 'apply.joinera.de',
                'address' => 'anna.schmidt@apply.joinera.de',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'mailbox_mode_rejected');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_two_users_cannot_store_the_same_address_or_domain(): void
    {
        $this->configureMailbox();

        $first = User::factory()->create(['name' => 'Anna Schmidt']);
        $second = User::factory()->create(['name' => 'Other Person']);

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('domain', 'anna-schmidt.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt.de');

        $this->fakeProvider->forceNextAddress('anna.schmidt@anna-schmidt.de');
        $this->fakeProvider->forceNextDomain('anna-schmidt.de');

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertStatus(409)
            ->assertJsonPath('code', 'mailbox_taken');

        $this->assertSame(1, MailboxIdentity::query()->where('address', 'anna.schmidt@anna-schmidt.de')->count());
        $this->assertSame(1, MailboxIdentity::query()->where('domain', 'anna-schmidt.de')->count());
    }

    public function test_owner_get_shows_domain_and_address_not_credential_or_message_body(): void
    {
        $this->configureMailbox();
        $this->fakeProvider->autoReady();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/mailbox')
            ->assertOk()
            ->assertJsonPath('domain', 'anna-schmidt.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt.de')
            ->assertJsonPath('state', 'ready');

        $payload = $response->json();
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('messages', $payload);
        $this->assertArrayNotHasKey('body', $payload);
    }

    public function test_another_users_mailbox_get_is_404(): void
    {
        $this->configureMailbox();
        $this->fakeProvider->autoReady();

        $owner = User::factory()->create(['name' => 'Anna Schmidt']);
        $other = User::factory()->create(['name' => 'Bob Example']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/mailbox')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_user_mailbox_messages_and_reply_routes_are_404(): void
    {
        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/mailbox/messages')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox/messages/1/reply', ['body' => 'hi'])
            ->assertNotFound();
    }

    public function test_staff_metadata_get_has_no_secret_and_no_message_body(): void
    {
        $this->configureMailbox();
        $this->fakeProvider->autoReady();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);
        $staff = User::factory()->staff()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated();

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/mailbox')
            ->assertOk()
            ->assertJsonPath('domain', 'anna-schmidt.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt.de')
            ->assertJsonPath('state', 'ready');

        $payload = $response->json();
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('messages', $payload);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertSame(['domain', 'address', 'state'], array_keys($payload));
    }

    public function test_umlaut_name_yields_personal_domain_address(): void
    {
        $this->configureMailbox();

        $user = User::factory()->create(['name' => 'Jörg Müller-Weiß']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('domain', 'joerg-mueller-weiss.de')
            ->assertJsonPath('address', 'joerg.mueller-weiss@joerg-mueller-weiss.de')
            ->assertJsonPath('state', 'pending');
    }

    public function test_second_anna_schmidt_gets_alternate_domain(): void
    {
        $this->configureMailbox();

        $first = User::factory()->create(['name' => 'Anna Schmidt']);
        $second = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('domain', 'anna-schmidt.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt.de');

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('domain', 'anna-schmidt2.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt2.de');
    }

    public function test_preferred_domain_taken_tries_alternate(): void
    {
        $this->configureMailbox();
        $this->fakeRegistrar->forceTaken('anna-schmidt.de');

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('domain', 'anna-schmidt2.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt2.de');
    }

    public function test_exhausted_domain_candidates_end_in_failed_without_joinera_subdomain(): void
    {
        $this->configureMailbox();
        $this->fakeRegistrar->exhaustAll();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('domain', 'anna-schmidt.de')
            ->assertJsonPath('address', 'anna.schmidt@anna-schmidt.de');

        $this->assertStringNotContainsString('joinera', (string) $response->json('domain'));
        $this->assertStringNotContainsString('joinera', (string) $response->json('address'));
    }

    public function test_missing_last_name_returns_422_name_required(): void
    {
        $this->configureMailbox();

        $user = User::factory()->create(['name' => 'Madonna']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertStatus(422)
            ->assertJsonPath('code', 'name_required');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_port_fake_can_move_pending_to_ready(): void
    {
        $this->configureMailbox();

        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox')
            ->assertCreated()
            ->assertJsonPath('state', 'pending');

        $mailbox = $user->fresh()->mailboxIdentity;
        $this->assertNotNull($mailbox);
        $this->assertSame(MailboxState::Pending, $mailbox->state);

        $this->fakeProvider->markReady($mailbox);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/mailbox')
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('domain', 'anna-schmidt.de');
    }

    private function configureMailbox(): void
    {
        config([
            'joinera.mailbox.mode' => 'managed_provider',
            'joinera.mailbox.provider' => 'mailgun_eu',
            'joinera.mailbox.registrar' => 'fake',
        ]);
    }
}
