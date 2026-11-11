<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\MailboxMessageDirection;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Models\MailboxIdentity;
use App\Models\MailboxMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mailbox.mode' => 'gmx_manual',
            'joinera.mailbox.driver' => 'fake',
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
        ]);
    }

    public function test_staff_can_list_applicants_only(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create(['name' => 'Anna Schmidt', 'email' => 'anna@example.test']);
        $applicant->ensureProfile();
        User::factory()->admin()->create(['email' => 'other-admin@example.test']);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants')
            ->assertOk()
            ->assertJsonPath('total_users', 1)
            ->assertJsonPath('users.0.id', $applicant->id)
            ->assertJsonPath('users.0.email', 'anna@example.test')
            ->assertJsonPath('users.0.approval', 'pending')
            ->assertJsonPath('users.0.mailboxState', 'pending');
    }

    public function test_user_cannot_list_applicants(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/applicants')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_admin_creates_applicant_without_mailbox(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'Mahdi Mahboobi',
                'email' => 'mahdi.personal@gmail.com',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Mahdi Mahboobi')
            ->assertJsonPath('email', 'mahdi.personal@gmail.com')
            ->assertJsonPath('approval', 'pending')
            ->assertJsonPath('mailboxState', 'pending')
            ->assertJsonPath('mailboxDomain', null)
            ->assertJsonPath('mailboxAddress', null)
            ->assertJsonMissingPath('password');

        $id = $response->json('id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas('users', [
            'id' => $id,
            'email' => 'mahdi.personal@gmail.com',
            'role' => Role::User->value,
        ]);
        $created = User::query()->findOrFail($id);
        $this->assertNotSame('', (string) $created->password);
        $this->assertDatabaseCount('mailbox_identities', 0);
    }

    public function test_admin_creates_applicant_with_name_only(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'No Contact Yet',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'No Contact Yet')
            ->assertJsonPath('email', null)
            ->assertJsonPath('mailboxState', 'pending')
            ->assertJsonMissingPath('password');

        $id = $response->json('id');
        $this->assertDatabaseHas('users', [
            'id' => $id,
            'email' => null,
            'role' => Role::User->value,
        ]);
    }

    public function test_create_rejects_admin_supplied_password(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'Should Fail',
                'email' => 'should.fail@example.test',
                'password' => 'password123',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'should.fail@example.test']);
    }

    public function test_staff_cannot_create_applicant(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'Mahdi Mahboobi',
                'email' => 'mahdi.personal@gmail.com',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseMissing('users', ['email' => 'mahdi.personal@gmail.com']);
    }

    public function test_create_allows_single_display_name(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'Mahboobi',
                'email' => 'customer@gmail.com',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Mahboobi')
            ->assertJsonPath('email', 'customer@gmail.com')
            ->assertJsonPath('mailboxState', 'pending');
    }

    public function test_admin_attaches_mailbox_after_create(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/applicants', [
                'name' => 'Mahdi Mahboobi',
                'email' => 'mahdi.personal@gmail.com',
            ])
            ->assertCreated()
            ->json('id');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$id.'/mailbox/credentials', [
                'address' => 'mail.mahboobi@gmx.de',
                'password' => 'gmx-pass',
            ])
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('address', 'mail.mahboobi@gmx.de');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/applicants/'.$id)
            ->assertOk()
            ->assertJsonPath('mailboxState', 'ready')
            ->assertJsonPath('mailboxDomain', 'gmx.de')
            ->assertJsonPath('mailboxAddress', 'mail.mahboobi@gmx.de');
    }

    public function test_staff_can_show_applicant_and_not_staff_user(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->create(['name' => 'Anna Schmidt']);
        $applicant->ensureProfile();

        MailboxIdentity::factory()->for($applicant)->ready()->create([
            'domain' => 'gmx.de',
            'address' => 'anna.schmidt@gmx.de',
            'state' => MailboxState::Ready,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('id', $applicant->id)
            ->assertJsonPath('mailboxState', 'ready')
            ->assertJsonPath('mailboxDomain', 'gmx.de');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$admin->id)
            ->assertNotFound();
    }

    public function test_staff_can_approve_and_list_reflects_approval(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create(['name' => 'Ben Weber']);
        $profile = $applicant->ensureProfile();
        $this->assertTrue(
            $profile->approval === null || $profile->approval === ApprovalStatus::Pending
        );

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$applicant->id.'/profile/approvals')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('approval', 'approved')
            ->assertJsonPath('approvedById', (string) $staff->id)
            ->assertJsonPath('approvedByEmail', $staff->email);
    }

    public function test_staff_can_read_mailbox_for_applicant(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create(['name' => 'Cara Mueller']);
        MailboxIdentity::factory()->for($applicant)->ready()->create([
            'domain' => 'gmx.de',
            'address' => 'cara.mueller@gmx.de',
            'state' => MailboxState::Ready,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$applicant->id.'/mailbox')
            ->assertOk()
            ->assertJsonPath('domain', 'gmx.de')
            ->assertJsonPath('address', 'cara.mueller@gmx.de')
            ->assertJsonPath('state', 'ready');
    }

    public function test_staff_or_admin_delete_candidate_clears_mailbox_and_messages(): void
    {
        $staff = User::factory()->staff()->create();
        $applicant = User::factory()->create(['name' => 'Delete Me']);
        $applicant->ensureProfile();

        $mailbox = MailboxIdentity::factory()->for($applicant)->ready()->create([
            'address' => 'delete.me@gmx.de',
            'domain' => 'gmx.de',
            'password' => 'gmx-secret',
        ]);

        MailboxMessage::query()->create([
            'mailbox_identity_id' => $mailbox->id,
            'provider_message_id' => '<del@employer.test>',
            'direction' => MailboxMessageDirection::Inbound,
            'from_address' => 'hr@employer.test',
            'to_address' => 'delete.me@gmx.de',
            'subject' => 'Bye',
            'body_text' => 'body',
            'received_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->deleteJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $applicant->id]);
        $this->assertDatabaseMissing('mailbox_identities', ['id' => $mailbox->id]);
        $this->assertDatabaseCount('mailbox_messages', 0);
    }

    public function test_delete_does_not_call_gmx_and_frees_address_for_reuse(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->create();
        $applicant->ensureProfile();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$applicant->id.'/mailbox/credentials', [
                'address' => 'reuse@gmx.de',
                'password' => 'secret',
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/applicants/'.$applicant->id)
            ->assertOk();

        $replacement = User::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$replacement->id.'/mailbox/credentials', [
                'address' => 'reuse@gmx.de',
                'password' => 'secret',
            ])
            ->assertOk()
            ->assertJsonPath('address', 'reuse@gmx.de');
    }
}
