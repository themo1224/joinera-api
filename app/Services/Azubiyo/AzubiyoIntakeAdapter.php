<?php

namespace App\Services\Azubiyo;

use App\Contracts\AzubiyoHttpClient;
use App\Contracts\SiteIntakeAdapter;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Source key MUST be exactly `azubiyo` when registering / upserting.
 *
 * List selectors (live-aligned): div.job-offer-teaser,
 * a[href*="/stellenanzeigen/"], span.h3 (title), span.text-secondary (company),
 * last ul.list-unstyled li for city (PLZ + Ort).
 *
 * Detail: application/ld+json JobPosting; Schulabschluss: … fallback;
 * a[Bewerb…] with href containing bewerben for apply_url.
 * external_id = trailing token after last `_` on
 * /stellenanzeigen/{slug}_{id}/ (never JobPosting identifier / Ausbildung.de UUID).
 *
 * Pagination: /stellenmarkt/{n}/ (page 1 = bare search_url). Hard cap:
 * joinera.sites.adapters.azubiyo.max_pages (default 5). Stops on empty page
 * or zero new external_ids.
 */
class AzubiyoIntakeAdapter implements SiteIntakeAdapter
{
    private const EXTERNAL_ID_PATTERN = '#/stellenanzeigen/[^/]+_([a-z0-9]+)/?$#i';

    public function __construct(
        private readonly AzubiyoHttpClient $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(): array
    {
        $maxPages = max(1, (int) config('joinera.sites.adapters.azubiyo.max_pages', 5));
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
            'body' => $pages[0]['body'] ?? '',
        ];
    }

    private function searchPageUrl(int $page): string
    {
        $base = (string) config(
            'joinera.sites.adapters.azubiyo.search_url',
            'https://www.azubiyo.de/stellenmarkt/',
        );

        if ($page <= 1) {
            return $base;
        }

        return rtrim($base, '/').'/'.$page.'/';
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
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array
    {
        $url = trim((string) ($listItem['url'] ?? ''));

        if ($url === '') {
            throw new RuntimeException('Azubiyo list item is missing url for parseDetail.');
        }

        $html = $this->http->get($url);
        $jobPosting = $this->extractJobPosting($html);
        [$dom, $xpath] = $this->loadDom($html);

        $title = $this->nonEmptyString($jobPosting['title'] ?? null)
            ?? $this->firstText($xpath, $dom, '//h1')
            ?? $this->nonEmptyString($listItem['title'] ?? null);

        $company = $this->nonEmptyString(data_get($jobPosting, 'hiringOrganization.name'))
            ?? $this->firstText($xpath, $dom, '//*[@data-azy-company]')
            ?? $this->nonEmptyString($listItem['company'] ?? null);

        $city = $this->extractDetailCity($jobPosting, $xpath, $dom)
            ?? $this->nonEmptyString($listItem['city'] ?? null);

        $country = $this->normalizeCountry($this->jobLocationCountry($jobPosting)) ?? 'DE';

        $description = $this->nonEmptyString($jobPosting['description'] ?? null)
            ?? $this->firstText($xpath, $dom, '//*[@data-azy-description]');

        $education = $this->extractEducationRequirement($jobPosting, $xpath, $dom);
        $applyUrl = $this->extractApplyUrl($xpath, $dom, $url);
        $postedAt = $this->nonEmptyString($jobPosting['datePosted'] ?? null)
            ?? $this->firstAttribute($xpath, $dom, '//*[@data-azy-posted-at]', 'data-azy-posted-at');
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
        $cards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " job-offer-teaser ")]');

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

    /**
     * @return array<string, mixed>|null
     */
    private function parseCard(DOMElement $card, DOMXPath $xpath, string $baseUrl): ?array
    {
        $href = $this->firstAttribute(
            $xpath,
            $card,
            './/a[contains(@href, "/stellenanzeigen/")]',
            'href',
        );

        if ($href === null || $href === '') {
            return null;
        }

        $url = $this->absolutizeUrl($href, $baseUrl);
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match(self::EXTERNAL_ID_PATTERN, $path, $matches) !== 1) {
            return null;
        }

        $externalId = strtolower($matches[1]);
        $title = $this->firstText($xpath, $card, './/span[contains(concat(" ", normalize-space(@class), " "), " h3 ")]');
        $company = $this->firstText($xpath, $card, './/span[contains(concat(" ", normalize-space(@class), " "), " text-secondary ")]');
        $city = $this->extractListCity($xpath, $card);

        return [
            'external_id' => $externalId,
            'url' => $url,
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'kind' => 'ausbildung',
        ];
    }

    private function extractListCity(DOMXPath $xpath, DOMNode $card): ?string
    {
        $nodes = $xpath->query('.//ul[contains(concat(" ", normalize-space(@class), " "), " list-unstyled ")]//li', $card);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $text = trim(preg_replace('/\s+/u', ' ', $nodes->item($i)?->textContent ?? '') ?? '');

            if ($text === '') {
                continue;
            }

            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $text) === 1) {
                continue;
            }

            return $text;
        }

        return null;
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
    private function extractDetailCity(array $jobPosting, DOMXPath $xpath, DOMDocument $dom): ?string
    {
        $address = $this->jobLocationAddress($jobPosting);
        $locality = $this->nonEmptyString(data_get($address, 'addressLocality'));
        $postal = $this->nonEmptyString(data_get($address, 'postalCode'));

        if ($locality !== null) {
            // Prefer first locality token when live JSON lists multiple cities.
            $primary = trim(explode(',', $locality, 2)[0]);
            if ($postal !== null) {
                return $postal.' '.$primary;
            }

            return $primary !== '' ? $primary : $locality;
        }

        $fromDom = $this->firstText($xpath, $dom, '//*[@data-azy-city]');

        if ($fromDom === null) {
            return null;
        }

        return trim(preg_replace('/,\s*Deutschland$/iu', '', $fromDom) ?? $fromDom) ?: null;
    }

    /**
     * @param  array<string, mixed>  $jobPosting
     * @return array<string, mixed>|null
     */
    private function jobLocationAddress(array $jobPosting): ?array
    {
        $location = $jobPosting['jobLocation'] ?? null;

        if (is_array($location) && array_is_list($location)) {
            $location = $location[0] ?? null;
        }

        if (! is_array($location)) {
            return null;
        }

        $address = $location['address'] ?? null;

        return is_array($address) ? $address : null;
    }

    /**
     * @param  array<string, mixed>  $jobPosting
     */
    private function jobLocationCountry(array $jobPosting): mixed
    {
        $address = $this->jobLocationAddress($jobPosting);

        if ($address === null) {
            return null;
        }

        return $address['addressCountry']['name']
            ?? $address['addressCountry']
            ?? null;
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

        $bodyText = trim(preg_replace('/\s+/u', ' ', $dom->textContent ?? '') ?? '');

        if (preg_match('/Schulabschluss:\s*([^.;,\n]+)/iu', $bodyText, $matches) === 1) {
            $value = trim($matches[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function extractApplyUrl(DOMXPath $xpath, DOMDocument $dom, string $listingUrl): ?string
    {
        $anchors = $xpath->query('//a[contains(translate(@href, "BEWERBEN", "bewerben"), "bewerben")]');

        if ($anchors === false) {
            return null;
        }

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $applyUrl = $this->absolutizeUrl($href, $this->baseUrl());

            if ($applyUrl === $listingUrl) {
                continue;
            }

            return $applyUrl;
        }

        return null;
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

        if (preg_match(self::EXTERNAL_ID_PATTERN, $path, $matches) === 1) {
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

        $trimmed = strtoupper(trim($country));

        if ($trimmed === 'DEUTSCHLAND' || $trimmed === 'GERMANY') {
            return 'DE';
        }

        return $trimmed;
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
            'joinera.sites.adapters.azubiyo.base_url',
            'https://www.azubiyo.de',
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
