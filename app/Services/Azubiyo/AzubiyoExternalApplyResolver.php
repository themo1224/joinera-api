<?php

namespace App\Services\Azubiyo;

use App\Contracts\AzubiyoHttpClient;
use App\Models\Listing;
use App\Support\ApplyUrlPreference;

/**
 * Azubiyo /stellenanzeigen/…/bewerben/ is an exit interstitial (“Du verlässt
 * jetzt Azubiyo…”), not the employer form. Resolve the off-board company URL
 * from that page (setApplication target / “hier” link / copy button).
 */
class AzubiyoExternalApplyResolver
{
    public function __construct(
        private readonly AzubiyoHttpClient $http,
    ) {}

    public function resolve(?string $applyUrl): ?string
    {
        if ($applyUrl === null || trim($applyUrl) === '') {
            return $applyUrl;
        }

        $resolved = $applyUrl;

        if (ApplyUrlPreference::isBoardHost('azubiyo', $applyUrl)) {
            $path = parse_url($applyUrl, PHP_URL_PATH) ?: '';

            // Easy-Apply / email / post stay on-board; only the external exit path.
            if (preg_match('#/bewerben/?$#i', $path) !== 1) {
                return $applyUrl;
            }

            try {
                $html = $this->http->get($applyUrl);
            } catch (\Throwable) {
                return $applyUrl;
            }

            $companyUrl = $this->extractInterstitialCompanyUrl($html);

            if ($companyUrl === null || ApplyUrlPreference::isBoardHost('azubiyo', $companyUrl)) {
                return $applyUrl;
            }

            $resolved = $companyUrl;
        }

        return $this->maybeUnwrapTrackingUrl($resolved);
    }

    /**
     * Aplitrak (and www) often 200 with meta-refresh to the real employer form.
     * Store the final employer URL so liveness probes see the true target.
     */
    public function maybeUnwrapTrackingUrl(string $url): string
    {
        if (! $this->isAplitrakHost($url)) {
            return $url;
        }

        try {
            $html = $this->http->get($url);
        } catch (\Throwable) {
            return $url;
        }

        $next = $this->extractMetaRefreshUrl($html, $url);

        if (
            $next === null
            || $next === $url
            || ApplyUrlPreference::isBoardHost('azubiyo', $next)
            || $this->isAplitrakHost($next)
        ) {
            return $url;
        }

        return $next;
    }

    public function isAplitrakHost(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return $host === 'aplitrak.com' || str_ends_with($host, '.aplitrak.com');
    }

    public function extractMetaRefreshUrl(string $html, string $baseUrl): ?string
    {
        if (preg_match(
            '/<meta[^>]+http-equiv\s*=\s*["\']?refresh["\']?[^>]*content\s*=\s*["\']?\s*\d+\s*;\s*url\s*=\s*([^>\s]+)/i',
            $html,
            $matches,
        ) !== 1 && preg_match(
            '/<meta[^>]+content\s*=\s*["\']?\s*\d+\s*;\s*url\s*=\s*([^>\s]+)[^>]*http-equiv\s*=\s*["\']?refresh/i',
            $html,
            $matches,
        ) !== 1) {
            return null;
        }

        $raw = html_entity_decode(trim($matches[1], " \t\n\r\0\x0B'\""), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($raw === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }

        if (str_starts_with($raw, '//')) {
            return 'https:'.$raw;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (str_starts_with($raw, '/')) {
            return $origin.$raw;
        }

        return $origin.'/'.$raw;
    }

    /**
     * Persist an upgraded company apply_url when the listing still has a board exit URL.
     *
     * @return 'company'|'board'|'missing'
     */
    public function upgradeListingApplyUrl(Listing $listing): string
    {
        $current = $listing->apply_url ?: $listing->url;
        $source = (string) ($listing->source ?: 'azubiyo');
        $target = ApplyUrlPreference::applyTarget($source, $current);

        if ($target !== 'board' || $source !== 'azubiyo') {
            return $target;
        }

        $resolved = $this->resolve(is_string($current) ? $current : null);

        if (
            $resolved === null
            || $resolved === $current
            || ApplyUrlPreference::isBoardHost('azubiyo', $resolved)
        ) {
            return $target;
        }

        try {
            $listing->apply_url = $resolved;
            $listing->save();
        } catch (\Throwable) {
            // e.g. legacy varchar(255) until widen migration runs — keep board URL.
            $listing->refresh();

            return $target;
        }

        return 'company';
    }

    public function extractInterstitialCompanyUrl(string $html): ?string
    {
        $candidates = [];

        // Angular: data-ng-init="setApplication(2, &quot;https://…\u0026…&quot;, false)"
        if (preg_match(
            '/setApplication\s*\(\s*\d+\s*,\s*(?:&quot;|["\'])(https?:\/\/.+?)(?:&quot;|["\'])\s*,/is',
            $html,
            $matches,
        ) === 1) {
            $candidates[] = $matches[1];
        }

        // Fallback CTA: “Dann klicke bitte hier” → employer URL.
        if (preg_match(
            '/Bewerbungssystem.*?href\s*=\s*"(https?:\/\/[^"]+)"[^>]*>\s*hier\s*</is',
            $html,
            $matches,
        ) === 1) {
            $candidates[] = $matches[1];
        }

        // Copy-link button embeds the same target.
        if (preg_match(
            '/copyTextToClipboard\s*\(\s*\$event\s*,\s*[\'"](https?:\/\/[^\'"]+)[\'"]/i',
            $html,
            $matches,
        ) === 1) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $raw) {
            $decoded = $this->decodeInterstitialUrl($raw);

            if ($decoded !== null && ! ApplyUrlPreference::isBoardHost('azubiyo', $decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function decodeInterstitialUrl(string $raw): ?string
    {
        $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $url,
        ) ?? $url;
        $url = stripcslashes($url);
        $url = trim($url);

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        return $url;
    }
}
