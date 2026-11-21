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
     *     needsHelp: bool,
     *     needsHelpMessage: string,
     *     companyApplyUrl: string|null,
     *     createdAt: string|null,
     *     resolvedAt: string|null
     * }
     */
    public function handle(BlockEvent $event, ?SiteIdentity $identity = null): array
    {
        $event->loadMissing(['user', 'application.listing']);

        $identity ??= SiteIdentity::query()
            ->where('user_id', $event->user_id)
            ->where('site', $event->site)
            ->first();

        $listing = $event->application?->listing;
        $companyApplyUrl = $listing?->apply_url ?: $listing?->url;
        $open = $event->isOpen();
        $applicantName = $event->user?->name ?? '';
        $kind = $event->observed_kind;

        $needsHelpMessage = $open
            ? sprintf(
                'Needs help: %s blocked apply for %s%s. Open the company page to finish by hand.',
                $kind,
                $applicantName !== '' ? $applicantName : ('applicant #'.$event->user_id),
                $companyApplyUrl ? '' : ' (company URL missing)',
            )
            : 'Block cleared.';

        return [
            'id' => $event->id,
            'applicationId' => $event->application_id,
            'applicantId' => $event->user_id,
            'applicantName' => $applicantName,
            'siteId' => $event->site,
            'siteIdentityId' => $identity?->id,
            'kind' => $kind,
            // Plan 25 — open pause tracks BlockEvent.resolved_at (company-site may lack SiteIdentity).
            'open' => $open,
            // Plan 26/27 — clear Staff signal + same company page to finish.
            'needsHelp' => $open,
            'needsHelpMessage' => $needsHelpMessage,
            'companyApplyUrl' => is_string($companyApplyUrl) && $companyApplyUrl !== ''
                ? $companyApplyUrl
                : null,
            'createdAt' => $event->created_at?->toISOString(),
            'resolvedAt' => $event->resolved_at?->toISOString(),
        ];
    }
}
