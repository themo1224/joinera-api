<?php

namespace Tests\Unit;

use App\Support\ApplyUrlPreference;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplyUrlPreferenceTest extends TestCase
{
    #[DataProvider('boardHostProvider')]
    public function test_is_board_host(string $source, string $url, bool $expected): void
    {
        $this->assertSame($expected, ApplyUrlPreference::isBoardHost($source, $url));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function boardHostProvider(): array
    {
        return [
            'azubiyo www' => ['azubiyo', 'https://www.azubiyo.de/stellen/1/bewerben', true],
            'azubiyo bare' => ['azubiyo', 'https://azubiyo.de/bewerben', true],
            'azubiyo company' => ['azubiyo', 'https://karriere.firma.example.test/apply', false],
            'ausbildung board' => ['ausbildung.de', 'https://www.ausbildung.de/stellen/x/apply', true],
            'ausbildung company' => ['ausbildung.de', 'https://sonova.example.test/jobs/1/apply', false],
            'ba board' => ['arbeitsagentur.de', 'https://www.arbeitsagentur.de/jobsuche/stelle/1', true],
            'ba company' => ['arbeitsagentur.de', 'https://jobs.employer.example.test/apply', false],
        ];
    }

    public function test_apply_target_classifies_company_board_missing(): void
    {
        $this->assertSame(
            'company',
            ApplyUrlPreference::applyTarget('azubiyo', 'https://karriere.firma.example.test/apply'),
        );
        $this->assertSame(
            'board',
            ApplyUrlPreference::applyTarget('azubiyo', 'https://www.azubiyo.de/stellen/1/bewerben'),
        );
        $this->assertSame('missing', ApplyUrlPreference::applyTarget('azubiyo', null));
        $this->assertSame('missing', ApplyUrlPreference::applyTarget('azubiyo', '  '));
    }

    public function test_prefer_apply_url_picks_company_over_board(): void
    {
        $chosen = ApplyUrlPreference::preferApplyUrl(
            'azubiyo',
            'https://www.azubiyo.de/stellen/1/bewerben',
            'https://karriere.firma.example.test/bewerben',
        );

        $this->assertSame('https://karriere.firma.example.test/bewerben', $chosen);
    }

    public function test_prefer_apply_url_falls_back_to_board_when_no_company(): void
    {
        $chosen = ApplyUrlPreference::preferApplyUrl(
            'azubiyo',
            'https://www.azubiyo.de/stellen/1/bewerben',
            null,
        );

        $this->assertSame('https://www.azubiyo.de/stellen/1/bewerben', $chosen);
    }

    public function test_prefer_apply_url_returns_null_when_empty(): void
    {
        $this->assertNull(ApplyUrlPreference::preferApplyUrl('azubiyo', null, '', '  '));
    }

    public function test_should_upgrade_from_null_or_board_to_company(): void
    {
        $this->assertTrue(ApplyUrlPreference::shouldUpgradeApplyUrl(
            'azubiyo',
            null,
            'https://karriere.firma.example.test/apply',
        ));

        $this->assertTrue(ApplyUrlPreference::shouldUpgradeApplyUrl(
            'azubiyo',
            'https://www.azubiyo.de/stellen/1/bewerben',
            'https://karriere.firma.example.test/apply',
        ));

        $this->assertFalse(ApplyUrlPreference::shouldUpgradeApplyUrl(
            'azubiyo',
            'https://karriere.firma.example.test/apply',
            'https://www.azubiyo.de/stellen/1/bewerben',
        ));

        $this->assertFalse(ApplyUrlPreference::shouldUpgradeApplyUrl(
            'azubiyo',
            'https://www.azubiyo.de/stellen/1/bewerben',
            null,
        ));
    }
}
