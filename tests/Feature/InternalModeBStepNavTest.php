<?php

namespace Tests\Feature;

use App\Contracts\LlmClient;
use App\Services\Ai\FakeLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalModeBStepNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'joinera.mode_b.worker_secret' => 'worker-secret',
            'joinera.mode_b.ai_step_nav.enabled' => true,
            'joinera.mode_b.ai_step_nav.min_confidence' => 0.75,
        ]);
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        $this->postJson('/api/v1/internal/mode-b/step-nav', [
            'snapshot' => $this->sampleSnapshot(),
        ])->assertForbidden();

        $this->withHeader('X-Mode-B-Worker-Secret', 'wrong')
            ->postJson('/api/v1/internal/mode-b/step-nav', [
                'snapshot' => $this->sampleSnapshot(),
            ])
            ->assertForbidden();
    }

    public function test_disabled_flag_returns_none_without_llm_call(): void
    {
        config(['joinera.mode_b.ai_step_nav.enabled' => false]);

        // Do not queue FakeLlm content — disabled path must short-circuit before chat().
        $response = $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->postJson('/api/v1/internal/mode-b/step-nav', [
                'snapshot' => $this->sampleSnapshot(),
            ])
            ->assertOk()
            ->assertJsonPath('action', 'none')
            ->assertJsonPath('source', 'disabled');

        $this->assertNull($response->json('confidence'));
    }

    public function test_fake_llm_success_returns_next(): void
    {
        $fake = new FakeLlmClient;
        $fake->queueContent(json_encode([
            'action' => 'next',
            'confidence' => 0.86,
            'control_id' => 'c0',
            'click' => [
                'strategy' => 'text',
                'text' => 'Add application documents',
            ],
            'reason' => 'documents step CTA',
        ], JSON_THROW_ON_ERROR));
        $this->app->instance(LlmClient::class, $fake);

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->postJson('/api/v1/internal/mode-b/step-nav', [
                'snapshot' => $this->sampleSnapshot(),
            ])
            ->assertOk()
            ->assertJsonPath('action', 'next')
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('control_id', 'c0')
            ->assertJsonPath('confidence', 0.86);
    }

    public function test_low_confidence_and_malformed_refuse(): void
    {
        $fake = new FakeLlmClient;
        $fake->queueContent('{"action":"next","confidence":0.2,"control_id":"c0"}');
        $fake->queueContent('not-json-at-all');
        $this->app->instance(LlmClient::class, $fake);

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->postJson('/api/v1/internal/mode-b/step-nav', [
                'snapshot' => $this->sampleSnapshot(),
            ])
            ->assertOk()
            ->assertJsonPath('action', 'none')
            ->assertJsonPath('source', 'ai_refuse');

        $this->withHeader('X-Mode-B-Worker-Secret', 'worker-secret')
            ->postJson('/api/v1/internal/mode-b/step-nav', [
                'snapshot' => $this->sampleSnapshot(),
            ])
            ->assertOk()
            ->assertJsonPath('action', 'none')
            ->assertJsonPath('source', 'ai_refuse');
    }

    public function test_llm_client_is_fake_in_tests(): void
    {
        $this->assertInstanceOf(FakeLlmClient::class, app(LlmClient::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleSnapshot(): array
    {
        return [
            'schema_version' => 1,
            'page' => [
                'url_host' => 'jobs.example.com',
                'url_path' => '/apply/1',
                'title' => 'Apply',
            ],
            'step_index' => 1,
            'controls' => [
                [
                    'id' => 'c0',
                    'tag' => 'button',
                    'role' => 'button',
                    'type' => 'button',
                    'testid' => null,
                    'name_attr' => null,
                    'text' => 'Add application documents',
                    'aria_label' => null,
                    'classes' => [],
                    'disabled' => false,
                    'visible' => true,
                ],
            ],
            'hints' => [
                'filled_field_count' => 3,
                'has_file_input' => true,
            ],
        ];
    }
}
