<?php

namespace Tests\Feature;

use App\Models\CvAsset;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_put_with_industry_and_no_cv_leaves_completeness_false(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['industry' => 'IT'])
            ->assertOk()
            ->assertJsonPath('industry', 'IT')
            ->assertJsonPath('complete', false)
            ->assertJsonPath('cv', null);
    }

    public function test_cv_upload_without_named_fields_leaves_completeness_false(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('complete', false)
            ->assertJsonPath('cv.original_name', 'cv.pdf')
            ->assertJsonPath('cv.mime', 'application/pdf');

        $this->assertDatabaseCount('cv_assets', 1);
    }

    public function test_pdf_cv_plus_all_four_fields_makes_completeness_true(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
            ])
            ->assertOk()
            ->assertJsonPath('complete', false);

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertExactJson([
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
                'complete' => true,
                'parse_status' => 'unparsed',
                'approval' => 'pending',
                'cv' => [
                    'original_name' => 'resume.pdf',
                    'mime' => 'application/pdf',
                    'size' => 200 * 1024,
                ],
            ]);
    }

    public function test_non_pdf_upload_is_422_and_stores_nothing(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('cv_assets', 0);
        Storage::disk(CvStorage::DISK)->assertDirectoryEmpty('cvs');
    }

    public function test_another_users_profile_by_id_is_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/profile/'.$owner->id)
            ->assertNotFound();
    }

    public function test_empty_language_on_complete_profile_is_422_and_old_language_remains(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

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
            ->assertCreated()
            ->assertJsonPath('complete', true);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['language' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('language', 'de')
            ->assertJsonPath('complete', true);
    }

    public function test_second_pdf_replaces_stored_asset_and_only_one_current_cv_remains(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $first = CvAsset::query()->where('user_id', $user->id)->firstOrFail();
        Storage::disk(CvStorage::DISK)->assertExists($first->path);

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('second.pdf', 150, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('cv.original_name', 'second.pdf');

        $this->assertDatabaseCount('cv_assets', 1);

        $second = CvAsset::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame($first->path, $second->path);
        Storage::disk(CvStorage::DISK)->assertMissing($first->path);
        Storage::disk(CvStorage::DISK)->assertExists($second->path);
    }

    public function test_staff_can_read_user_profile_fields_without_file_path(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();
        $staff = User::factory()->staff()->create();

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

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/profile')
            ->assertOk()
            ->assertExactJson([
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
                'complete' => true,
                'parse_status' => 'unparsed',
                'approval' => 'pending',
            ])
            ->assertJsonMissingPath('cv')
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('disk');
    }

    public function test_admin_can_read_user_profile(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['city' => 'Munich'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users/'.$user->id.'/profile')
            ->assertOk()
            ->assertJsonPath('city', 'Munich')
            ->assertJsonPath('complete', false);
    }

    public function test_user_cannot_read_another_users_profile_via_staff_route(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/users/'.$owner->id.'/profile')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_can_upload_cv_for_candidate_and_applicant_shows_has_cv(): void
    {
        Storage::fake(CvStorage::DISK);
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/users/'.$candidate->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertExactJson([
                'industry' => null,
                'city' => null,
                'province' => null,
                'language' => null,
                'complete' => false,
                'parse_status' => 'unparsed',
                'approval' => 'pending',
            ])
            ->assertJsonMissingPath('cv')
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('disk');

        $this->assertDatabaseCount('cv_assets', 1);
        $this->assertDatabaseHas('cv_assets', ['user_id' => $candidate->id]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('hasCv', true)
            ->assertJsonPath('profileComplete', false);
    }

    public function test_admin_can_upload_cv_for_candidate(): void
    {
        Storage::fake(CvStorage::DISK);
        $candidate = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/v1/users/'.$candidate->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('complete', false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/applicants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('hasCv', true);
    }

    public function test_candidate_cannot_upload_cv_via_staff_route(): void
    {
        Storage::fake(CvStorage::DISK);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->post('/api/v1/users/'.$owner->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseCount('cv_assets', 0);
    }

    public function test_staff_cannot_upload_cv_for_staff_or_admin_target(): void
    {
        Storage::fake(CvStorage::DISK);
        $staffActor = User::factory()->staff()->create();
        $staffTarget = User::factory()->staff()->create();
        $adminTarget = User::factory()->admin()->create();

        $this->actingAs($staffActor, 'sanctum')
            ->post('/api/v1/users/'.$staffTarget->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->actingAs($staffActor, 'sanctum')
            ->post('/api/v1/users/'.$adminTarget->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->assertDatabaseCount('cv_assets', 0);
    }

    public function test_staff_non_pdf_upload_for_candidate_is_422(): void
    {
        Storage::fake(CvStorage::DISK);
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/users/'.$candidate->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseCount('cv_assets', 0);
    }

    public function test_staff_second_pdf_replaces_candidate_cv_asset(): void
    {
        Storage::fake(CvStorage::DISK);
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/users/'.$candidate->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $first = CvAsset::query()->where('user_id', $candidate->id)->firstOrFail();
        Storage::disk(CvStorage::DISK)->assertExists($first->path);

        $this->actingAs($staff, 'sanctum')
            ->post('/api/v1/users/'.$candidate->id.'/profile/cv', [
                'cv' => UploadedFile::fake()->create('second.pdf', 150, 'application/pdf'),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('cv_assets', 1);

        $second = CvAsset::query()->where('user_id', $candidate->id)->firstOrFail();
        $this->assertNotSame($first->path, $second->path);
        Storage::disk(CvStorage::DISK)->assertMissing($first->path);
        Storage::disk(CvStorage::DISK)->assertExists($second->path);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('hasCv', true);
    }

    public function test_staff_can_update_candidate_profile_fields(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile', [
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
            ])
            ->assertOk()
            ->assertExactJson([
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
                'complete' => false,
                'parse_status' => 'unparsed',
                'approval' => 'pending',
            ])
            ->assertJsonMissingPath('cv')
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('disk')
            ->assertJsonMissingPath('password');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile')
            ->assertOk()
            ->assertJsonPath('industry', 'IT')
            ->assertJsonPath('city', 'Berlin')
            ->assertJsonPath('province', 'BE')
            ->assertJsonPath('language', 'de');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/applicants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('industry', 'IT')
            ->assertJsonPath('city', 'Berlin')
            ->assertJsonPath('province', 'BE')
            ->assertJsonPath('language', 'de');
    }

    public function test_admin_can_update_candidate_profile_fields(): void
    {
        $candidate = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile', [
                'city' => 'Munich',
            ])
            ->assertOk()
            ->assertJsonPath('city', 'Munich')
            ->assertJsonMissingPath('password');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/applicants/'.$candidate->id)
            ->assertOk()
            ->assertJsonPath('city', 'Munich');
    }

    public function test_staff_cannot_update_profile_for_staff_or_admin_target(): void
    {
        $staffActor = User::factory()->staff()->create();
        $staffTarget = User::factory()->staff()->create();
        $adminTarget = User::factory()->admin()->create();

        $this->actingAs($staffActor, 'sanctum')
            ->putJson('/api/v1/users/'.$staffTarget->id.'/profile', [
                'city' => 'Berlin',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->actingAs($staffActor, 'sanctum')
            ->putJson('/api/v1/users/'.$adminTarget->id.'/profile', [
                'city' => 'Berlin',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_staff_empty_language_on_candidate_profile_is_422_and_old_value_remains(): void
    {
        $candidate = User::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile', [
                'industry' => 'IT',
                'city' => 'Berlin',
                'province' => 'BE',
                'language' => 'de',
            ])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/users/'.$candidate->id.'/profile', ['language' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/users/'.$candidate->id.'/profile')
            ->assertOk()
            ->assertJsonPath('language', 'de');
    }

    public function test_candidate_cannot_update_profile_via_staff_route(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($intruder, 'sanctum')
            ->putJson('/api/v1/users/'.$owner->id.'/profile', [
                'city' => 'Berlin',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }
}
