<?php

namespace Tests\Unit;

use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use App\Models\User;
use App\Support\ModeBCandidatePack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModeBCandidatePackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mailbox.allowed_hosts' => ['gmx.de', 'gmx.net'],
        ]);
    }

    public function test_ready_gmx_mailbox_sets_apply_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Candidate',
            'email' => 'ada.personal@gmail.com',
        ]);
        MailboxIdentity::factory()->ready()->for($user)->create([
            'address' => 'ada.apply@gmx.de',
            'domain' => 'gmx.de',
        ]);

        $pack = ModeBCandidatePack::fromUser($user->fresh(['mailboxIdentity', 'profile']) ?? $user);

        $this->assertSame('ada.apply@gmx.de', $pack['apply_email']);
        $this->assertNotSame($user->email, $pack['apply_email']);
    }

    public function test_pending_mailbox_yields_null_apply_email(): void
    {
        $user = User::factory()->create(['email' => 'ada.personal@gmail.com']);
        MailboxIdentity::factory()->pending()->for($user)->create([
            'address' => 'ada.apply@gmx.de',
            'domain' => 'gmx.de',
        ]);

        $pack = ModeBCandidatePack::fromUser($user->fresh(['mailboxIdentity']) ?? $user);

        $this->assertNull($pack['apply_email']);
    }

    public function test_ready_non_gmx_mailbox_yields_null_apply_email(): void
    {
        $user = User::factory()->create(['email' => 'ada.personal@gmail.com']);
        MailboxIdentity::factory()->for($user)->create([
            'state' => MailboxState::Ready,
            'address' => 'ada.personal@gmail.com',
            'domain' => 'gmail.com',
            'provider' => 'gmx',
        ]);

        $pack = ModeBCandidatePack::fromUser($user->fresh(['mailboxIdentity']) ?? $user);

        $this->assertNull($pack['apply_email']);
    }

    public function test_missing_mailbox_yields_null_apply_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Sparse User',
            'email' => 'ada.personal@gmail.com',
        ]);

        $pack = ModeBCandidatePack::fromUser($user);

        $this->assertNull($pack['apply_email']);
        $this->assertSame('Sparse User', $pack['name']);
        $this->assertNotSame($user->email, $pack['apply_email']);
    }
}
