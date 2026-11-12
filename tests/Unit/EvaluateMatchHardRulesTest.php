<?php

namespace Tests\Unit;

use App\Enums\MatchHardSkipReason;
use App\Models\Profile;
use App\Services\EvaluateMatchHardRules;
use Tests\Fixtures\Match\HardRuleListings;
use Tests\TestCase;

class EvaluateMatchHardRulesTest extends TestCase
{
    public function test_pass_all_hard_rules(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT'),
            HardRuleListings::make('passAll'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_industry_mismatch_fails_c(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT'),
            HardRuleListings::make('industryMismatch'),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MatchHardSkipReason::Industry, $result['hard_skip_reason']);
    }

    public function test_unknown_listing_category_does_not_fail_c(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT'),
            HardRuleListings::make('categoryUnknown'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_industry_match_is_case_insensitive(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'it'),
            HardRuleListings::make('passAll'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_non_germany_fails_e(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT'),
            HardRuleListings::make('notGermany'),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MatchHardSkipReason::NotGermany, $result['hard_skip_reason']);
    }

    public function test_education_below_requirement_fails_d(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT', education: 'Mittlere Reife'),
            HardRuleListings::make('requiresAbitur'),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MatchHardSkipReason::Education, $result['hard_skip_reason']);
    }

    public function test_missing_candidate_education_fails_d_when_required(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT', education: null),
            HardRuleListings::make('requiresAbitur'),
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MatchHardSkipReason::Education, $result['hard_skip_reason']);
    }

    public function test_higher_education_satisfies_lower_requirement(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT', education: 'Abitur'),
            HardRuleListings::make('requiresHauptschule'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_no_education_requirement_passes_d(): void
    {
        $result = $this->evaluate(
            $this->profile(industry: 'IT', education: null),
            HardRuleListings::make('noEducationReq'),
        );

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_language_and_city_do_not_hard_skip(): void
    {
        $profile = $this->profile(
            industry: 'IT',
            city: 'Munich',
            language: 'fa',
        );
        $listing = HardRuleListings::make('passAll');
        $listing->city = 'Berlin';
        $listing->language_requirement = 'de C1';

        $result = $this->evaluate($profile, $listing);

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['hard_skip_reason']);
    }

    public function test_first_failure_is_industry_before_germany(): void
    {
        $listing = HardRuleListings::make('notGermany');
        $listing->category = 'Nursing';

        $result = $this->evaluate(
            $this->profile(industry: 'IT'),
            $listing,
        );

        $this->assertFalse($result['eligible']);
        $this->assertSame(MatchHardSkipReason::Industry, $result['hard_skip_reason']);
    }

    /**
     * @return array{eligible: bool, hard_skip_reason: ?MatchHardSkipReason}
     */
    private function evaluate(Profile $profile, \App\Models\Listing $listing): array
    {
        return (new EvaluateMatchHardRules)->handle($profile, $listing);
    }

    private function profile(
        string $industry,
        ?string $education = null,
        string $city = 'Berlin',
        string $language = 'de',
    ): Profile {
        return Profile::make([
            'industry' => $industry,
            'city' => $city,
            'province' => 'BE',
            'language' => $language,
            'education' => $education,
        ]);
    }
}
