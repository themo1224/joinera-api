<?php

namespace App\Support\Documents;

use App\Enums\DocumentPageRole;
use App\Enums\PageTextKind;

final class PageProbe
{
    public function __construct(
        public readonly int $pageNumber,
        public readonly string $rawText,
        public readonly string $normalizedText,
        public readonly int $nonWsChars,
        public readonly PageTextKind $textKind,
        public readonly DocumentPageRole $role,
        public readonly bool $skipped,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->pageNumber,
            'non_ws_chars' => $this->nonWsChars,
            'text_kind' => $this->textKind->value,
            'role' => $this->role->value,
            'skipped' => $this->skipped,
        ];
    }
}
