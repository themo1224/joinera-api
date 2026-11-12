<?php

namespace App\Support;

final class LlmChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?string $finishReason = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $usage = null,
    ) {
    }
}
