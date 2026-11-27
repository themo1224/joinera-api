<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mode B claim preflight: treat only hard HTTP death (404/410) as dead.
 * Soft blocks (403/429/timeouts) must not false hard-fail Softgarden/Personio.
 */
class ProbeCompanyApplyUrl
{
    public function isDead(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'JoineraApplyProbe/1.0'])
                ->get($url);

            if ($this->isGoneStatus($response->status())) {
                return true;
            }

            if (! $response->successful()) {
                return false;
            }

            $next = $this->extractMetaRefreshUrl($response->body(), $url);

            if ($next === null || $next === $url) {
                return false;
            }

            $second = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'JoineraApplyProbe/1.0'])
                ->get($next);

            return $this->isGoneStatus($second->status());
        } catch (Throwable) {
            return false;
        }
    }

    private function isGoneStatus(int $status): bool
    {
        return $status === 404 || $status === 410;
    }

    private function extractMetaRefreshUrl(string $html, string $baseUrl): ?string
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
}
