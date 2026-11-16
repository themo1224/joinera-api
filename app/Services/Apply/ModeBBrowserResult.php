<?php

namespace App\Services\Apply;

use App\Enums\ModeBBrowserOutcome;

final readonly class ModeBBrowserResult
{
    public function __construct(
        public ModeBBrowserOutcome $outcome,
        public ?string $confirmationReference = null,
        public ?string $detail = null,
    ) {}

    public static function success(string $confirmationReference): self
    {
        return new self(ModeBBrowserOutcome::Success, $confirmationReference);
    }

    public static function identityMismatch(?string $detail = null): self
    {
        return new self(ModeBBrowserOutcome::IdentityMismatch, detail: $detail);
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
