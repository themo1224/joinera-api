<?php

namespace App\Services\AusbildungDe;

use App\Contracts\AusbildungDeHttpClient;
use RuntimeException;

class FakeAusbildungDeHttpClient implements AusbildungDeHttpClient
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
            ?? base_path('tests/Fixtures/intake/ausbildung.de');
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
            throw new RuntimeException("Ausbildung.de fixture missing: {$file}");
        }

        $body = file_get_contents($file);

        if ($body === false) {
            throw new RuntimeException("Unable to read Ausbildung.de fixture: {$file}");
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

        if (str_contains($path, '/suche') || str_ends_with($path, '/search')) {
            parse_str($query, $params);
            $page = isset($params['page']) ? (int) $params['page'] : 1;

            if ($page >= 3) {
                return 'search_page_empty.html';
            }

            if ($page >= 2) {
                return 'search_page_2.html';
            }

            return 'search_page_1.html';
        }

        if (
            str_contains($path, '/stellen/')
            || str_contains($path, '/stelle/')
            || str_contains($path, '/stellenangebote/')
        ) {
            return 'detail_sample.html';
        }

        throw new RuntimeException("No Ausbildung.de fixture mapped for path: {$path}");
    }
}
