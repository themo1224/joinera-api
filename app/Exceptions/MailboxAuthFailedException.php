<?php

namespace App\Exceptions;

use RuntimeException;

class MailboxAuthFailedException extends RuntimeException
{
    public function __construct(string $message = 'gmx_auth_failed')
    {
        parent::__construct($message);
    }
}
