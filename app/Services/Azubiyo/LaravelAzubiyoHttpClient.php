<?php

namespace App\Services\Azubiyo;

use App\Contracts\AzubiyoHttpClient;
use Illuminate\Support\Facades\Http;

class LaravelAzubiyoHttpClient implements AzubiyoHttpClient
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
