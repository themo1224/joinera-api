<?php

namespace App\Contracts;

use App\Exceptions\LlmRequestException;
use App\Support\LlmChatResult;

interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{temperature?: float, max_tokens?: int}  $options
     *
     * @throws LlmRequestException
     */
    public function chat(array $messages, array $options = []): LlmChatResult;
}
