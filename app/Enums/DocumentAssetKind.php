<?php

namespace App\Enums;

enum DocumentAssetKind: string
{
    case Passport = 'passport';
    case LanguageCert = 'language_cert';
    case EducationCert = 'education_cert';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $kind): string => $kind->value,
            self::cases(),
        );
    }
}
