<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\AiUnavailableException;
use App\Models\User;
use App\Services\Ai\AiReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaffAiReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_readiness_returns_ready_when_check_passes(): void
    {
        $this->mock(AiReadiness::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andReturn(['ready' => true]);
        });

        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ai/readiness')
            ->assertOk()
            ->assertJsonPath('ready', true);
    }

    #[Test]
    public function staff_readiness_returns_structured_error_when_unavailable(): void
    {
        $this->mock(AiReadiness::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andReturn([
                'ready' => false,
                'code' => AiUnavailableException::CODE_NO_CREDIT,
                'message' => 'No AI credit remaining.',
            ]);
        });

        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/ai/readiness')
            ->assertStatus(503)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('code', 'ai_no_credit')
            ->assertJsonPath('message', 'No AI credit remaining.');
    }

    #[Test]
    public function analyze_documents_blocks_before_llm_when_ai_not_configured(): void
    {
        config([
            'joinera.ai.force_readiness_check' => true,
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => '',
        ]);

        Http::fake();

        $staff = User::factory()->create(['role' => Role::Staff]);
        $candidate = User::factory()->create(['role' => Role::User, 'name' => 'No Key Candidate']);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/users/{$candidate->id}/profile/analyze-documents")
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_not_configured');

        Http::assertNothingSent();
    }

    #[Test]
    public function candidate_cannot_read_ai_readiness(): void
    {
        $user = User::factory()->create(['role' => Role::User]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/ai/readiness')
            ->assertForbidden();
    }
}
