<?php

namespace Tests\Feature;

use App\Enums\MailboxMessageDirection;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Jobs\SyncGmxMailboxJob;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Models\User;
use App\Services\FakeMailboxInboundSync;
use App\Services\FakeMailboxOutboundMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailboxInboundSync $fakeInbound;

    private FakeMailboxOutboundMailer $fakeOutbound;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
        ]);

        $this->fakeInbound = $this->app->make(FakeMailboxInboundSync::class);
        $this->fakeOutbound = $this->app->make(FakeMailboxOutboundMailer::class);
    }

    public function test_candidate_self_post_mailbox_is_rejected(): void
    {
        $user = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/mailbox', [
                'address' => 'anna@gmx.de',
                'password' => 'secret',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'mailbox_self_provision_disabled');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_admin_attach_gmx_credentials_ready_and_hides_password(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create(['name' => 'Anna Schmidt']);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna.schmidt@gmx.de',
                'password' => 'gmx-secret-pass',
            ])
            ->assertOk()
            ->assertJsonPath('address', 'anna.schmidt@gmx.de')
            ->assertJsonPath('domain', 'gmx.de')
            ->assertJsonPath('state', 'ready');

        $payload = $response->json();
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('secret', $payload);

        $mailbox = MailboxIdentity::query()->where('user_id', $candidate->id)->first();
        $this->assertNotNull($mailbox);
        $this->assertSame('gmx-secret-pass', $mailbox->password);
        $this->assertSame(MailboxState::Ready, $mailbox->state);
    }

    public function test_attach_auth_failure_sets_failed_and_returns_422(): void
    {
        $this->fakeInbound->failAuth();

        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create(['name' => 'Anna Schmidt']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna.schmidt@gmx.de',
                'password' => 'bad-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'gmx_auth_failed')
            ->assertJsonPath('state', 'failed')
            ->assertJsonPath('lastSyncError', 'gmx_auth_failed');

        $this->assertDatabaseHas('mailbox_identities', [
            'user_id' => $candidate->id,
            'address' => 'anna.schmidt@gmx.de',
            'state' => MailboxState::Failed->value,
            'last_sync_error' => 'gmx_auth_failed',
        ]);
    }

    public function test_attach_rejects_non_gmx_host(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna@gmail.com',
                'password' => 'secret',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_gmx_address');

        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_attach_rejects_taken_address(): void
    {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        MailboxIdentity::factory()->for($first)->ready()->create([
            'address' => 'shared@gmx.de',
            'domain' => 'gmx.de',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$second->id.'/mailbox/credentials', [
                'address' => 'shared@gmx.de',
                'password' => 'secret',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'mailbox_taken');
    }

    public function test_reattach_overwrites_password_and_re_verifies(): void
    {
        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna@gmx.net',
                'password' => 'first-pass',
            ])
            ->assertOk()
            ->assertJsonPath('domain', 'gmx.net');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna@gmx.net',
                'password' => 'second-pass',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'ready');

        $mailbox = $candidate->fresh()->mailboxIdentity;
        $this->assertNotNull($mailbox);
        $this->assertSame('second-pass', $mailbox->password);
        $this->assertSame(1, MailboxIdentity::query()->where('user_id', $candidate->id)->count());
    }

    public function test_staff_can_attach_is_forbidden_admin_only(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna@gmx.de',
                'password' => 'secret',
            ])
            ->assertForbidden();
    }

    public function test_staff_mailbox_show_has_sync_fields_without_password(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        MailboxIdentity::factory()->for($candidate)->ready()->create([
            'address' => 'cara@gmx.de',
            'domain' => 'gmx.de',
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/mailbox')
            ->assertOk()
            ->assertJsonPath('address', 'cara@gmx.de')
            ->assertJsonPath('domain', 'gmx.de')
            ->assertJsonPath('state', 'ready');

        $payload = $response->json();
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayHasKey('lastSyncedAt', $payload);
        $this->assertArrayHasKey('lastSyncError', $payload);
    }

    public function test_staff_lists_and_replies_to_messages(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        $mailbox = MailboxIdentity::factory()->for($candidate)->ready()->create([
            'address' => 'anna@gmx.de',
            'domain' => 'gmx.de',
        ]);

        $inbound = MailboxMessage::query()->create([
            'mailbox_identity_id' => $mailbox->id,
            'provider_message_id' => '<inbound-1@employer.test>',
            'direction' => MailboxMessageDirection::Inbound,
            'from_address' => 'hr@employer.test',
            'to_address' => 'anna@gmx.de',
            'subject' => 'Interview',
            'body_text' => 'Can you interview next week?',
            'received_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/mailbox/messages')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('messages.0.id', $inbound->id)
            ->assertJsonPath('messages.0.subject', 'Interview');

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$candidate->id.'/mailbox/messages/'.$inbound->id.'/reply', [
                'body' => 'Yes, Tuesday works.',
            ])
            ->assertCreated()
            ->assertJsonPath('direction', 'outbound')
            ->assertJsonPath('bodyText', 'Yes, Tuesday works.')
            ->assertJsonPath('inReplyToMessageId', $inbound->id);

        $this->assertCount(1, $this->fakeOutbound->sent());
        $this->assertDatabaseHas('mailbox_messages', [
            'mailbox_identity_id' => $mailbox->id,
            'direction' => MailboxMessageDirection::Outbound->value,
            'body_text' => 'Yes, Tuesday works.',
            'in_reply_to_message_id' => $inbound->id,
        ]);
    }

    public function test_candidate_cannot_list_or_reply_staff_message_routes(): void
    {
        $candidate = User::factory()->create();
        $mailbox = MailboxIdentity::factory()->for($candidate)->ready()->create([
            'address' => 'anna@gmx.de',
            'domain' => 'gmx.de',
        ]);
        $inbound = MailboxMessage::query()->create([
            'mailbox_identity_id' => $mailbox->id,
            'provider_message_id' => '<inbound-2@employer.test>',
            'direction' => MailboxMessageDirection::Inbound,
            'from_address' => 'hr@employer.test',
            'to_address' => 'anna@gmx.de',
            'subject' => 'Hello',
            'body_text' => 'Hi',
            'received_at' => now(),
        ]);

        $this->actingAs($candidate, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/mailbox/messages')
            ->assertForbidden();

        $this->actingAs($candidate, 'sanctum')
            ->postJson('/api/v1/users/'.$candidate->id.'/mailbox/messages/'.$inbound->id.'/reply', [
                'body' => 'nope',
            ])
            ->assertForbidden();
    }

    public function test_sync_job_upserts_inbound_idempotently_and_auth_fail_marks_failed(): void
    {
        $candidate = User::factory()->create();
        $mailbox = MailboxIdentity::factory()->for($candidate)->ready()->create([
            'address' => 'anna@gmx.de',
            'domain' => 'gmx.de',
            'password' => 'ok',
        ]);

        $payload = [
            'provider_message_id' => '<dup@employer.test>',
            'from_address' => 'hr@employer.test',
            'to_address' => 'anna@gmx.de',
            'subject' => 'Offer',
            'body_text' => 'Congrats',
        ];

        $this->fakeInbound->queueInbound('anna@gmx.de', $payload);
        (new SyncGmxMailboxJob($mailbox->id))->handle($this->fakeInbound);
        $this->assertSame(1, MailboxMessage::query()->where('mailbox_identity_id', $mailbox->id)->count());

        $this->fakeInbound->queueInbound('anna@gmx.de', $payload);
        (new SyncGmxMailboxJob($mailbox->id))->handle($this->fakeInbound);
        $this->assertSame(1, MailboxMessage::query()->where('mailbox_identity_id', $mailbox->id)->count());

        $this->fakeInbound->failAuth();
        (new SyncGmxMailboxJob($mailbox->id))->handle($this->fakeInbound);

        $mailbox->refresh();
        $this->assertSame(MailboxState::Failed, $mailbox->state);
        $this->assertSame('gmx_auth_failed', $mailbox->last_sync_error);
    }

    public function test_two_candidates_may_share_gmx_de_domain_but_not_address(): void
    {
        $admin = User::factory()->admin()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$first->id.'/mailbox/credentials', [
                'address' => 'one@gmx.de',
                'password' => 'secret1',
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$second->id.'/mailbox/credentials', [
                'address' => 'two@gmx.de',
                'password' => 'secret2',
            ])
            ->assertOk()
            ->assertJsonPath('domain', 'gmx.de');

        $this->assertSame(2, MailboxIdentity::query()->where('domain', 'gmx.de')->count());
        $this->assertSame(Role::User, $first->fresh()->role);
    }

    public function test_sync_all_job_dispatches_per_ready_mailbox(): void
    {
        Queue::fake();

        $ready = MailboxIdentity::factory()->ready()->create([
            'address' => 'ready@gmx.de',
            'domain' => 'gmx.de',
        ]);
        MailboxIdentity::factory()->pending()->create([
            'address' => 'pending@gmx.de',
            'domain' => 'gmx.de',
        ]);

        (new \App\Jobs\SyncAllReadyGmxMailboxesJob)->handle();

        Queue::assertPushed(SyncGmxMailboxJob::class, function (SyncGmxMailboxJob $job) use ($ready): bool {
            return $job->mailboxIdentityId === $ready->id;
        });
    }

    public function test_staff_sync_now_upserts_and_returns_count(): void
    {
        $staff = User::factory()->staff()->create();
        $candidate = User::factory()->create();
        MailboxIdentity::factory()->for($candidate)->ready()->create([
            'address' => 'anna@gmx.de',
            'domain' => 'gmx.de',
            'password' => 'ok',
            'last_sync_error' => 'gmx_sync_failed',
        ]);

        $this->fakeInbound->queueInbound('anna@gmx.de', [
            'provider_message_id' => '<sync-now@employer.test>',
            'from_address' => 'hr@employer.test',
            'to_address' => 'anna@gmx.de',
            'subject' => 'Synced',
            'body_text' => 'Hello from sync now',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$candidate->id.'/mailbox/sync')
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('upserted', 1)
            ->assertJsonPath('lastSyncError', null);

        $this->assertDatabaseHas('mailbox_messages', [
            'provider_message_id' => '<sync-now@employer.test>',
            'subject' => 'Synced',
        ]);
    }

    public function test_attach_dispatches_sync_job_when_ready(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $candidate = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/mailbox/credentials', [
                'address' => 'anna@gmx.de',
                'password' => 'secret',
            ])
            ->assertOk();

        $mailbox = MailboxIdentity::query()->where('user_id', $candidate->id)->first();
        $this->assertNotNull($mailbox);

        Queue::assertPushed(SyncGmxMailboxJob::class, function (SyncGmxMailboxJob $job) use ($mailbox): bool {
            return $job->mailboxIdentityId === $mailbox->id;
        });
    }
}
