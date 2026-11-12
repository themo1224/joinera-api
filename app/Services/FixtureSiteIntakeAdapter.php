<?php

namespace App\Services;

use App\Contracts\SiteIntakeAdapter;

class FixtureSiteIntakeAdapter implements SiteIntakeAdapter
{
    private bool $includeNonDe = false;

    /** @var list<array<string, mixed>>|null */
    private ?array $overrideItems = null;

    public function includeNonDe(bool $enabled = true): self
    {
        $this->includeNonDe = $enabled;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function withItems(array $items): self
    {
        $this->overrideItems = $items;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function search(): array
    {
        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $searchResult
     * @return list<array<string, mixed>>
     */
    public function parseList(array $searchResult): array
    {
        if ($this->overrideItems !== null) {
            return $this->overrideItems;
        }

        $items = [
            [
                'external_id' => 'job-1',
                'kind' => 'job',
                'category' => 'IT',
                'title' => 'Software Engineer',
                'company' => 'Acme GmbH',
                'city' => 'Berlin',
                'url' => 'https://example.test/jobs/1',
                'apply_url' => 'https://example.test/jobs/1/apply',
                'description' => 'Build software in Berlin.',
                'language_requirement' => 'B1 German',
                'education_requirement' => 'Bachelor',
                'country' => 'DE',
                'posted_at' => '2026-11-01T10:00:00+00:00',
            ],
            [
                'external_id' => 'ausbildung-1',
                'kind' => 'ausbildung',
                'category' => null,
                'title' => 'Fachinformatiker',
                'company' => 'Tech AG',
                'city' => 'Munich',
                'url' => 'https://example.test/ausbildung/1',
                'apply_url' => null,
                'description' => null,
                'language_requirement' => null,
                'education_requirement' => null,
                'country' => 'DE',
                'posted_at' => null,
            ],
        ];

        if ($this->includeNonDe) {
            $items[] = [
                'external_id' => 'job-at-1',
                'kind' => 'job',
                'category' => 'IT',
                'title' => 'Developer Vienna',
                'company' => 'Alps GmbH',
                'city' => 'Vienna',
                'url' => 'https://example.test/jobs/at-1',
                'country' => 'AT',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array
    {
        return $listItem;
    }
}
