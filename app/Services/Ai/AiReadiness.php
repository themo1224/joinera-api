<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Last-second gate before paid/network LLM work (staff document analyze).
 *
 * OpenRouter: uses GET /api/v1/key (regular API key). GET /api/v1/credits
 * requires a management key and is intentionally not used here.
 */
class AiReadiness
{
    /**
     * @throws AiUnavailableException
     */
    public function assertReady(): void
    {
        $result = $this->check();
        if ($result['ready']) {
            return;
        }

        throw new AiUnavailableException($result['code'], $result['message']);
    }

    /**
     * @return array{ready: true}|array{ready: false, code: string, message: string}
     */
    public function check(): array
    {
        if ($this->shouldBypass()) {
            return ['ready' => true];
        }

        return $this->evaluate();
    }

    /**
     * Real driver/key/credit evaluation (no testing bypass). Prefer {@see check()} in app code.
     *
     * @return array{ready: true}|array{ready: false, code: string, message: string}
     */
    public function evaluate(): array
    {
        $driver = strtolower(trim((string) config('joinera.ai.driver', 'openrouter')));

        return match ($driver) {
            'fake' => ['ready' => true],
            'gemini' => $this->evaluateGemini(),
            default => $this->evaluateOpenRouter(),
        };
    }

    private function shouldBypass(): bool
    {
        if ((string) config('joinera.ai.driver') === 'fake') {
            return true;
        }

        // PHPUnit uses FakeLlmClient; skip live provider probes unless a test forces them.
        if (app()->environment('testing') && ! config('joinera.ai.force_readiness_check')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{ready: true}|array{ready: false, code: string, message: string}
     */
    private function evaluateGemini(): array
    {
        $apiKey = trim((string) config('joinera.ai.gemini.api_key'));
        if ($apiKey === '') {
            return $this->notConfigured('AI is not configured (GEMINI_API_KEY is missing).');
        }

        return ['ready' => true];
    }

    /**
     * @return array{ready: true}|array{ready: false, code: string, message: string}
     */
    private function evaluateOpenRouter(): array
    {
        $apiKey = trim((string) config('joinera.ai.openrouter.api_key'));
        if ($apiKey === '') {
            return $this->notConfigured('AI is not configured (OPENROUTER_API_KEY is missing).');
        }

        $baseUrl = rtrim((string) config('joinera.ai.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $timeout = max(5, min(30, (int) config('joinera.ai.openrouter.connect_timeout', 45)));

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->get($baseUrl.'/key');
        } catch (ConnectionException $e) {
            return $this->notConfigured(
                'AI is not configured (could not reach OpenRouter to verify the key).'
            );
        } catch (Throwable $e) {
            return $this->notConfigured(
                'AI is not configured (could not verify the OpenRouter key).'
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->noCredit('No AI credit remaining (OpenRouter key was rejected).');
        }

        if ($response->status() === 402) {
            return $this->noCredit('No AI credit remaining.');
        }

        if ($response->failed()) {
            $message = (string) data_get($response->json(), 'error.message', '');
            $lower = strtolower($message);
            if (
                str_contains($lower, 'credit')
                || str_contains($lower, 'insufficient')
                || str_contains($lower, 'payment')
            ) {
                return $this->noCredit('No AI credit remaining.');
            }

            return $this->notConfigured(
                'AI is not configured (OpenRouter key check failed).'
            );
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            // Key authenticated but shape unexpected — do not invent a balance; allow analyze.
            return ['ready' => true];
        }

        $limitRemaining = $data['limit_remaining'] ?? null;
        if (is_numeric($limitRemaining) && (float) $limitRemaining <= 0) {
            return $this->noCredit('No AI credit remaining.');
        }

        $budgetRemaining = data_get($data, 'effective_budget.remaining_usd');
        if (is_numeric($budgetRemaining) && (float) $budgetRemaining <= 0) {
            return $this->noCredit('No AI credit remaining.');
        }

        return ['ready' => true];
    }

    /**
     * @return array{ready: false, code: string, message: string}
     */
    private function notConfigured(string $message): array
    {
        return [
            'ready' => false,
            'code' => AiUnavailableException::CODE_NOT_CONFIGURED,
            'message' => $message,
        ];
    }

    /**
     * @return array{ready: false, code: string, message: string}
     */
    private function noCredit(string $message): array
    {
        return [
            'ready' => false,
            'code' => AiUnavailableException::CODE_NO_CREDIT,
            'message' => $message,
        ];
    }
}
