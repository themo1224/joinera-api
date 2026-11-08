<?php

namespace App\Services;

use App\Enums\SiteIdentityState;
use App\Exceptions\SharedSiteLoginException;
use App\Exceptions\SitesUnconfiguredException;
use App\Exceptions\UnknownSiteException;
use App\Models\SiteIdentity;
use App\Models\User;

class StoreSiteIdentity
{
    public function handle(User $user, string $site, string $siteLogin, string $secret): SiteIdentity
    {
        $sites = $this->configuredSites();

        if ($sites === []) {
            throw new SitesUnconfiguredException;
        }

        if (! in_array($site, $sites, true)) {
            throw new UnknownSiteException;
        }

        $takenByOther = SiteIdentity::query()
            ->where('site', $site)
            ->where('site_login', $siteLogin)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($takenByOther) {
            throw new SharedSiteLoginException;
        }

        $identity = SiteIdentity::query()->firstOrNew([
            'user_id' => $user->id,
            'site' => $site,
        ]);

        $identity->site_login = $siteLogin;
        $identity->secret = $secret;

        if (! $identity->exists) {
            $identity->state = SiteIdentityState::Healthy;
        }

        $identity->save();

        return $identity;
    }

    /**
     * @return list<string>
     */
    private function configuredSites(): array
    {
        $sites = config('joinera.sites.v1', []);

        if (! is_array($sites)) {
            return [];
        }

        return array_values(array_filter(
            $sites,
            fn (mixed $site): bool => is_string($site) && $site !== '',
        ));
    }
}
