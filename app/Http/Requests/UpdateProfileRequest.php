<?php

namespace App\Http\Requests;

use App\Models\Profile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    private const SCALAR_STRINGS = [
        'industry',
        'city',
        'province',
        'language',
        'education',
        'desired_path',
        'preferred_occupation',
        'work_residence_note',
        'about',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'industry' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'province' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'language' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'education' => ['sometimes', 'nullable', 'string', 'max:255'],
            'desired_path' => ['sometimes', 'nullable', 'string', Rule::in(Profile::DESIRED_PATHS)],
            'preferred_occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'available_from' => ['sometimes', 'nullable', 'date'],
            'willing_to_relocate' => ['sometimes', 'nullable', 'boolean'],
            'education_entries' => ['sometimes', 'nullable', 'array', 'max:20'],
            'education_entries.*.level' => ['nullable', 'string', 'max:255'],
            'education_entries.*.institution' => ['nullable', 'string', 'max:255'],
            'education_entries.*.program' => ['nullable', 'string', 'max:255'],
            'education_entries.*.start' => ['nullable', 'string', 'max:32'],
            'education_entries.*.end' => ['nullable', 'string', 'max:32'],
            'education_entries.*.status' => ['nullable', 'string', Rule::in(['completed', 'ongoing'])],
            'education_entries.*.grade_note' => ['nullable', 'string', 'max:255'],
            'experiences' => ['sometimes', 'nullable', 'array', 'max:30'],
            'experiences.*.title' => ['nullable', 'string', 'max:255'],
            'experiences.*.organization' => ['nullable', 'string', 'max:255'],
            'experiences.*.location' => ['nullable', 'string', 'max:255'],
            'experiences.*.start' => ['nullable', 'string', 'max:32'],
            'experiences.*.end' => ['nullable', 'string', 'max:32'],
            'experiences.*.current' => ['nullable', 'boolean'],
            'experiences.*.description' => ['nullable', 'string', 'max:2000'],
            'skills' => ['sometimes', 'nullable', 'array', 'max:50'],
            'skills.*.name' => ['nullable', 'string', 'max:255'],
            'skills.*.level' => ['nullable', Rule::in(['basic', 'good', 'very_good', '1', '2', '3', '4', '5', 1, 2, 3, 4, 5])],
            'languages' => ['sometimes', 'nullable', 'array', 'max:20'],
            'languages.*.language' => ['nullable', 'string', 'max:255'],
            'languages.*.level' => ['nullable', Rule::in(['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native'])],
            'driving_licenses' => ['sometimes', 'nullable', 'array', 'max:10'],
            'driving_licenses.*' => ['string', 'max:32'],
            'work_residence_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'about' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['industry', 'city', 'province', 'language'] as $field) {
                if (! $this->exists($field)) {
                    continue;
                }

                if (trim((string) $this->input($field)) === '') {
                    $validator->errors()->add($field, "The {$field} field cannot be empty.");
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFields(): array
    {
        $fields = [];

        foreach (self::SCALAR_STRINGS as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $raw = $this->input($field);
            if ($raw === null) {
                $fields[$field] = null;

                continue;
            }

            $trimmed = trim((string) $raw);
            $fields[$field] = $trimmed === '' ? null : $trimmed;
        }

        if ($this->exists('available_from')) {
            $raw = $this->input('available_from');
            $fields['available_from'] = $raw === null || $raw === '' ? null : (string) $raw;
        }

        if ($this->exists('willing_to_relocate')) {
            $fields['willing_to_relocate'] = $this->input('willing_to_relocate') === null
                ? null
                : $this->boolean('willing_to_relocate');
        }

        if ($this->exists('education_entries')) {
            $fields['education_entries'] = $this->normalizeEducationEntries($this->input('education_entries'));
        }

        if ($this->exists('experiences')) {
            $fields['experiences'] = $this->normalizeExperiences($this->input('experiences'));
        }

        if ($this->exists('skills')) {
            $fields['skills'] = $this->normalizeSkills($this->input('skills'));
        }

        if ($this->exists('languages')) {
            $fields['languages'] = $this->normalizeLanguages($this->input('languages'));
        }

        if ($this->exists('driving_licenses')) {
            $fields['driving_licenses'] = $this->normalizeDrivingLicenses($this->input('driving_licenses'));
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeEducationEntries(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = [
                'level' => $this->nullableTrim($row['level'] ?? null),
                'institution' => $this->nullableTrim($row['institution'] ?? null),
                'program' => $this->nullableTrim($row['program'] ?? null),
                'start' => $this->nullableTrim($row['start'] ?? null),
                'end' => $this->nullableTrim($row['end'] ?? null),
                'status' => $this->nullableTrim($row['status'] ?? null),
                'grade_note' => $this->nullableTrim($row['grade_note'] ?? null),
            ];
            if ($this->rowHasValue($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeExperiences(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $entry = [
                'title' => $this->nullableTrim($row['title'] ?? null),
                'organization' => $this->nullableTrim($row['organization'] ?? null),
                'location' => $this->nullableTrim($row['location'] ?? null),
                'start' => $this->nullableTrim($row['start'] ?? null),
                'end' => $this->nullableTrim($row['end'] ?? null),
                'current' => (bool) ($row['current'] ?? false),
                'description' => $this->nullableTrim($row['description'] ?? null),
            ];
            if ($this->rowHasValue([
                $entry['title'],
                $entry['organization'],
                $entry['location'],
                $entry['start'],
                $entry['end'],
                $entry['description'],
            ]) || $entry['current']) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSkills(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->nullableTrim($row['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $level = $row['level'] ?? null;
            if (is_int($level)) {
                $level = (string) $level;
            } elseif (is_string($level)) {
                $level = trim($level) === '' ? null : trim($level);
            } else {
                $level = null;
            }
            $out[] = ['name' => $name, 'level' => $level];
        }

        return $out;
    }

    /**
     * @return list<array{language: string, level: string|null}>
     */
    private function normalizeLanguages(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = $this->nullableTrim($row['language'] ?? null);
            if ($language === null) {
                continue;
            }
            $level = $this->nullableTrim($row['level'] ?? null);
            $out[] = ['language' => $language, 'level' => $level];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeDrivingLicenses(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $value = $this->nullableTrim(is_string($item) || is_numeric($item) ? (string) $item : null);
            if ($value !== null) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function rowHasValue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if ($value !== null && ! is_string($value) && $value !== false) {
                return true;
            }
        }

        return false;
    }
}
