<?php

namespace App\Services\Ai;

use App\Contracts\LlmClient;
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
            throw new LlmRequestException('OPENROUTER_API_KEY is not configured.');
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
        $timeout = (int) config('joinera.ai.openrouter.timeout', 60);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new LlmRequestException('OpenRouter request failed to connect.', null, $e);
        } catch (Throwable $e) {
            throw new LlmRequestException('OpenRouter request failed.', null, $e);
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();

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
}
