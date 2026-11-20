<?php

namespace App\Services\Apply;

use App\Enums\ModeBBrowserOutcome;

final readonly class ModeBBrowserResult
{
    /**
     * @param  array<string, mixed>|null  $formPlan
     */
    public function __construct(
        public ModeBBrowserOutcome $outcome,
        public ?string $confirmationReference = null,
        public ?string $detail = null,
        public ?array $formPlan = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $formPlan
     */
    public static function success(string $confirmationReference, ?array $formPlan = null): self
    {
        return new self(ModeBBrowserOutcome::Success, $confirmationReference, formPlan: $formPlan);
    }

    /**
     * @param  array<string, mixed>|null  $formPlan
     */
    public static function identityMismatch(?string $detail = null, ?array $formPlan = null): self
    {
        return new self(ModeBBrowserOutcome::IdentityMismatch, detail: $detail, formPlan: $formPlan);
    }

    public static function challenge(?string $detail = null): self
    {
        return new self(ModeBBrowserOutcome::Challenge, detail: $detail);
    }

    public static function retryable(?string $detail = null): self
    {
        return new self(ModeBBrowserOutcome::Retryable, detail: $detail);
    }
}
