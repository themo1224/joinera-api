<?php

namespace Tests\Unit;

use App\Enums\DocGenerationRule;
use App\Models\Listing;
use App\Models\Profile;
use App\Services\Docs\ResolveDocGenerationRule;
use PHPUnit\Framework\TestCase;

class ResolveDocGenerationRuleTest extends TestCase
{
    public function test_same_industry_and_category_is_cover_only(): void
    {
        $rule = (new ResolveDocGenerationRule)->handle(
            $this->profile('IT'),
            $this->listing('IT'),
        );

        $this->assertSame(DocGenerationRule::CoverOnly, $rule);
    }

    public function test_comparison_is_case_insensitive(): void
    {
        $rule = (new ResolveDocGenerationRule)->handle(
            $this->profile('Nursing'),
            $this->listing(' nursing '),
        );

        $this->assertSame(DocGenerationRule::CoverOnly, $rule);
    }

    public function test_different_category_is_cv_and_cover(): void
    {
        $rule = (new ResolveDocGenerationRule)->handle(
            $this->profile('IT'),
            $this->listing('nursing'),
        );

        $this->assertSame(DocGenerationRule::CvAndCover, $rule);
    }

    public function test_missing_category_is_unknown(): void
    {
        $rule = (new ResolveDocGenerationRule)->handle(
            $this->profile('IT'),
            $this->listing(null),
        );

        $this->assertSame(DocGenerationRule::CategoryUnknown, $rule);
    }

    public function test_missing_industry_is_unknown(): void
    {
        $rule = (new ResolveDocGenerationRule)->handle(
            $this->profile(null),
            $this->listing('IT'),
        );

        $this->assertSame(DocGenerationRule::CategoryUnknown, $rule);
    }

    private function profile(?string $industry): Profile
    {
        $profile = new Profile;
        $profile->industry = $industry;

        return $profile;
    }

    private function listing(?string $category): Listing
    {
        $listing = new Listing;
        $listing->category = $category;

        return $listing;
    }
}
