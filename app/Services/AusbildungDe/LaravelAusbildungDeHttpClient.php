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
        return Http::withOptions($options)
            ->get($url)
            ->throw()
            ->body();
    }
}
