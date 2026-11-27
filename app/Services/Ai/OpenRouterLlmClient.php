<?php

namespace App\Services\Ai;

use App\Contracts\LlmClient;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\LlmRequestException;
use App\Support\LlmChatResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenRouterLlmClient implements LlmClient
{
    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $apiKey = trim((string) config('joinera.ai.openrouter.api_key'));

        if ($apiKey === '') {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_NOT_CONFIGURED,
                'AI is not configured (OPENROUTER_API_KEY is missing).',
            );
        }

        $model = trim((string) config('joinera.ai.openrouter.model', 'openrouter/free'));

        if ($model === '') {
            throw new LlmRequestException('OPENROUTER_MODEL is not configured.');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        $fallbacks = config('joinera.ai.openrouter.fallback_models', []);
        if (is_array($fallbacks) && $fallbacks !== []) {
            $payload['models'] = array_values(array_filter(
                $fallbacks,
                static fn (mixed $item): bool => is_string($item) && trim($item) !== '' && trim($item) !== $model,
            ));
        }

        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if (array_key_exists('max_tokens', $options)) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        if (array_key_exists('plugins', $options) && is_array($options['plugins']) && $options['plugins'] !== []) {
            $payload['plugins'] = array_values($options['plugins']);
        }

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ];

        $referer = trim((string) config('joinera.ai.openrouter.http_referer'));
        if ($referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }

        $title = trim((string) config('joinera.ai.openrouter.app_title', 'Joinera'));
        if ($title !== '') {
            $headers['X-Title'] = $title;
        }

        $baseUrl = (string) config('joinera.ai.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $timeout = max(5, (int) config('joinera.ai.openrouter.timeout', 120));
        $connectTimeout = max(5, (int) config('joinera.ai.openrouter.connect_timeout', 45));
        $attempts = max(1, (int) config('joinera.ai.openrouter.retries', 3));

        $response = null;
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->acceptJson()
                    ->post($baseUrl.'/chat/completions', $payload);
                $lastConnectionError = null;
                break;
            } catch (ConnectionException $e) {
                $lastConnectionError = $e;
                if ($attempt >= $attempts) {
                    throw new LlmRequestException(
                        'OpenRouter request failed to connect after '.$attempts.' attempts: '.$e->getMessage(),
                        null,
                        $e,
                    );
                }
                usleep(250_000 * $attempt);
            } catch (Throwable $e) {
                throw new LlmRequestException(
                    'OpenRouter request failed: '.$e->getMessage(),
                    null,
                    $e,
                );
            }
        }

        if ($response === null) {
            throw new LlmRequestException(
                'OpenRouter request failed to connect'.($lastConnectionError ? ': '.$lastConnectionError->getMessage() : '.'),
                null,
                $lastConnectionError,
            );
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();

            if ($response->status() === 402 || $this->looksLikeInsufficientCredit($message)) {
                throw new AiUnavailableException(
                    AiUnavailableException::CODE_NO_CREDIT,
                    'No AI credit remaining.',
                );
            }

            throw new LlmRequestException(
                'OpenRouter request failed: '.$message,
                $response->status(),
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new LlmRequestException('OpenRouter returned a non-JSON body.', $response->status());
        }

        $content = data_get($data, 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new LlmRequestException('OpenRouter returned an empty completion.', $response->status());
        }

        $usedModel = data_get($data, 'model');
        $finishReason = data_get($data, 'choices.0.finish_reason');
        $usage = data_get($data, 'usage');

        return new LlmChatResult(
            content: $content,
            model: is_string($usedModel) && $usedModel !== '' ? $usedModel : $model,
            finishReason: is_string($finishReason) ? $finishReason : null,
            usage: is_array($usage) ? $usage : null,
        );
    }

    private function looksLikeInsufficientCredit(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'insufficient credits')
            || str_contains($lower, 'insufficient credit')
            || str_contains($lower, 'no credits')
            || str_contains($lower, 'credit balance');
    }
}
