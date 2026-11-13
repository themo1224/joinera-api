<?php

namespace App\Support;

use App\Enums\ApplyCapWindow;

final readonly class ApplyCapSettings
{
    public function __construct(
        public int $cap,
        public ApplyCapWindow $window,
    ) {}

    public static function fromConfig(): self
    {
        $cap = max(1, (int) config('joinera.apply.cap', 10));
        $raw = config('joinera.apply.cap_window', 'daily');
        $window = is_string($raw)
            ? ApplyCapWindow::tryFrom(strtolower(trim($raw)))
            : null;

        return new self($cap, $window ?? ApplyCapWindow::Daily);
    }
}
