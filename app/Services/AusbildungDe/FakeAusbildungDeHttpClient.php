<?php

namespace App\Services\AusbildungDe;

use App\Contracts\AusbildungDeHttpClient;
use RuntimeException;

class FakeAusbildungDeHttpClient implements AusbildungDeHttpClient
{
    private string $fixturesRoot;

    /** @var array<string, string> path substring => fixture filename */
    private array $pathOverrides;

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

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $fixture = $this->resolveFixture($path);

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

    private function resolveFixture(string $path): string
    {
        foreach ($this->pathOverrides as $needle => $fixture) {
            if ($needle !== '' && str_contains($path, $needle)) {
                return $fixture;
            }
        }

        if (str_contains($path, '/suche') || str_ends_with($path, '/search')) {
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
