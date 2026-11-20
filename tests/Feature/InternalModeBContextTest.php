<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Models\Listing;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\SiteIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InternalModeBContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'joinera.mode_b.worker_secret' => 'worker-secret',
            'joinera.docs.disk' => 'local',
        ]);
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        $application = $this->makeApplicationWithArtifacts();

        $this->getJson('/api/v1/internal/mode-b/applications/'.$application->id)
            ->assertForbidden();

        $this->withHeader('X-Mode-B-Worker-Secret', 'wrong')
            ->getJson('/api/v1/internal/mode-b/applications/'.$application->id)
            ->assertForbidden();
    }

    public function test_returns_context_and_artifact_bytes(): void
    {
        $application = $this->makeApplicationWithArtifacts();

        $response = $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->getJson('/api/v1/internal/mode-b/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('user_id', $application->user_id)
            ->assertJsonPath('site_id', 'azubiyo')
            ->assertJsonPath('application_id', $application->id)
            ->assertJsonPath('site_login', 'cand@azubiyo.test')
            ->assertJsonPath('apply_url', 'https://www.azubiyo.de/stellen/1/bewerben')
            ->assertJsonPath('apply_target', 'board')
            ->assertJsonPath('listing_url', 'https://www.azubiyo.de/stellen/1')
            ->assertJsonPath('candidate.name', 'Ada Candidate')
            ->assertJsonPath('candidate.apply_email', 'ada.apply@gmx.de')
            ->assertJsonPath('candidate.date_of_birth', '1998-05-12')
            ->assertJsonPath('candidate.city', ['Berlin'])
            ->assertJsonPath('candidate.education', 'Mittlere Reife')
            ->assertJsonPath('candidate.desired_path', 'ausbildung')
            ->assertJsonPath('candidate.willing_to_relocate', true);

        $this->assertIsString($response->json('artifacts.cv.url'));
        $this->assertIsString($response->json('artifacts.cover.url'));
        $this->assertIsArray($response->json('candidate.skills'));
        $this->assertSame(['Deutsch'], $response->json('candidate.languages'));
        $this->assertArrayNotHasKey('password', $response->json('candidate'));
        $json = $response->json();
        $this->assertStringNotContainsString('secret-mailbox-password', json_encode($json) ?: '');

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->get('/api/v1/internal/mode-b/applications/'.$application->id.'/artifacts/cv')
            ->assertOk();

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->get('/api/v1/internal/mode-b/applications/'.$application->id.'/artifacts/cover')
            ->assertOk();
    }

    public function test_candidate_pack_is_sparse_when_profile_and_mailbox_missing(): void
    {
        $user = User::factory()->create(['name' => 'Sparse User']);
        $listing = Listing::factory()->forSource('azubiyo')->create([
            'url' => 'https://www.azubiyo.de/stellen/sparse',
            'apply_url' => 'https://karriere.firma.example.test/apply',
        ]);

        $application = Application::factory()
            ->for($user)
            ->for($listing)
            ->create([
                'site' => 'azubiyo',
                'status' => ApplicationStatus::Applying,
            ]);

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->getJson('/api/v1/internal/mode-b/applications/'.$application->id)
            ->assertOk()
            ->assertJsonPath('candidate.name', 'Sparse User')
            ->assertJsonPath('candidate.apply_email', null)
            ->assertJsonPath('candidate.date_of_birth', null)
            ->assertJsonPath('candidate.city', [])
            ->assertJsonPath('candidate.province', [])
            ->assertJsonPath('candidate.education_entries', [])
            ->assertJsonPath('candidate.experiences', [])
            ->assertJsonPath('candidate.skills', [])
            ->assertJsonPath('candidate.languages', [])
            ->assertJsonPath('artifacts.cv', null)
            ->assertJsonPath('artifacts.cover', null)
            ->assertJsonPath('apply_url', 'https://karriere.firma.example.test/apply')
            ->assertJsonPath('apply_target', 'company');
    }

    private function makeApplicationWithArtifacts(): Application
    {
        $user = User::factory()->create(['name' => 'Ada Candidate']);

        Profile::factory()->for($user)->create([
            'city' => ['Berlin'],
            'province' => ['BE'],
            'language' => 'de',
            'education' => 'Mittlere Reife',
            'desired_path' => 'ausbildung',
            'preferred_occupation' => 'MFA',
            'available_from' => '2026-08-01',
            'willing_to_relocate' => true,
            'about' => 'Motiviert und zuverlässig.',
            'work_residence_note' => 'Aufenthaltstitel vorhanden',
            'date_of_birth' => '1998-05-12',
            'driving_licenses' => ['B'],
            'education_entries' => [
                ['level' => 'Mittlere Reife', 'institution' => 'Schule Berlin'],
            ],
            'experiences' => [
                ['title' => 'Praktikantin', 'company' => 'Klinik'],
            ],
            'skills' => ['MS Office'],
            'languages' => ['Deutsch'],
        ]);

        MailboxIdentity::factory()->ready()->for($user)->create([
            'address' => 'ada.apply@gmx.de',
            'password' => 'secret-mailbox-password',
        ]);

        $listing = Listing::factory()->forSource('azubiyo')->create([
            'url' => 'https://www.azubiyo.de/stellen/1',
            'apply_url' => 'https://www.azubiyo.de/stellen/1/bewerben',
        ]);

        SiteIdentity::factory()->for($user)->healthy()->create([
            'site' => 'azubiyo',
            'site_login' => 'cand@azubiyo.test',
            'secret' => 'plain-secret',
            'paused' => false,
        ]);

        $application = Application::factory()
            ->for($user)
            ->for($listing)
            ->create([
                'site' => 'azubiyo',
                'status' => ApplicationStatus::Applying,
            ]);

        foreach ([DocArtifactKind::Cv, DocArtifactKind::Cover] as $kind) {
            $path = 'docs/'.$application->id.'/'.$kind->value.'.pdf';
            Storage::disk('local')->put($path, "%PDF-1.4\n%".$kind->value."\n%%EOF\n");
            DocArtifact::factory()->create([
                'application_id' => $application->id,
                'kind' => $kind,
                'disk' => 'local',
                'path' => $path,
                'mime' => 'application/pdf',
                'size' => 24,
            ]);
        }

        return $application;
    }
}
