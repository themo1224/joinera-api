<?php

namespace App\Support\Mailbox;

final class SyncResult
{
    public function __construct(
        public readonly int $upserted = 0,
        public readonly bool $authFailed = false,
        public readonly ?string $error = null,
    ) {
    }
}
