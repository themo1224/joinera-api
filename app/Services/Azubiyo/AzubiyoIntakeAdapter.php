<?php

namespace App\Services\Azubiyo;

use App\Contracts\AzubiyoHttpClient;
use App\Contracts\SiteIntakeAdapter;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Source key MUST be exactly `azubiyo` when registering / upserting.
 *
 * List selectors (live-aligned): div.job-offer-teaser,
 * a[href*="/stellenanzeigen/"], span.h3 (title), span.text-secondary (company),
 * last ul.list-unstyled li for city (PLZ + Ort).
 *
 * external_id = trailing token after last `_` on
 * /stellenanzeigen/{slug}_{id}/ (never Ausbildung.de UUID rules).
 *
 * Page 1 only for now — pagination is Plan 3D. parseDetail remains a stub.
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
        $url = (string) config(
            'joinera.sites.adapters.azubiyo.search_url',
            'https://www.azubiyo.de/stellenmarkt/',
        );
        $body = $this->http->get($url);

        return [
            'pages' => [
                [
                    'page' => 1,
                    'url' => $url,
                    'body' => $body,
                ],
            ],
            'body' => $body,
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
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array
    {
        return [];
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
        $city = $this->extractCity($xpath, $card);

        return [
            'external_id' => $externalId,
            'url' => $url,
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'kind' => 'ausbildung',
        ];
    }

    private function extractCity(DOMXPath $xpath, DOMNode $card): ?string
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

            // Skip start-date lines like 01.08.2027.
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $text) === 1) {
                continue;
            }

            // Prefer PLZ + Ort; otherwise take the last non-date line.
            return $text;
        }

        return null;
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
