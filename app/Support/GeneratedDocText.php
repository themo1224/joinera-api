<?php

namespace App\Support;

final class GeneratedDocText
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?array $usage = null,
    ) {}
}
