<?php

namespace App\Contracts;

use App\Exceptions\LlmRequestException;
use App\Support\LlmChatResult;

interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  array{temperature?: float, max_tokens?: int, plugins?: list<array<string, mixed>>}  $options
     *
     * @throws LlmRequestException
     */
    public function chat(array $messages, array $options = []): LlmChatResult;
}
