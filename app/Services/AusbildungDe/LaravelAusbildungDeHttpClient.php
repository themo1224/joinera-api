<?php

namespace App\Services\AusbildungDe;

use App\Contracts\AusbildungDeHttpClient;
use Illuminate\Support\Facades\Http;

class LaravelAusbildungDeHttpClient implements AusbildungDeHttpClient
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string
    {
        $timeout = max(5, (int) config('joinera.sites.adapters.ausbildung_de.http_timeout', 30));

        return Http::timeout($timeout)
            ->withOptions($options)
            ->get($url)
            ->throw()
            ->body();
    }
}
