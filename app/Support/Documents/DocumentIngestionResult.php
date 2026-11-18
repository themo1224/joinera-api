<?php

namespace App\Support\Documents;

final class DocumentIngestionResult
{
    /**
     * @param  list<PageProbe>  $pages
     * @param  list<array<string, mixed>>  $educationEntries
     * @param  list<array<string, mixed>>  $experiences
     * @param  list<array<string, mixed>>  $skills
     * @param  list<array{language: string, level: string}>  $languages
     * @param  list<string>  $drivingLicenses
     * @param  list<string>  $detectedNames
     * @param  array<string, mixed>  $identityGuard
     * @param  array<string, mixed>  $passport
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $pages = [],
        public readonly ?string $preferredOccupation = null,
        public readonly ?string $about = null,
        public readonly ?string $education = null,
        public readonly ?string $desiredPath = null,
        public readonly array $educationEntries = [],
        public readonly array $experiences = [],
        public readonly array $skills = [],
        public readonly array $languages = [],
        public readonly array $drivingLicenses = [],
        public readonly ?string $suggestedName = null,
        public readonly ?string $dateOfBirth = null,
        public readonly array $detectedNames = [],
        public readonly array $identityGuard = [],
        public readonly array $passport = [],
        public readonly bool $autoApplyBlocked = false,
        public readonly array $meta = [],
        public readonly ?string $industry = null,
        public readonly ?string $language = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toStaffPayload(): array
    {
        return [
            'pages' => array_map(static fn (PageProbe $p): array => $p->toArray(), $this->pages),
            'suggested_name' => $this->suggestedName,
            'date_of_birth' => $this->dateOfBirth,
            'passport' => $this->passport,
            'identity_guard' => $this->identityGuard,
            'auto_apply_blocked' => $this->autoApplyBlocked,
            'fields' => [
                'preferred_occupation' => $this->preferredOccupation,
                'about' => $this->about,
                'education' => $this->education,
                'desired_path' => $this->desiredPath,
                'education_entries' => $this->educationEntries,
                'experiences' => $this->experiences,
                'skills' => $this->skills,
                'languages' => $this->languages,
                'driving_licenses' => $this->drivingLicenses,
                'industry' => $this->industry,
                'language' => $this->language,
            ],
            'meta' => $this->meta,
        ];
    }
}
