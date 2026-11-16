<?php

namespace App\Exceptions;

use RuntimeException;

class ApplyCapExceededException extends RuntimeException
{
    /**
     * @param  array{cap: int, window: string, used: int, remaining: int}  $applyCap
     */
    public function __construct(
        public readonly array $applyCap,
    ) {
        parent::__construct('Apply cap reached for this Candidate in the current window.');
    }
}
