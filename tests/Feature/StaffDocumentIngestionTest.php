<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\CvStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffDocumentIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_parse_uploaded_cv_with_fixture_parser(): void
    {
        Storage::fake(CvStorage::DISK);
        $staff = User::factory()->create(['role' => Role::Staff]);
        $candidate = User::factory()->create(['role' => Role::User, 'name' => 'Test Candidate']);

        $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/users/{$candidate->id}/profile/cv", [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/users/{$candidate->id}/profile/parse")
            ->assertOk()
            ->assertJsonPath('industry', ['IT'])
            ->assertJsonPath('language', 'de')
            ->assertJsonPath('parse_status', 'parsed');
    }

    public function test_staff_can_upload_passport_document_slot(): void
    {
        Storage::fake(CvStorage::DISK);
        Storage::fake('local');
        $staff = User::factory()->create(['role' => Role::Staff]);
        $candidate = User::factory()->create(['role' => Role::User, 'name' => 'Chaimae Modden Malki']);

        $response = $this->actingAs($staff, 'sanctum')
            ->post("/api/v1/users/{$candidate->id}/profile/documents", [
                'kind' => 'passport',
                'document' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('uploaded_document.kind', 'passport');

        $this->assertDatabaseHas('profile_document_assets', [
            'user_id' => $candidate->id,
            'kind' => 'passport',
        ]);
    }

    public function test_staff_can_patch_date_of_birth(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $candidate = User::factory()->create(['role' => Role::User]);
        $candidate->ensureProfile();

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/users/{$candidate->id}/profile", [
                'date_of_birth' => '1989-02-08',
                'industry' => ['IT'],
                'language' => 'de',
                'desired_path' => 'job',
            ])
            ->assertOk()
            ->assertJsonPath('date_of_birth', '1989-02-08');
    }

    public function test_analyze_documents_requires_all_four_slots(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);
        $candidate = User::factory()->create(['role' => Role::User, 'name' => 'Incomplete Docs']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/users/{$candidate->id}/profile/analyze-documents")
            ->assertStatus(422)
            ->assertJsonPath('code', 'documents_incomplete');
    }
}
