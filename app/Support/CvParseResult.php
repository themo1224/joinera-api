<?php

namespace App\Support;

use App\Support\Documents\DocumentIngestionResult;

class CvParseResult
{
    /**
     * @param  list<array<string, mixed>>  $educationEntries
     * @param  list<array<string, mixed>>  $experiences
     * @param  list<array<string, mixed>>  $skills
     * @param  list<array{language?: string, level?: string}>  $languages
     * @param  list<string>  $drivingLicenses
     * @param  array<string, mixed>  $ingestion
     */
    public function __construct(
        public readonly ?string $industry = null,
        public readonly ?string $language = null,
        public readonly ?string $education = null,
        public readonly ?string $desiredPath = null,
        public readonly ?string $preferredOccupation = null,
        public readonly ?string $about = null,
        public readonly ?string $dateOfBirth = null,
        public readonly array $educationEntries = [],
        public readonly array $experiences = [],
        public readonly array $skills = [],
        public readonly array $languages = [],
        public readonly array $drivingLicenses = [],
        public readonly bool $autoApplyBlocked = false,
        public readonly array $ingestion = [],
        public readonly ?string $suggestedName = null,
    ) {
    }

    public static function fromIngestion(DocumentIngestionResult $result): self
    {
        return new self(
            industry: $result->industry,
            language: $result->language,
            education: $result->education,
            desiredPath: $result->desiredPath,
            preferredOccupation: $result->preferredOccupation,
            about: $result->about,
            dateOfBirth: $result->dateOfBirth,
            educationEntries: $result->educationEntries,
            experiences: $result->experiences,
            skills: $result->skills,
            languages: $result->languages,
            drivingLicenses: $result->drivingLicenses,
            autoApplyBlocked: $result->autoApplyBlocked,
            ingestion: $result->toStaffPayload(),
            suggestedName: $result->suggestedName,
        );
    }

    public function effectiveSuggestedName(): ?string
    {
        if (is_string($this->suggestedName) && trim($this->suggestedName) !== '') {
            return trim($this->suggestedName);
        }

        $fromPayload = $this->ingestion['suggested_name'] ?? null;

        return is_string($fromPayload) && trim($fromPayload) !== '' ? trim($fromPayload) : null;
    }

    /**
     * Scalar named fields historically owned by the fixture parser.
     *
     * @return array<string, string>
     */
    public function presentFields(): array
    {
        $fields = [];

        foreach (['industry', 'language'] as $field) {
            $value = $this->{$field};

            if ($value !== null && trim($value) !== '') {
                $fields[$field] = trim($value);
            }
        }

        return $fields;
    }

    /**
     * Structured profile fields safe to apply when identity guard allows.
     *
     * @return array<string, mixed>
     */
    public function presentStructuredFields(): array
    {
        $fields = [];

        foreach ([
            'education' => $this->education,
            'desired_path' => $this->desiredPath,
            'preferred_occupation' => $this->preferredOccupation,
            'about' => $this->about,
            'date_of_birth' => $this->dateOfBirth,
        ] as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = trim($value);
            }
        }

        if ($this->educationEntries !== []) {
            $fields['education_entries'] = $this->educationEntries;
        }
        if ($this->experiences !== []) {
            $fields['experiences'] = $this->experiences;
        }
        if ($this->skills !== []) {
            $fields['skills'] = $this->skills;
        }
        if ($this->languages !== []) {
            $fields['languages'] = $this->languages;
        }
        if ($this->drivingLicenses !== []) {
            $fields['driving_licenses'] = $this->drivingLicenses;
        }

        return $fields;
    }
}
