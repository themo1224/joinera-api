<?php

namespace Tests\Unit;

use App\Enums\DocumentAssetKind;
use App\Enums\Role;
use App\Exceptions\DocumentsIncompleteException;
use App\Exceptions\LlmRequestException;
use App\Models\CvAsset;
use App\Models\ProfileDocumentAsset;
use App\Models\User;
use App\Services\Ai\AiReadiness;
use App\Services\Ai\FakeLlmClient;
use App\Services\AnalyzeApplicantDocumentsWithAi;
use App\Services\Documents\IdentityMismatchGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyzeApplicantDocumentsWithAiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fills_profile_from_queued_llm_json(): void
    {
        Storage::fake('local');
        $user = $this->makeApplicantWithFourDocs('Othmane El Badaoui');

        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode([
            'suggested_name' => 'Othmane El Badaoui',
            'date_of_birth' => '1998-04-12',
            'education' => 'Fachhochschulreife',
            'languages' => [
                ['language' => 'Deutsch', 'level' => 'B2'],
                ['language' => 'Englisch', 'level' => 'B1'],
            ],
            'experiences' => [
                ['title' => 'Koch', 'organization' => 'Hotel Beispiel', 'current' => false],
            ],
            'education_entries' => [
                ['level' => 'Fachhochschulreife', 'status' => 'completed'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $service = new AnalyzeApplicantDocumentsWithAi($fake, new IdentityMismatchGuard, new AiReadiness);
        $result = $service->handle($user);

        $this->assertSame('ran', $result['ingestion']->meta['ai_status'] ?? null);
        $this->assertFalse($result['result']->autoApplyBlocked);
        $profile = $result['profile']->fresh();
        $this->assertSame('1998-04-12', $profile->date_of_birth?->format('Y-m-d'));
        $this->assertSame('Fachhochschulreife', $profile->education);
        $this->assertNotEmpty($profile->experiences);
        $languages = collect($profile->languages)->pluck('level', 'language')->all();
        $this->assertSame('B2', $languages['Deutsch'] ?? null);
        $this->assertSame('B1', $languages['Englisch'] ?? null);
        $this->assertSame('Othmane El Badaoui', $user->fresh()->name);
    }

    #[Test]
    public function it_rejects_incomplete_document_set(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => Role::User, 'name' => 'Incomplete']);
        $user->ensureProfile();

        $service = new AnalyzeApplicantDocumentsWithAi(new FakeLlmClient, new IdentityMismatchGuard, new AiReadiness);

        $this->expectException(DocumentsIncompleteException::class);
        $service->handle($user);
    }

    #[Test]
    public function it_does_not_invent_fields_when_llm_fails(): void
    {
        Storage::fake('local');
        $user = $this->makeApplicantWithFourDocs('Anna Schmidt');

        $throwing = new class extends FakeLlmClient
        {
            public function chat(array $messages, array $options = []): \App\Support\LlmChatResult
            {
                throw new LlmRequestException('forced', 500);
            }
        };

        $service = new AnalyzeApplicantDocumentsWithAi($throwing, new IdentityMismatchGuard, new AiReadiness);

        try {
            $service->handle($user);
            $this->fail('Expected LlmRequestException');
        } catch (LlmRequestException) {
            $profile = $user->ensureProfile()->fresh();
            $this->assertTrue($profile->education === null || $profile->education === '');
            $this->assertTrue(($profile->experiences ?? []) === [] || $profile->experiences === null);
            $this->assertTrue(($profile->languages ?? []) === [] || $profile->languages === null);
        }
    }

    private function makeApplicantWithFourDocs(string $name): User
    {
        $user = User::factory()->create(['role' => Role::User, 'name' => $name]);
        $user->ensureProfile();

        $cvPath = "cvs/{$user->id}/cv.pdf";
        Storage::disk('local')->put($cvPath, '%PDF-1.4 cv');
        CvAsset::query()->create([
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $cvPath,
            'original_name' => 'cv.pdf',
            'mime' => 'application/pdf',
            'size' => 12,
        ]);

        foreach ([
            DocumentAssetKind::Passport,
            DocumentAssetKind::LanguageCert,
            DocumentAssetKind::EducationCert,
        ] as $kind) {
            $path = "profile-docs/{$user->id}/{$kind->value}/doc.pdf";
            Storage::disk('local')->put($path, '%PDF-1.4 '.$kind->value);
            ProfileDocumentAsset::query()->create([
                'user_id' => $user->id,
                'kind' => $kind,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $kind->value.'.pdf',
                'mime' => 'application/pdf',
                'size' => 20,
            ]);
        }

        return $user->fresh(['cvAsset', 'profileDocumentAssets']);
    }
}
