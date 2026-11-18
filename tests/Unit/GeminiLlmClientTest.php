<?php

namespace Tests\Unit;

use App\Services\Ai\GeminiLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiLlmClientTest extends TestCase
{
    public function test_gemini_chat_maps_multimodal_parts_and_returns_text(): void
    {
        config([
            'joinera.ai.gemini.api_key' => 'test-gemini-key',
            'joinera.ai.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'joinera.ai.gemini.model' => 'gemini-3-flash-preview',
            'joinera.ai.gemini.fallback_models' => [],
            'joinera.ai.gemini.timeout' => 10,
            'joinera.ai.gemini.connect_timeout' => 5,
            'joinera.ai.gemini.retries' => 1,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'finishReason' => 'STOP',
                        'content' => [
                            'parts' => [
                                ['text' => '{"suggested_name":"Ada"}'],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                ],
            ], 200),
        ]);

        $result = app(GeminiLlmClient::class)->chat([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Extract'],
                    [
                        'type' => 'file',
                        'file' => [
                            'filename' => 'cv.pdf',
                            'file_data' => 'data:application/pdf;base64,AAA',
                        ],
                    ],
                ],
            ],
        ], ['temperature' => 0, 'max_tokens' => 200]);

        $this->assertSame('{"suggested_name":"Ada"}', $result->content);
        $this->assertSame('gemini-3-flash-preview', $result->model);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $parts = $data['contents'][0]['parts'] ?? null;

            return str_contains($request->url(), 'gemini-3-flash-preview:generateContent')
                && str_contains($request->url(), 'key=test-gemini-key')
                && is_array($parts)
                && ($parts[0]['text'] ?? null) === 'Extract'
                && ($parts[1]['inline_data']['mime_type'] ?? null) === 'application/pdf'
                && ($parts[1]['inline_data']['data'] ?? null) === 'AAA';
        });
    }

    public function test_gemini_falls_back_to_next_model_on_high_demand(): void
    {
        config([
            'joinera.ai.gemini.api_key' => 'test-gemini-key',
            'joinera.ai.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'joinera.ai.gemini.model' => 'gemini-3-flash-preview',
            'joinera.ai.gemini.fallback_models' => ['gemini-2.5-flash'],
            'joinera.ai.gemini.timeout' => 10,
            'joinera.ai.gemini.connect_timeout' => 5,
            'joinera.ai.gemini.retries' => 1,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*/gemini-3-flash-preview:generateContent*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'This model is currently experiencing high demand. Please try again later.',
                ],
            ], 503),
            'generativelanguage.googleapis.com/*/gemini-2.5-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'finishReason' => 'STOP',
                        'content' => [
                            'parts' => [
                                ['text' => '{"education":"Abitur"}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GeminiLlmClient::class)->chat([
            ['role' => 'user', 'content' => 'Extract'],
        ]);

        $this->assertSame('{"education":"Abitur"}', $result->content);
        $this->assertSame('gemini-2.5-flash', $result->model);
    }
}
