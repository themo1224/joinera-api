<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusChanged
{
    use Dispatchable, SerializesModels;

    public const NAME = 'application.status_changed';

    public function __construct(
        public readonly int $applicationId,
        public readonly string $from,
        public readonly string $to,
    ) {
    }
}
