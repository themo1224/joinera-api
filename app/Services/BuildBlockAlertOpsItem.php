<?php

namespace App\Services;

use App\Models\BlockEvent;
use App\Models\SiteIdentity;
use App\Support\ModeBFailureCatalog;

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
     *     failureCode: string,
     *     failureLabel: string,
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

        $failure = ModeBFailureCatalog::forBlockKind($kind);
        $who = $applicantName !== '' ? $applicantName : ('applicant #'.$event->user_id);
        $needsHelpMessage = $open
            ? sprintf(
                'Needs help: %s for %s%s. Open the company page to finish by hand.',
                $failure['labelEn'],
                $who,
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
            // Plan 31 — Staff-readable failure map.
            'failureCode' => $failure['code'],
            'failureLabel' => $failure['labelEn'],
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
