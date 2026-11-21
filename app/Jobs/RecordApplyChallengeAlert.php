<?php

namespace App\Jobs;

use App\Models\AdminAlert;
use App\Models\Application;
use App\Models\BlockEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Plan 26 — durable Staff “needs help” signal after a Mode B challenge pause.
 */
class RecordApplyChallengeAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $site,
        public readonly int $applicationId,
        public readonly string $observedKind,
        public readonly ?int $blockEventId = null,
    ) {
        $this->onQueue((string) config('joinera.queues.alerts', 'alerts'));
    }

    public function handle(): void
    {
        $application = Application::query()
            ->with(['user', 'listing'])
            ->find($this->applicationId);

        $applicantName = $application?->user?->name;
        $listingTitle = $application?->listing?->title;
        $companyApplyUrl = $application?->listing?->apply_url
            ?: $application?->listing?->url;

        $kindLabel = match ($this->observedKind) {
            BlockEvent::KIND_TWO_FACTOR => 'two-factor / code wall',
            BlockEvent::KIND_LOGIN_WALL => 'login wall',
            BlockEvent::KIND_COOKIE_WALL => 'cookie wall',
            default => 'CAPTCHA',
        };

        $who = $applicantName !== null && $applicantName !== ''
            ? $applicantName
            : ('applicant #'.$this->userId);
        $job = $listingTitle !== null && $listingTitle !== ''
            ? $listingTitle
            : ('application #'.$this->applicationId);

        $message = sprintf(
            'Needs help: Mode B paused on %s (%s) for %s — %s. Finish on the company apply page.',
            $kindLabel,
            $this->site,
            $who,
            $job,
        );

        AdminAlert::query()->create([
            'kind' => AdminAlert::KIND_APPLY_CHALLENGE,
            'site' => $this->site,
            'payload' => [
                'needs_help' => true,
                'message' => $message,
                'user_id' => $this->userId,
                'applicant_name' => $applicantName,
                'application_id' => $this->applicationId,
                'listing_title' => $listingTitle,
                'company_apply_url' => $companyApplyUrl,
                'observed_kind' => $this->observedKind,
                'block_event_id' => $this->blockEventId,
            ],
        ]);
    }
}
