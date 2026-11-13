<?php

namespace App\Exceptions;

use RuntimeException;

class MatchNotSkippedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('match_not_skipped');
    }
}
