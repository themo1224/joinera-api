<?php

namespace App\Services\ArbeitsagenturDe;

use App\Contracts\ArbeitsagenturDeHttpClient;
use RuntimeException;

class FakeArbeitsagenturDeHttpClient implements ArbeitsagenturDeHttpClient
{
    private string $fixturesRoot;

    /** @var array<string, string> path/query substring => fixture filename */
    private array $pathOverrides;

    /** @var list<string> */
    public array $requestedUrls = [];

    /**
     * @param  array<string, string>  $pathOverrides
     */
    public function __construct(?string $fixturesRoot = null, array $pathOverrides = [])
    {
        $this->fixturesRoot = $fixturesRoot
            ?? base_path('tests/Fixtures/intake/arbeitsagentur.de');
        $this->pathOverrides = $pathOverrides;
    }

    /**
     * @param  array<string, string>  $pathOverrides
     */
    public function withPathOverrides(array $pathOverrides): self
    {
        $clone = clone $this;
        $clone->pathOverrides = $pathOverrides;
        $clone->requestedUrls = [];

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string
    {
        $this->requestedUrls[] = $url;

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        $fixture = $this->resolveFixture($path, $query);

        $file = $this->fixturesRoot.DIRECTORY_SEPARATOR.$fixture;

        if (! is_file($file)) {
            throw new RuntimeException("Arbeitsagentur.de fixture missing: {$file}");
        }

        $body = file_get_contents($file);

        if ($body === false) {
            throw new RuntimeException("Unable to read Arbeitsagentur.de fixture: {$file}");
        }

        return $body;
    }

    private function resolveFixture(string $path, string $query): string
    {
        $haystack = $path.($query !== '' ? '?'.$query : '');

        foreach ($this->pathOverrides as $needle => $fixture) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $fixture;
            }
        }

        if (str_contains($path, '/jobdetails/')) {
            return 'jobs_detail_sample.json';
        }

        if (preg_match('#/ausbildungsangebot/(\d+)#', $path) === 1) {
            return 'ausbildung_detail_sample.json';
        }

        if (str_contains($path, '/ausbildungsangebot')) {
            parse_str($query, $params);
            $apiPage = isset($params['page']) ? (int) $params['page'] : 0;

            if ($apiPage >= 2) {
                return 'ausbildung_search_empty.json';
            }

            if ($apiPage >= 1) {
                return 'ausbildung_search_page_2.json';
            }

            return 'ausbildung_search_page_1.json';
        }

        if (str_contains($path, '/jobs')) {
            parse_str($query, $params);
            $apiPage = isset($params['page']) ? (int) $params['page'] : 1;

            if ($apiPage >= 3) {
                return 'jobs_search_empty.json';
            }

            if ($apiPage >= 2) {
                return 'jobs_search_page_2.json';
            }

            return 'jobs_search_page_1.json';
        }

        throw new RuntimeException("No Arbeitsagentur.de fixture mapped for path: {$path}");
    }
}
