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
}
