<?php

namespace App\Support\Mailbox;

final class OutboundResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {
    }
}
