<?php

namespace App\Services\ArbeitsagenturDe;

use App\Contracts\ArbeitsagenturDeHttpClient;
use Illuminate\Support\Facades\Http;

class LaravelArbeitsagenturDeHttpClient implements ArbeitsagenturDeHttpClient
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string
    {
        $headers = $this->headersForUrl($url);

        return Http::withHeaders($headers)
            ->withOptions($options)
            ->get($url)
            ->throw()
            ->body();
    }

    /**
     * @return array<string, string>
     */
    private function headersForUrl(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $headers = [];

        if (str_contains($path, '/infosysbub/absuche') || str_contains($path, '/ausbildungsangebot')) {
            $headers['X-API-Key'] = (string) config(
                'joinera.sites.adapters.arbeitsagentur_de.ausbildung_api_key',
                'infosysbub-absuche',
            );
            $headers['Accept'] = str_contains($path, '/ausbildungsangebot/')
                ? 'application/json'
                : 'application/hal+json';
        } else {
            $headers['X-API-Key'] = (string) config(
                'joinera.sites.adapters.arbeitsagentur_de.jobs_api_key',
                'jobboerse-jobsuche',
            );
            $headers['Accept'] = 'application/json';
        }

        return $headers;
    }
}
