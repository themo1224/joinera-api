<?php

namespace App\Services\Ai;

use App\Contracts\LlmClient;
use App\Exceptions\LlmRequestException;
use App\Support\LlmChatResult;

class FakeLlmClient implements LlmClient
{
    public const FAIL_MARKER = 'FAIL_LLM';

    public const EMPTY_MARKER = 'EMPTY_LLM';

    /** @var list<string> */
    private array $forcedContents = [];

    public function queueContent(string $content): void
    {
        $this->forcedContents[] = $content;
    }

    public function chat(array $messages, array $options = []): LlmChatResult
    {
        $lastUser = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $lastUser = $message['content'];
                break;
            }
        }

        if (str_contains($lastUser, self::FAIL_MARKER)) {
            throw new LlmRequestException('Fake LLM forced failure.', 500);
        }

        if ($this->forcedContents !== []) {
            $content = array_shift($this->forcedContents) ?? '';
        } elseif (str_contains($lastUser, self::EMPTY_MARKER)) {
            $content = '';
        } else {
            $content = "FAKE_COVER\n\n".$lastUser;
        }

        return new LlmChatResult(
            content: $content,
            model: 'fake/llm',
            finishReason: 'stop',
            usage: [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
                'total_tokens' => 2,
            ],
        );
    }
}
