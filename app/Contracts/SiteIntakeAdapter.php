<?php

namespace App\Contracts;

interface SiteIntakeAdapter
{
    /**
     * @return array<string, mixed>
     */
    public function search(): array;

    /**
     * @param  array<string, mixed>  $searchResult
     * @return list<array<string, mixed>>
     */
    public function parseList(array $searchResult): array;

    /**
     * @param  array<string, mixed>  $listItem
     * @return array<string, mixed>
     */
    public function parseDetail(array $listItem): array;
}
