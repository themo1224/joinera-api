<?php

namespace App\Services\Azubiyo;

use App\Contracts\AzubiyoHttpClient;
use RuntimeException;

class FakeAzubiyoHttpClient implements AzubiyoHttpClient
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
            ?? base_path('tests/Fixtures/intake/azubiyo');
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
            throw new RuntimeException("Azubiyo fixture missing: {$file}");
        }

        $body = file_get_contents($file);

        if ($body === false) {
            throw new RuntimeException("Unable to read Azubiyo fixture: {$file}");
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

        // Detail before search: slug paths like /stellenanzeigen/ausbildung-..._
        // contain the substring "ausbildung" and must not map to search fixtures.
        if (str_contains($path, '/stellenanzeigen')) {
            return 'detail_sample.html';
        }

        if (str_contains($path, '/stellenmarkt')) {
            if (preg_match('#/stellenmarkt/(\d+)/?$#', $path, $matches) === 1) {
                $page = (int) $matches[1];

                if ($page >= 3) {
                    return 'search_page_empty.html';
                }

                if ($page >= 2) {
                    return 'search_page_2.html';
                }
            }

            return 'search_page_1.html';
        }

        if (preg_match('#(^|/)ausbildung(/|$)#', $path) === 1) {
            return 'search_page_1.html';
        }

        if (
            str_contains($path, '/stelle')
            || str_contains($path, '/stellenangebot')
            || str_contains($path, '/job')
        ) {
            return 'detail_sample.html';
        }

        throw new RuntimeException("No Azubiyo fixture mapped for path: {$path}");
    }
}
