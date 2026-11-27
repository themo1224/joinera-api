<?php

namespace Tests\Unit;

use App\Exceptions\AiUnavailableException;
use App\Services\Ai\AiReadiness;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiReadinessTest extends TestCase
{
    #[Test]
    public function it_reports_not_configured_when_openrouter_key_missing(): void
    {
        config([
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => '',
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertFalse($result['ready']);
        $this->assertSame(AiUnavailableException::CODE_NOT_CONFIGURED, $result['code']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_reports_not_configured_when_gemini_key_missing(): void
    {
        config([
            'joinera.ai.driver' => 'gemini',
            'joinera.ai.gemini.api_key' => '   ',
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertFalse($result['ready']);
        $this->assertSame(AiUnavailableException::CODE_NOT_CONFIGURED, $result['code']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_reports_no_credit_when_openrouter_limit_remaining_is_zero(): void
    {
        config([
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response([
                'data' => [
                    'limit' => 10,
                    'limit_remaining' => 0,
                ],
            ], 200),
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertFalse($result['ready']);
        $this->assertSame(AiUnavailableException::CODE_NO_CREDIT, $result['code']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://openrouter.ai/api/v1/key'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    #[Test]
    public function it_reports_no_credit_when_openrouter_auth_fails(): void
    {
        config([
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => 'bad-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response([
                'error' => ['message' => 'Missing Authentication header'],
            ], 401),
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertFalse($result['ready']);
        $this->assertSame(AiUnavailableException::CODE_NO_CREDIT, $result['code']);
    }

    #[Test]
    public function it_is_ready_when_openrouter_key_has_remaining_credit(): void
    {
        config([
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => 'test-key',
            'joinera.ai.openrouter.base_url' => 'https://openrouter.ai/api/v1',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/key' => Http::response([
                'data' => [
                    'limit' => 100,
                    'limit_remaining' => 42.5,
                    'effective_budget' => [
                        'remaining_usd' => 42.5,
                    ],
                ],
            ], 200),
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertTrue($result['ready']);
    }

    #[Test]
    public function it_is_ready_for_gemini_when_key_present(): void
    {
        config([
            'joinera.ai.driver' => 'gemini',
            'joinera.ai.gemini.api_key' => 'gemini-test-key',
        ]);

        $result = app(AiReadiness::class)->evaluate();

        $this->assertTrue($result['ready']);
        Http::assertNothingSent();
    }

    #[Test]
    public function assert_ready_throws_structured_exception(): void
    {
        config([
            'joinera.ai.force_readiness_check' => true,
            'joinera.ai.driver' => 'openrouter',
            'joinera.ai.openrouter.api_key' => '',
        ]);

        try {
            app(AiReadiness::class)->assertReady();
            $this->fail('Expected AiUnavailableException');
        } catch (AiUnavailableException $e) {
            $this->assertSame(AiUnavailableException::CODE_NOT_CONFIGURED, $e->errorCode);
            $this->assertStringContainsString('not configured', strtolower($e->getMessage()));
        }
    }
}
