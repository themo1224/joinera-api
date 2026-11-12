<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Models\Profile;
use App\Services\EvaluateMatchSoftSignals;
use Tests\Fixtures\Match\SoftRuleListings;
use Tests\TestCase;

class EvaluateMatchSoftSignalsTest extends TestCase
{
    public function test_matching_language_code_and_city_yields_no_soft_signals(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'de', city: 'Berlin'),
            SoftRuleListings::make('languageB1German'),
        );

        $this->assertFalse($result['has_soft_mismatch']);
        $this->assertNull($result['soft_signals']);
    }

    public function test_language_code_mismatch_flags_language_level(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'fa', city: 'Berlin'),
            SoftRuleListings::make('languageDeC1'),
        );

        $this->assertTrue($result['has_soft_mismatch']);
        $this->assertSame('language_code_mismatch', $result['soft_signals']['language_level']['reason']);
        $this->assertSame('de', $result['soft_signals']['language_level']['listing_language']);
        $this->assertSame('C1', $result['soft_signals']['language_level']['listing_level']);
        $this->assertArrayNotHasKey('hard_skip_reason', $result);
    }

    public function test_de_plus_en_covers_english(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'de+en', city: 'Berlin'),
            SoftRuleListings::make('languageEnglish'),
        );

        $this->assertFalse($result['has_soft_mismatch']);
        $this->assertNull($result['soft_signals']);
    }

    public function test_missing_language_requirement_skips_language_branch(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'fa', city: 'Berlin'),
            SoftRuleListings::make('noLanguageReq'),
        );

        $this->assertFalse($result['has_soft_mismatch']);
        $this->assertNull($result['soft_signals']);
    }

    public function test_berlin_matches_plz_prefixed_listing_city(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'de', city: 'Berlin', province: 'BE'),
            SoftRuleListings::make('cityBerlinPlz'),
        );

        $this->assertFalse($result['has_soft_mismatch']);
        $this->assertNull($result['soft_signals']);
    }

    public function test_city_mismatch_flags_location(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'de', city: 'Munich', province: 'BY'),
            SoftRuleListings::make('cityBerlinPlz'),
        );

        $this->assertTrue($result['has_soft_mismatch']);
        $this->assertSame('city_mismatch', $result['soft_signals']['location']['reason']);
        $this->assertSame('Munich', $result['soft_signals']['location']['profile_city']);
        $this->assertSame('berlin', $result['soft_signals']['location']['listing_city_normalized']);
        $this->assertArrayNotHasKey('language_level', $result['soft_signals']);
    }

    public function test_empty_listing_city_skips_location_signal(): void
    {
        $result = $this->evaluate(
            $this->profile(language: 'de', city: 'Berlin'),
            SoftRuleListings::make('noCity'),
        );

        $this->assertFalse($result['has_soft_mismatch']);
        $this->assertNull($result['soft_signals']);
    }

    public function test_has_soft_mismatch_true_only_when_payload_present(): void
    {
        $mismatch = $this->evaluate(
            $this->profile(language: 'fa', city: 'Berlin'),
            SoftRuleListings::make('languageDeC1'),
        );
        $clean = $this->evaluate(
            $this->profile(language: 'de', city: 'Berlin'),
            SoftRuleListings::make('languageB1German'),
        );

        $this->assertTrue($mismatch['has_soft_mismatch']);
        $this->assertNotNull($mismatch['soft_signals']);
        $this->assertFalse($clean['has_soft_mismatch']);
        $this->assertNull($clean['soft_signals']);
    }

    /**
     * @return array{soft_signals: ?array<string, mixed>, has_soft_mismatch: bool}
     */
    private function evaluate(Profile $profile, Listing $listing): array
    {
        return (new EvaluateMatchSoftSignals)->handle($profile, $listing);
    }

    private function profile(
        string $language,
        string $city = 'Berlin',
        string $province = 'BE',
    ): Profile {
        return Profile::make([
            'industry' => 'IT',
            'city' => $city,
            'province' => $province,
            'language' => $language,
        ]);
    }
}
