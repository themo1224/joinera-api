<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_complete_profile_reads_approval_pending(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->completeProfile($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('complete', true)
            ->assertJsonPath('approval', 'pending');
    }

    public function test_staff_approval_makes_get_return_approved(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->completeProfile($user);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');
    }

    public function test_later_put_of_city_leaves_approval_approved(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->completeProfile($user);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['city' => 'Munich'])
            ->assertOk()
            ->assertJsonPath('city', 'Munich')
            ->assertJsonPath('approval', 'approved');
    }

    public function test_user_cannot_approve_themselves(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->completeProfile($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('approval', 'pending');
    }

    public function test_second_staff_approval_stays_approved_without_pending_cycle(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $otherStaff = User::factory()->staff()->create();

        $this->completeProfile($user);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');

        $profile = $user->fresh()->profile;
        $approvedAt = $profile->approved_at?->toISOString();
        $approvedBy = $profile->approved_by;

        $this->actingAs($otherStaff, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');

        $profile->refresh();

        $this->assertSame(ApprovalStatus::Approved, $profile->approval);
        $this->assertSame($approvedBy, $profile->approved_by);
        $this->assertSame($approvedAt, $profile->approved_at?->toISOString());
    }

    public function test_admin_may_approve(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->completeProfile($user);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/users/'.$user->id.'/profile/approvals')
            ->assertOk()
            ->assertJsonPath('approval', 'approved');
    }

    public function test_user_calling_another_users_approval_route_is_403(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/users/'.$owner->id.'/profile/approvals')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    private function completeProfile(User $user): void
    {
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();
    }
}
