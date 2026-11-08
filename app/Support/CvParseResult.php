<?php

namespace App\Support;

class CvParseResult
{
    public function __construct(
        public readonly ?string $industry = null,
        public readonly ?string $city = null,
        public readonly ?string $province = null,
        public readonly ?string $language = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function presentFields(): array
    {
        $fields = [];

        foreach (['industry', 'city', 'province', 'language'] as $field) {
            $value = $this->{$field};

            if ($value !== null && trim($value) !== '') {
                $fields[$field] = trim($value);
            }
        }

        return $fields;
    }
}
