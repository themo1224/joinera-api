<?php

namespace App\Services;

use App\Models\BlockEvent;
use Illuminate\Support\Carbon;

/**
 * Plan 25/29 — clear open Staff pauses when retrying or completing an apply.
 */
class ResolveOpenBlockEvents
{
    public function forApplication(int $applicationId, ?Carbon $at = null): int
    {
        return BlockEvent::query()
            ->where('application_id', $applicationId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => $at ?? now()]);
    }

    public function forUserSite(int $userId, string $site, ?Carbon $at = null): int
    {
        return BlockEvent::query()
            ->where('user_id', $userId)
            ->where('site', $site)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => $at ?? now()]);
    }
}
