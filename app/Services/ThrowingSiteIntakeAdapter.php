<?php

namespace App\Services;

use App\Contracts\SiteIntakeAdapter;
use RuntimeException;

class ThrowingSiteIntakeAdapter implements SiteIntakeAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function search(): array
    {
        throw new RuntimeException('Fixture site intake failed.');
    }

    /**
     * @param  array<string, mixed>  $searchResult
     * @return list<array<string, mixed>>
     */
    public function parseList(array $searchResult): array
    {
        return [];
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
