<?php

namespace App\Exceptions;

use RuntimeException;

class AiUnavailableException extends RuntimeException
{
    public const CODE_NOT_CONFIGURED = 'ai_not_configured';

    public const CODE_NO_CREDIT = 'ai_no_credit';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
