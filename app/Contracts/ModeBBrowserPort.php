<?php

namespace App\Contracts;

use App\Services\Apply\ModeBBrowserResult;

/**
 * Mode B browser worker port. Input is user_id + site_id + application_id only.
 * PDF paths are resolved inside the worker from DocArtifacts.
 */
interface ModeBBrowserPort
{
    public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult;
}
