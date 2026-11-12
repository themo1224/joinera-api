<?php

namespace App\Services\AusbildungDe;

use App\Contracts\AusbildungDeHttpClient;
use App\Contracts\SiteIntakeAdapter;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Source key MUST be exactly `ausbildung.de` when registering / upserting.
 *
 * List selectors: [data-testid="jp-card"], a[href*="/stellen/"],
 * [data-testid="jp-title|jp-customer|jp-branches"]. external_id = trailing UUID
 * on /stellen/{slug}-{uuid}/ (never JobPosting identifier).
 *
 * Detail selectors: application/ld+json JobPosting; Schulabschluss fact box;
 * a[data-testid="application-button"] for apply_url.
 *
 * Pagination: provisional ?page=N (Fake honors it; live SSR may ignore and
 * return duplicates — search stops on empty page or zero new external_ids).
 * Hard cap: joinera.sites.adapters.ausbildung_de.max_pages (default 5).
 */
class AusbildungDeIntakeAdapter implements SiteIntakeAdapter
{
    private const UUID_PATTERN = '/-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/?$/i';

    public function __construct(
        private readonly AusbildungDeHttpClient $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(): array
    {
        $maxPages = max(1, (int) config('joinera.sites.adapters.ausbildung_de.max_pages', 5));
        $seenIds = [];
        $pages = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = $this->searchPageUrl($page);
            $body = $this->http->get($url);
            $pageItems = $this->parseBodyItems($body);

            if ($pageItems === []) {
                break;
            }

            $newCount = 0;

            foreach ($pageItems as $item) {
                $id = (string) ($item['external_id'] ?? '');

                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $newCount++;
            }

            $pages[] = [
                'page' => $page,
                'url' => $url,
                'body' => $body,
            ];

            if ($newCount === 0) {
                break;
            }
        }

        return [
            'pages' => $pages,
            // Backward-compatible alias for page 1 body.
            'body' => $pages[0]['body'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $searchResult
     * @return list<array<string, mixed>>
     */
    public function parseList(array $searchResult): array
    {
        $bodies = [];

        if (isset($searchResult['pages']) && is_array($searchResult['pages'])) {
            foreach ($searchResult['pages'] as $page) {
                if (is_array($page) && is_string($page['body'] ?? null)) {
                    $bodies[] = $page['body'];
                }
            }
        } elseif (is_string($searchResult['body'] ?? null) && $searchResult['body'] !== '') {
            $bodies[] = $searchResult['body'];
        }

        $items = [];
        $seenIds = [];

        foreach ($bodies as $body) {
            foreach ($this->parseBodyItems($body) as $item) {
                $id = (string) ($item['external_id'] ?? '');

                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseBodyItems(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $dom = new DOMDocument;
        @$dom->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $cards = $xpath->query('//*[@data-testid="jp-card"]');

        if ($cards === false) {
            return [];
        }

        $baseUrl = $this->baseUrl();
        $items = [];

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $item = $this->parseCard($card, $xpath, $baseUrl);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function searchPageUrl(int $page): string
    {
        $base = (string) config(
            'joinera.sites.adapters.ausbildung_de.search_url',
            'https://www.ausbildung.de/suche/',
        );

        if ($page <= 1) {
            return $base;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'www.ausbildung.de';
        $path = $parts['path'] ?? '/suche/';
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['page'] = $page;

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.$path.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array
    {
        $url = trim((string) ($listItem['url'] ?? ''));

        if ($url === '') {
            throw new RuntimeException('Ausbildung.de list item is missing url for parseDetail.');
        }

        $html = $this->http->get($url);
        $jobPosting = $this->extractJobPosting($html);
        [$dom, $xpath] = $this->loadDom($html);

        $title = $this->nonEmptyString($jobPosting['title'] ?? null)
            ?? $this->firstText($xpath, $dom, '//h1')
            ?? $this->nonEmptyString($listItem['title'] ?? null);

        $company = $this->nonEmptyString(data_get($jobPosting, 'hiringOrganization.name'))
            ?? $this->nonEmptyString($listItem['company'] ?? null);

        $city = $this->nonEmptyString(data_get($jobPosting, 'jobLocation.address.addressLocality'))
            ?? $this->nonEmptyString($listItem['city'] ?? null);

        $country = $this->normalizeCountry(
            data_get($jobPosting, 'jobLocation.address.addressCountry.name')
                ?? data_get($jobPosting, 'jobLocation.address.addressCountry')
        ) ?? 'DE';

        $description = $this->nonEmptyString($jobPosting['description'] ?? null);
        $education = $this->extractEducationRequirement($jobPosting, $xpath, $dom);
        $applyUrl = $this->extractApplyUrl($xpath, $dom, $url);
        $postedAt = $this->nonEmptyString($jobPosting['datePosted'] ?? null);
        $externalId = $this->resolveExternalId($listItem, $url);

        return [
            'external_id' => $externalId,
            'kind' => $this->nonEmptyString($listItem['kind'] ?? null) ?? 'ausbildung',
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'url' => $url,
            'apply_url' => $applyUrl,
            'description' => $description,
            'language_requirement' => null,
            'education_requirement' => $education,
            'country' => $country,
            'posted_at' => $postedAt,
            'category' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCard(DOMElement $card, DOMXPath $xpath, string $baseUrl): ?array
    {
        $href = $this->firstAttribute($xpath, $card, './/a[contains(@href, "/stellen/")]', 'href');

        if ($href === null || $href === '') {
            return null;
        }

        $url = $this->absolutizeUrl($href, $baseUrl);
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (! preg_match(self::UUID_PATTERN, $path, $matches)) {
            return null;
        }

        $externalId = strtolower($matches[1]);
        $title = $this->firstText($xpath, $card, './/*[@data-testid="jp-title"]');
        $company = $this->normalizeCompany($this->firstText($xpath, $card, './/*[@data-testid="jp-customer"]'));
        $city = $this->normalizeCity($this->firstText($xpath, $card, './/*[@data-testid="jp-branches"]'));

        return [
            'external_id' => $externalId,
            'url' => $url,
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'kind' => 'ausbildung',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJobPosting(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $scripts = $dom->getElementsByTagName('script');

        foreach ($scripts as $script) {
            if (! $script instanceof DOMElement) {
                continue;
            }

            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }

            $decoded = json_decode(trim($script->textContent), true);

            if (! is_array($decoded)) {
                continue;
            }

            $candidates = array_is_list($decoded) ? $decoded : [$decoded];

            foreach ($candidates as $candidate) {
                if (is_array($candidate) && ($candidate['@type'] ?? null) === 'JobPosting') {
                    return $candidate;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $jobPosting
     * @return array{0: DOMDocument, 1: DOMXPath}
     */
    private function loadDom(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return [$dom, new DOMXPath($dom)];
    }

    /**
     * @param  array<string, mixed>  $jobPosting
     */
    private function extractEducationRequirement(array $jobPosting, DOMXPath $xpath, DOMDocument $dom): ?string
    {
        $fromJson = $jobPosting['educationRequirements'] ?? null;

        if (is_string($fromJson) && trim($fromJson) !== '') {
            return trim($fromJson);
        }

        if (is_array($fromJson)) {
            $nested = $fromJson['credentialCategory']
                ?? $fromJson['name']
                ?? $fromJson['@value']
                ?? null;

            if (is_string($nested) && trim($nested) !== '') {
                return trim($nested);
            }
        }

        $titles = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fact-title ") or self::span or self::div]', $dom);

        if ($titles === false) {
            return null;
        }

        foreach ($titles as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (trim($node->textContent) !== 'Schulabschluss') {
                continue;
            }

            $sibling = $node->nextSibling;

            while ($sibling !== null && ! $sibling instanceof DOMElement) {
                $sibling = $sibling->nextSibling;
            }

            if ($sibling instanceof DOMElement) {
                $value = trim(preg_replace('/\s+/u', ' ', $sibling->textContent) ?? '');

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractApplyUrl(DOMXPath $xpath, DOMDocument $dom, string $listingUrl): ?string
    {
        $href = $this->firstAttribute(
            $xpath,
            $dom,
            '//a[@data-testid="application-button"]',
            'href',
        );

        if ($href === null) {
            return null;
        }

        $applyUrl = $this->absolutizeUrl($href, $this->baseUrl());

        if ($applyUrl === $listingUrl) {
            return null;
        }

        return $applyUrl;
    }

    /**
     * @param  array<string, mixed>  $listItem
     */
    private function resolveExternalId(array $listItem, string $url): ?string
    {
        $fromList = $this->nonEmptyString($listItem['external_id'] ?? null);

        if ($fromList !== null) {
            return strtolower($fromList);
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match(self::UUID_PATTERN, $path, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    private function normalizeCountry(mixed $country): ?string
    {
        if (is_array($country)) {
            $country = $country['name'] ?? $country['@value'] ?? null;
        }

        if (! is_string($country) || trim($country) === '') {
            return null;
        }

        return strtoupper(trim($country));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config(
            'joinera.sites.adapters.ausbildung_de.base_url',
            'https://www.ausbildung.de',
        ), '/');
    }

    private function firstAttribute(DOMXPath $xpath, DOMNode $context, string $query, string $attribute): ?string
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $value = $node->getAttribute($attribute);

        return $value !== '' ? $value : null;
    }

    private function firstText(DOMXPath $xpath, DOMNode $context, string $query): ?string
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $nodes->item(0)?->textContent ?? '') ?? '');

        return $text !== '' ? $text : null;
    }

    private function normalizeCompany(?string $company): ?string
    {
        if ($company === null) {
            return null;
        }

        $normalized = preg_replace('/^bei\s+/iu', '', $company) ?? $company;
        $normalized = trim($normalized);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeCity(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $parts = explode(' + ', $city, 2);
        $normalized = trim($parts[0]);

        return $normalized !== '' ? $normalized : null;
    }

    private function absolutizeUrl(string $href, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }

        return $baseUrl.'/'.ltrim($href, '/');
    }
}
