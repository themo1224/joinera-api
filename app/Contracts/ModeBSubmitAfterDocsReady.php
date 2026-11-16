<?php

namespace App\Contracts;

use App\Models\Application;
use App\Models\ApplyAttempt;

/**
 * Plan 3 replaces the NoOp with fixture/real Mode B browser submit.
 */
interface ModeBSubmitAfterDocsReady
{
    public function handle(Application $application, ApplyAttempt $attempt): void;
}
