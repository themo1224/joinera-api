<?php

namespace App\Services\Ai;

use App\Contracts\LlmClient;
use App\Exceptions\AiUnavailableException;
use App\Exceptions\LlmRequestException;
use App\Support\LlmChatResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Gemini generateContent client. Accepts the same multimodal content parts
 * used by document analyze (text / file / image_url) and maps them to Gemini parts.
 */
class GeminiLlmClient implements LlmClient
{
    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $apiKey = trim((string) config('joinera.ai.gemini.api_key'));
        if ($apiKey === '') {
            throw new AiUnavailableException(
                AiUnavailableException::CODE_NOT_CONFIGURED,
                'AI is not configured (GEMINI_API_KEY is missing).',
            );
        }

        $models = $this->modelCandidates();
        if ($models === []) {
            throw new LlmRequestException('GEMINI_MODEL is not configured.');
        }

        $baseUrl = rtrim((string) config(
            'joinera.ai.gemini.base_url',
            'https://generativelanguage.googleapis.com/v1beta',
        ), '/');
        $timeout = max(5, (int) config('joinera.ai.gemini.timeout', 180));
        $connectTimeout = max(5, (int) config('joinera.ai.gemini.connect_timeout', 45));
        $attempts = max(1, (int) config('joinera.ai.gemini.retries', 3));

        $parts = $this->toGeminiParts($messages);
        if ($parts === []) {
            throw new LlmRequestException('Gemini request has no content parts.');
        }

        $generationConfig = [];
        if (array_key_exists('temperature', $options)) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (array_key_exists('max_tokens', $options)) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
        ];
        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $lastError = null;

        foreach ($models as $model) {
            $url = $baseUrl.'/models/'.rawurlencode($model).':generateContent';

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $response = Http::timeout($timeout)
                        ->connectTimeout($connectTimeout)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'User-Agent' => 'JoineraGeminiClient/1.0',
                        ])
                        ->withQueryParameters(['key' => $apiKey])
                        ->post($url, $payload);
                } catch (ConnectionException $e) {
                    $lastError = new LlmRequestException(
                        'Gemini request failed to connect: '.$e->getMessage(),
                        null,
                        $e,
                    );
                    usleep(400_000 * $attempt);
                    continue;
                } catch (Throwable $e) {
                    throw new LlmRequestException(
                        'Gemini request failed: '.$e->getMessage(),
                        null,
                        $e,
                    );
                }

                if ($response->successful()) {
                    return $this->toChatResult($response, $model);
                }

                $message = $this->errorMessage($response);
                $lastError = new LlmRequestException(
                    'Gemini request failed: '.$message,
                    $response->status(),
                );

                // Retry / fall through models on transient Google overload or HTML edge blocks.
                if ($this->shouldRetry($response) && $attempt < $attempts) {
                    usleep(500_000 * $attempt);
                    continue;
                }

                if ($this->shouldRetry($response)) {
                    break; // try next model
                }

                throw $lastError;
            }
        }

        throw $lastError ?? new LlmRequestException('Gemini request failed.');
    }

    /**
     * @return list<string>
     */
    private function modelCandidates(): array
    {
        $primary = trim((string) config('joinera.ai.gemini.model', 'gemini-3-flash-preview'));
        $fallbacks = config('joinera.ai.gemini.fallback_models', []);
        $models = [];
        if ($primary !== '') {
            $models[] = $primary;
        }
        if (is_array($fallbacks)) {
            foreach ($fallbacks as $fallback) {
                if (! is_string($fallback)) {
                    continue;
                }
                $name = trim($fallback);
                if ($name !== '' && ! in_array($name, $models, true)) {
                    $models[] = $name;
                }
            }
        }

        return $models;
    }

    private function shouldRetry(Response $response): bool
    {
        $status = $response->status();
        if (in_array($status, [429, 500, 502, 503, 504], true)) {
            return true;
        }

        // Google sometimes returns an HTML 403 interstitial under load / edge filtering.
        if ($status === 403 && str_contains($response->body(), '<html')) {
            return true;
        }

        $message = strtolower((string) data_get($response->json(), 'error.message', ''));

        return str_contains($message, 'high demand')
            || str_contains($message, 'try again')
            || str_contains($message, 'resource exhausted');
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();
        if (is_array($body)) {
            return (string) data_get($body, 'error.message', $response->body());
        }

        $raw = trim($response->body());
        if (str_contains($raw, '<html')) {
            return 'HTTP '.$response->status().' from Gemini (temporary Google block/overload). Retry in a minute.';
        }

        return $raw !== '' ? $raw : 'HTTP '.$response->status();
    }

    private function toChatResult(Response $response, string $model): LlmChatResult
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new LlmRequestException('Gemini returned a non-JSON body.', $response->status());
        }

        $content = $this->extractText($data);
        if ($content === '') {
            throw new LlmRequestException('Gemini returned an empty completion.', $response->status());
        }

        $finishReason = data_get($data, 'candidates.0.finishReason');
        $usage = data_get($data, 'usageMetadata');

        return new LlmChatResult(
            content: $content,
            model: $model,
            finishReason: is_string($finishReason) ? $finishReason : null,
            usage: is_array($usage) ? $usage : null,
        );
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array<string, mixed>>
     */
    private function toGeminiParts(array $messages): array
    {
        $parts = [];

        foreach ($messages as $message) {
            $content = $message['content'] ?? null;

            if (is_string($content) && trim($content) !== '') {
                $role = (string) ($message['role'] ?? 'user');
                $prefix = $role === 'system' ? "System instructions:\n" : '';
                $parts[] = ['text' => $prefix.$content];
                continue;
            }

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $type = (string) ($part['type'] ?? '');
                if ($type === 'text' && isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                    $parts[] = ['text' => $part['text']];
                    continue;
                }

                if ($type === 'file') {
                    $file = is_array($part['file'] ?? null) ? $part['file'] : [];
                    $dataUrl = (string) ($file['file_data'] ?? '');
                    $inline = $this->dataUrlToInline($dataUrl, 'application/pdf');
                    if ($inline !== null) {
                        $parts[] = ['inline_data' => $inline];
                    }
                    continue;
                }

                if ($type === 'image_url') {
                    $image = is_array($part['image_url'] ?? null) ? $part['image_url'] : [];
                    $dataUrl = (string) ($image['url'] ?? '');
                    $inline = $this->dataUrlToInline($dataUrl, 'image/jpeg');
                    if ($inline !== null) {
                        $parts[] = ['inline_data' => $inline];
                    }
                }
            }
        }

        return $parts;
    }

    /**
     * @return array{mime_type: string, data: string}|null
     */
    private function dataUrlToInline(string $dataUrl, string $fallbackMime): ?array
    {
        if (! str_starts_with($dataUrl, 'data:') || ! str_contains($dataUrl, ',')) {
            return null;
        }

        [$meta, $data] = explode(',', $dataUrl, 2);
        if ($data === '') {
            return null;
        }

        $mime = $fallbackMime;
        if (preg_match('#^data:([^;]+)#', $meta, $m) === 1) {
            $mime = $m[1];
        }

        return [
            'mime_type' => $mime,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractText(array $data): string
    {
        $parts = data_get($data, 'candidates.0.content.parts');
        if (! is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $chunks[] = $part['text'];
            }
        }

        return trim(implode("\n", $chunks));
    }
}
