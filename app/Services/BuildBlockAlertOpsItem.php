<?php

namespace App\Services;

use App\Models\BlockEvent;
use App\Models\SiteIdentity;

class BuildBlockAlertOpsItem
{
    /**
     * @return array{
     *     id: int,
     *     applicationId: int|null,
     *     applicantId: int,
     *     applicantName: string,
     *     siteId: string,
     *     siteIdentityId: int|null,
     *     kind: string,
     *     open: bool,
     *     createdAt: string|null
     * }
     */
    public function handle(BlockEvent $event, ?SiteIdentity $identity = null): array
    {
        $event->loadMissing('user');

        $identity ??= SiteIdentity::query()
            ->where('user_id', $event->user_id)
            ->where('site', $event->site)
            ->first();

        return [
            'id' => $event->id,
            'applicationId' => $event->application_id,
            'applicantId' => $event->user_id,
            'applicantName' => $event->user?->name ?? '',
            'siteId' => $event->site,
            'siteIdentityId' => $identity?->id,
            'kind' => $event->observed_kind,
            'open' => $identity?->paused === true,
            'createdAt' => $event->created_at?->toISOString(),
        ];
    }
}
