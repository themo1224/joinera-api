<?php

namespace App\Services;

use App\Enums\MailboxState;
use App\Enums\SiteIdentityState;
use App\Models\User;

class ResolveIdentityReadiness
{
    /**
     * @return array{readiness: string, reason: string}
     */
    public function handle(User $user): array
    {
        if ($this->mailboxProviderUnset()) {
            return $this->result('needs_staff', 'mailbox_provider_unset');
        }

        $mailbox = $user->mailboxIdentity;

        if ($mailbox === null || $mailbox->state !== MailboxState::Ready) {
            return $this->result('needs_staff', 'mailbox_not_ready');
        }

        if ($this->sitesUnconfigured()) {
            return $this->result('needs_staff', 'sites_unconfigured');
        }

        $identities = $user->siteIdentities()->get();

        $hasHealthy = $identities->contains(
            fn ($identity): bool => $identity->state === SiteIdentityState::Healthy,
        );

        if (! $hasHealthy) {
            return $this->result('needs_staff', 'no_healthy_site');
        }

        $allPaused = $identities->isNotEmpty()
            && $identities->every(fn ($identity): bool => (bool) $identity->paused);

        if ($allPaused) {
            return $this->result('blocked', 'all_sites_paused');
        }

        $hasHealthyUnpaused = $identities->contains(
            fn ($identity): bool => $identity->state === SiteIdentityState::Healthy
                && ! (bool) $identity->paused,
        );

        if ($hasHealthyUnpaused) {
            return $this->result('ready', 'ready');
        }

        return $this->result('needs_staff', 'no_healthy_site');
    }

    private function mailboxProviderUnset(): bool
    {
        $mode = config('joinera.mailbox.mode');
        $provider = config('joinera.mailbox.provider');

        return ! is_string($mode) || $mode === ''
            || ! is_string($provider) || $provider === '';
    }

    private function sitesUnconfigured(): bool
    {
        $sites = config('joinera.sites.v1', []);

        if (! is_array($sites)) {
            return true;
        }

        $configured = array_values(array_filter(
            $sites,
            fn (mixed $site): bool => is_string($site) && $site !== '',
        ));

        return $configured === [];
    }

    /**
     * @return array{readiness: string, reason: string}
     */
    private function result(string $readiness, string $reason): array
    {
        return [
            'readiness' => $readiness,
            'reason' => $reason,
        ];
    }
}
