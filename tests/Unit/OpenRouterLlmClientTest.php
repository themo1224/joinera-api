<?php

namespace Tests\Unit;

use App\Contracts\LlmClient;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\LlmRequestException;
use App\Services\Ai\FakeLlmClient;
use App\Services\Ai\OpenRouterLlmClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterLlmClientTest extends TestCase
{
    public function test_container_binds_fake_llm_in_testing(): void
    {
        $this->assertInstanceOf(FakeLlmClient::class, app(LlmClient::class));
    }

    public function test_open_router_chat_sends_model_and_fallbacks(): void
    {
        config([
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'joinera.ai.openrouter.model' => 'openrouter/free',
            'joinera.ai.openrouter.fallback_models' => [
                'meta-llama/llama-3.2-3b-instruct:free',
            ],
            'joinera.ai.openrouter.app_title' => 'Joinera',
            'joinera.ai.openrouter.http_referer' => 'https://joinera.test',
            'joinera.ai.openrouter.timeout' => 10,
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-1',
                'model' => 'meta-llama/llama-3.2-3b-instruct:free',
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Sehr geehrte Damen und Herren,',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 8,
                    'total_tokens' => 20,
                ],
            ], 200),
        ]);

        $result = app(OpenRouterLlmClient::class)->chat([
            ['role' => 'user', 'content' => 'Write a short cover letter.'],
        ], ['temperature' => 0.2, 'max_tokens' => 200]);

        $this->assertSame('Sehr geehrte Damen und Herren,', $result->content);
        $this->assertSame('meta-llama/llama-3.2-3b-instruct:free', $result->model);
        $this->assertSame('stop', $result->finishReason);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('X-Title', 'Joinera')
                && $request->hasHeader('HTTP-Referer', 'https://joinera.test')
                && ($data['model'] ?? null) === 'openrouter/free'
                && ($data['models'] ?? null) === ['meta-llama/llama-3.2-3b-instruct:free']
                && ($data['temperature'] ?? null) === 0.2
                && ($data['max_tokens'] ?? null) === 200;
        });
    }

    public function test_open_router_chat_forwards_plugins_and_multimodal_content(): void
    {
        config([
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'joinera.ai.openrouter.model' => 'openai/gpt-4o-mini',
            'joinera.ai.openrouter.fallback_models' => [],
            'joinera.ai.openrouter.app_title' => 'Joinera',
            'joinera.ai.openrouter.timeout' => 10,
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-2',
                'model' => 'openai/gpt-4o-mini',
                'choices' => [
                    [
                        'finish_reason' => 'stop',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"suggested_name":"Ada"}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(OpenRouterLlmClient::class)->chat([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Extract fields'],
                    [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'cv.pdf',
                            'file_data' => 'data:application/pdf;base64,AAA',
                        ],
                    ],
                ],
            ],
        ], [
            'temperature' => 0,
            'plugins' => [
                ['id' => 'file-parser', 'pdf' => ['engine' => 'mistral-ocr']],
            ],
        ]);

        $this->assertSame('{"suggested_name":"Ada"}', $result->content);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $content = $data['messages'][0]['content'] ?? null;

            return is_array($content)
                && ($data['plugins'][0]['id'] ?? null) === 'file-parser'
                && ($data['plugins'][0]['pdf']['engine'] ?? null) === 'mistral-ocr';
        });
    }

    public function test_open_router_chat_throws_on_http_error(): void
    {
        config([
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'joinera.ai.openrouter.model' => 'openrouter/free',
            'joinera.ai.openrouter.fallback_models' => [],
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(LlmRequestException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        app(OpenRouterLlmClient::class)->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);
    }

    #[Test]
    public function test_open_router_chat_maps_402_to_no_credit(): void
    {
        config([
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'joinera.ai.openrouter.model' => 'openrouter/free',
            'joinera.ai.openrouter.fallback_models' => [],
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Insufficient credits',
                ],
            ], 402),
        ]);

        try {
            app(OpenRouterLlmClient::class)->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_NO_CREDIT, $e->errorCode);
        }
    }

    #[Test]
    public function test_open_router_chat_maps_missing_key_to_not_configured(): void
    {
        config([
            'joinera.ai.openrouter.api_key' => '',
            'joinera.ai.openrouter.model' => 'openrouter/free',
        ]);

        Http::fake();

        try {
            app(OpenRouterLlmClient::class)->chat([
                ['role' => 'user', 'content' => 'hi'],
            ]);
            $this->fail('Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_NOT_CONFIGURED, $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_fake_llm_returns_deterministic_cover_stub(): void
    {
        $result = app(FakeLlmClient::class)->chat([
            ['role' => 'user', 'content' => 'IT Berlin nurse'],
        ]);

        $this->assertStringContainsString('FAKE_COVER', $result->content);
        $this->assertStringContainsString('IT Berlin nurse', $result->content);
        $this->assertSame('fake/llm', $result->model);
    }
}
