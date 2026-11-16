<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocArtifactKind;
use App\Models\Application;
use App\Models\DocArtifact;
use App\Models\Listing;
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
            ->assertJsonPath('site_login', 'cand@azubiyo.test');

        $this->assertIsString($response->json('artifacts.cv.url'));

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->get('/api/v1/internal/mode-b/applications/'.$application->id.'/artifacts/cv')
            ->assertOk();
    }

    private function makeApplicationWithArtifacts(): Application
    {
        $user = User::factory()->create();
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
