<?php

namespace App\Services\ArbeitsagenturDe;

use App\Contracts\ArbeitsagenturDeHttpClient;
use App\Contracts\SiteIntakeAdapter;
use RuntimeException;

/**
 * Source key MUST be exactly `arbeitsagentur.de` when registering / upserting.
 *
 * Dual-channel JSON APIs:
 * - Jobbörse → kind=job; external_id = refnr / referenznummer
 * - Ausbildungssuche → kind=ausbildung; external_id = termin id
 *
 * Pagination: max_pages applies per channel (default 5). Jobs page starts at 1;
 * Ausbildungssuche API page starts at 0 (adapter page N → API page N-1).
 * Stops on empty page or zero new external_ids.
 */
class ArbeitsagenturDeIntakeAdapter implements SiteIntakeAdapter
{
    private const CHANNEL_JOBS = 'jobs';

    private const CHANNEL_AUSBILDUNG = 'ausbildung';

    public function __construct(
        private readonly ArbeitsagenturDeHttpClient $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(): array
    {
        $maxPages = max(1, (int) config('joinera.sites.adapters.arbeitsagentur_de.max_pages', 5));
        $pages = [];

        foreach ([self::CHANNEL_JOBS, self::CHANNEL_AUSBILDUNG] as $channel) {
            $seenIds = [];

            for ($page = 1; $page <= $maxPages; $page++) {
                $url = $this->searchPageUrl($channel, $page);
                $body = $this->http->get($url);
                $pageItems = $this->parseChannelBody($body, $channel);

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
                    'channel' => $channel,
                    'url' => $url,
                    'body' => $body,
                ];

                if ($newCount === 0) {
                    break;
                }
            }
        }

        return [
            'pages' => $pages,
            'body' => $pages[0]['body'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $searchResult
     * @return list<array<string, mixed>>
     */
    public function parseList(array $searchResult): array
    {
        $items = [];
        $seenIds = [];

        if (isset($searchResult['pages']) && is_array($searchResult['pages'])) {
            foreach ($searchResult['pages'] as $page) {
                if (! is_array($page) || ! is_string($page['body'] ?? null)) {
                    continue;
                }

                $channel = (string) ($page['channel'] ?? '');

                if ($channel !== self::CHANNEL_JOBS && $channel !== self::CHANNEL_AUSBILDUNG) {
                    continue;
                }

                foreach ($this->parseChannelBody($page['body'], $channel) as $item) {
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

        return [];
    }

    /**
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array
    {
        $kind = $this->nonEmptyString($listItem['kind'] ?? null);
        $externalId = $this->nonEmptyString($listItem['external_id'] ?? null);
        $url = $this->nonEmptyString($listItem['url'] ?? null);

        if ($kind === null || $externalId === null) {
            throw new RuntimeException('Arbeitsagentur.de list item is missing kind or external_id for parseDetail.');
        }

        if ($url === null) {
            throw new RuntimeException('Arbeitsagentur.de list item is missing url for parseDetail.');
        }

        $detailUrl = $this->detailApiUrl($kind, $externalId);
        $body = $this->http->get($detailUrl);
        $json = $this->decodeJson($body);

        if ($kind === 'job') {
            return $this->mapJobsDetail($json, $listItem, $externalId, $url);
        }

        return $this->mapAusbildungDetail($json, $listItem, $externalId, $url);
    }

    private function searchPageUrl(string $channel, int $page): string
    {
        if ($channel === self::CHANNEL_JOBS) {
            $base = rtrim((string) config(
                'joinera.sites.adapters.arbeitsagentur_de.jobs_base_url',
                'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service',
            ), '/');

            return $base.'/pc/v6/jobs?'.http_build_query([
                'angebotsart' => 1,
                'page' => $page,
                'size' => 25,
            ]);
        }

        $base = rtrim((string) config(
            'joinera.sites.adapters.arbeitsagentur_de.ausbildung_base_url',
            'https://rest.arbeitsagentur.de/infosysbub/absuche',
        ), '/');

        return $base.'/pc/v1/ausbildungsangebot?'.http_build_query([
            'sty' => 0,
            'page' => max(0, $page - 1),
            'size' => 20,
        ]);
    }

    private function detailApiUrl(string $kind, string $externalId): string
    {
        if ($kind === 'job') {
            $base = rtrim((string) config(
                'joinera.sites.adapters.arbeitsagentur_de.jobs_base_url',
                'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service',
            ), '/');

            return $base.'/pc/v4/jobdetails/'.rawurlencode(base64_encode($externalId));
        }

        $base = rtrim((string) config(
            'joinera.sites.adapters.arbeitsagentur_de.ausbildung_base_url',
            'https://rest.arbeitsagentur.de/infosysbub/absuche',
        ), '/');

        return $base.'/pc/v1/ausbildungsangebot/'.rawurlencode($externalId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseChannelBody(string $body, string $channel): array
    {
        if ($body === '') {
            return [];
        }

        $json = $this->decodeJson($body);

        if ($channel === self::CHANNEL_JOBS) {
            return $this->parseJobsList($json);
        }

        return $this->parseAusbildungList($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function parseJobsList(array $json): array
    {
        $rows = $json['stellenangebote'] ?? null;

        if (! is_array($rows)) {
            return [];
        }

        $portal = $this->portalBaseUrl();
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $externalId = $this->nonEmptyString($row['refnr'] ?? null)
                ?? $this->nonEmptyString($row['referenznummer'] ?? null);

            if ($externalId === null) {
                continue;
            }

            $hashId = $this->nonEmptyString($row['hashId'] ?? null);
            $externeUrl = $this->nonEmptyString($row['externeUrl'] ?? null);
            $url = $externeUrl;

            if ($url === null && $hashId !== null) {
                $url = $portal.'/jobsuche/jobdetail/'.rawurlencode($hashId);
            }

            if ($url === null) {
                continue;
            }

            $ort = is_array($row['arbeitsort'] ?? null) ? $row['arbeitsort'] : [];
            $city = $this->formatCity($ort['plz'] ?? null, $ort['ort'] ?? null);

            $items[] = [
                'external_id' => $externalId,
                'url' => $url,
                'title' => $this->nonEmptyString($row['beruf'] ?? null) ?? $this->nonEmptyString($row['titel'] ?? null),
                'company' => $this->nonEmptyString($row['arbeitgeber'] ?? null),
                'city' => $city,
                'kind' => 'job',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function parseAusbildungList(array $json): array
    {
        $rows = data_get($json, '_embedded.termine');

        if (! is_array($rows)) {
            return [];
        }

        $portal = $this->portalBaseUrl();
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;

            if ($id === null || $id === '' || ! is_scalar($id)) {
                continue;
            }

            $externalId = (string) $id;
            $url = $portal.'/ausbildungssuche/ausbildungsangebot/'.rawurlencode($externalId);
            $angebot = is_array($row['angebot'] ?? null) ? $row['angebot'] : [];
            $anbieter = is_array($row['bildungsanbieter'] ?? null) ? $row['bildungsanbieter'] : [];
            $adresse = is_array($anbieter['adresse'] ?? null) ? $anbieter['adresse'] : [];
            $ortStrasse = is_array($adresse['ortStrasse'] ?? null) ? $adresse['ortStrasse'] : [];

            $items[] = [
                'external_id' => $externalId,
                'url' => $url,
                'title' => $this->nonEmptyString($angebot['titel'] ?? null),
                'company' => $this->nonEmptyString($anbieter['name'] ?? null),
                'city' => $this->formatCity($ortStrasse['plz'] ?? null, $ortStrasse['name'] ?? null),
                'kind' => 'ausbildung',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    private function mapJobsDetail(array $json, array $listItem, string $externalId, string $url): array
    {
        $title = $this->nonEmptyString($json['stellenangebotsTitel'] ?? null)
            ?? $this->nonEmptyString($json['titel'] ?? null)
            ?? $this->nonEmptyString($listItem['title'] ?? null);

        $company = $this->nonEmptyString($json['arbeitgeber'] ?? null)
            ?? $this->nonEmptyString($listItem['company'] ?? null);

        $city = $this->jobsDetailCity($json)
            ?? $this->nonEmptyString($listItem['city'] ?? null);

        $description = $this->nonEmptyString($json['stellenangebotsBeschreibung'] ?? null)
            ?? $this->nonEmptyString($json['stellenbeschreibung'] ?? null);

        $applyUrl = $this->nonEmptyString($json['externeUrl'] ?? null)
            ?? $this->nonEmptyString($json['allianzpartnerUrl'] ?? null)
            ?? $url;

        $postedAt = $this->nonEmptyString($json['aktuelleVeroeffentlichungsdatum'] ?? null)
            ?? $this->nonEmptyString($json['ersteVeroeffentlichungsdatum'] ?? null);

        $category = $this->nonEmptyString($json['branche'] ?? null)
            ?? $this->nonEmptyString($json['branchengruppe'] ?? null);

        return [
            'external_id' => $externalId,
            'kind' => 'job',
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'url' => $url,
            'apply_url' => $applyUrl,
            'description' => $description,
            'language_requirement' => null,
            'education_requirement' => null,
            'country' => 'DE',
            'posted_at' => $postedAt,
            'category' => $category,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    private function mapAusbildungDetail(array $json, array $listItem, string $externalId, string $url): array
    {
        $angebot = is_array($json['angebot'] ?? null) ? $json['angebot'] : [];
        $anbieter = is_array($json['bildungsanbieter'] ?? null) ? $json['bildungsanbieter'] : [];
        $adresse = is_array($anbieter['adresse'] ?? null) ? $anbieter['adresse'] : [];
        $ortStrasse = is_array($adresse['ortStrasse'] ?? null) ? $adresse['ortStrasse'] : [];

        $title = $this->nonEmptyString($angebot['titel'] ?? null)
            ?? $this->nonEmptyString($listItem['title'] ?? null);

        $company = $this->nonEmptyString($anbieter['name'] ?? null)
            ?? $this->nonEmptyString($listItem['company'] ?? null);

        $city = $this->formatCity($ortStrasse['plz'] ?? null, $ortStrasse['name'] ?? null)
            ?? $this->nonEmptyString($listItem['city'] ?? null);

        $description = $this->nonEmptyString($angebot['inhalt'] ?? null);
        $education = $this->extractAusbildungEducation($angebot);
        $homepage = $this->nonEmptyString($anbieter['homepage'] ?? null);
        $applyUrl = $homepage !== null
            ? (str_starts_with($homepage, 'http') ? $homepage : 'https://'.$homepage)
            : $url;

        return [
            'external_id' => $externalId,
            'kind' => 'ausbildung',
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'url' => $url,
            'apply_url' => $applyUrl,
            'description' => $description,
            'language_requirement' => null,
            'education_requirement' => $education,
            'country' => 'DE',
            'posted_at' => null,
            'category' => $this->nonEmptyString($angebot['abschlussbezeichnung'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function jobsDetailCity(array $json): ?string
    {
        $orte = $json['arbeitsorte'] ?? null;

        if (! is_array($orte) || $orte === []) {
            return null;
        }

        $first = $orte[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        return $this->formatCity($first['plz'] ?? null, $first['ort'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $angebot
     */
    private function extractAusbildungEducation(array $angebot): ?string
    {
        $zugang = $this->nonEmptyString($angebot['zugang'] ?? null);

        if ($zugang === null) {
            return null;
        }

        if (preg_match('/Schulabschluss[^\n.]*/iu', $zugang, $matches) === 1) {
            return trim($matches[0]);
        }

        if (stripos($zugang, 'schul') !== false || stripos($zugang, 'abschluss') !== false) {
            return $zugang;
        }

        return null;
    }

    private function formatCity(mixed $plz, mixed $ort): ?string
    {
        $ortText = $this->nonEmptyString(is_scalar($ort) ? (string) $ort : null);
        $plzText = $this->nonEmptyString(is_scalar($plz) ? (string) $plz : null);

        if ($ortText === null && $plzText === null) {
            return null;
        }

        if ($plzText !== null && $ortText !== null) {
            return $plzText.' '.$ortText;
        }

        return $ortText ?? $plzText;
    }

    private function portalBaseUrl(): string
    {
        return rtrim((string) config(
            'joinera.sites.adapters.arbeitsagentur_de.portal_base_url',
            'https://www.arbeitsagentur.de',
        ), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Arbeitsagentur.de response is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Arbeitsagentur.de JSON root must be an object.');
        }

        return $decoded;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
