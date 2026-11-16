<?php

namespace App\Services;

use App\Contracts\ModeBSubmitAfterDocsReady;
use App\Models\Application;
use App\Models\ApplyAttempt;

/**
 * Plan 2 placeholder — Plan 3 owns upload/submit.
 */
class NoOpModeBSubmitAfterDocsReady implements ModeBSubmitAfterDocsReady
{
    public function handle(Application $application, ApplyAttempt $attempt): void
    {
        // Intentionally empty until Mode B fixture adapter lands.
    }
}
