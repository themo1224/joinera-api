<?php

namespace App\Services;

use App\Contracts\SiteIntakeAdapter;

class SiteIntakeAdapterRegistry
{
    /** @var array<string, SiteIntakeAdapter> */
    private array $adapters = [];

    public function register(string $site, SiteIntakeAdapter $adapter): void
    {
        $this->adapters[$site] = $adapter;
    }

    public function get(string $site): ?SiteIntakeAdapter
    {
        return $this->adapters[$site] ?? null;
    }
}
