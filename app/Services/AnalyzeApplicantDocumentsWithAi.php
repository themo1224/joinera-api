<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\Enums\DocumentAssetKind;
use App\Enums\ParseStatus;
use App\Exceptions\DocumentTooLargeForAiException;
use App\Exceptions\DocumentsIncompleteException;
use App\Exceptions\LlmRequestException;
use App\Models\Profile;
use App\Models\ProfileDocumentAsset;
use App\Models\User;
use App\Services\Documents\IdentityMismatchGuard;
use App\Support\CvParseResult;
use App\Support\Documents\DocumentIngestionResult;
use App\Support\MergeProfileLanguages;
use Illuminate\Support\Facades\Storage;

class AnalyzeApplicantDocumentsWithAi
{
    private const MAX_BYTES_PER_FILE = 8 * 1024 * 1024;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly IdentityMismatchGuard $identityGuard,
    ) {
    }

    /**
     * @return array{profile: Profile, result: CvParseResult, ingestion: DocumentIngestionResult}
     *
     * @throws DocumentsIncompleteException
     * @throws DocumentTooLargeForAiException
     * @throws LlmRequestException
     */
    public function handle(User $user): array
    {
        $user->loadMissing(['cvAsset', 'profileDocumentAssets']);

        $cv = $user->cvAsset;
        $docs = $user->profileDocumentAssets->keyBy(
            static fn (ProfileDocumentAsset $asset): string => $asset->kind instanceof DocumentAssetKind
                ? $asset->kind->value
                : (string) $asset->kind
        );

        $passport = $docs->get(DocumentAssetKind::Passport->value);
        $language = $docs->get(DocumentAssetKind::LanguageCert->value);
        $education = $docs->get(DocumentAssetKind::EducationCert->value);

        if ($cv === null || $passport === null || $language === null || $education === null) {
            throw new DocumentsIncompleteException(
                'Upload CV, passport, language certificate, and education certificate before AI analyze.'
            );
        }

        // One PDF per OpenRouter request — a 4-file base64 payload often drops the TCP connection.
        $slots = [
            ['label' => 'cv', 'disk' => $cv->disk, 'path' => $cv->path, 'mime' => $cv->mime, 'name' => $cv->original_name],
            ['label' => 'passport', 'disk' => $passport->disk, 'path' => $passport->path, 'mime' => $passport->mime, 'name' => $passport->original_name],
            ['label' => 'language_cert', 'disk' => $language->disk, 'path' => $language->path, 'mime' => $language->mime, 'name' => $language->original_name],
            ['label' => 'education_cert', 'disk' => $education->disk, 'path' => $education->path, 'mime' => $education->mime, 'name' => $education->original_name],
        ];

        $merged = [];
        $models = [];
        $failures = 0;
        $lastFailure = null;

        $slotIndex = 0;
        foreach ($slots as $slot) {
            if ($slotIndex > 0 && (string) config('joinera.ai.driver') === 'gemini') {
                // Free Gemini RPM is tiny (often 5–15/min). Space PDF calls so one analyze doesn't 403.
                sleep(5);
            }
            $slotIndex++;

            try {
                $part = $this->analyzeOneSlot(
                    applicantName: (string) $user->name,
                    slotLabel: $slot['label'],
                    disk: $slot['disk'],
                    path: $slot['path'],
                    mime: $slot['mime'],
                    originalName: $slot['name'],
                );
                $merged = $this->mergeFields($merged, $part['fields']);
                $models[] = $part['model'];
            } catch (LlmRequestException $e) {
                $failures++;
                $lastFailure = $e;
            }
        }

        if ($merged === [] && $failures > 0) {
            $detail = $lastFailure?->getMessage() ?: 'unknown AI error';
            throw new LlmRequestException(
                'AI could not analyze the documents: '.$detail,
                $lastFailure?->getCode() ?: null,
                $lastFailure,
            );
        }

        $suggestedName = $this->stringField($merged, 'suggested_name')
            ?? $this->stringField($merged, 'name');
        $dateOfBirth = $this->stringField($merged, 'date_of_birth');
        $detectedNames = $suggestedName !== null ? [$suggestedName] : [];

        $guard = $this->identityGuard->evaluate((string) $user->name, $detectedNames);
        $blocked = ! $guard['ok'];

        $ingestion = new DocumentIngestionResult(
            preferredOccupation: $this->stringField($merged, 'preferred_occupation'),
            about: $this->stringField($merged, 'about'),
            education: $this->stringField($merged, 'education'),
            desiredPath: $this->stringField($merged, 'desired_path'),
            educationEntries: $this->arrayField($merged, 'education_entries'),
            experiences: $this->arrayField($merged, 'experiences'),
            skills: $this->arrayField($merged, 'skills'),
            languages: $this->languageEntries($merged),
            drivingLicenses: $this->stringListField($merged, 'driving_licenses'),
            suggestedName: $suggestedName,
            dateOfBirth: $dateOfBirth,
            detectedNames: $detectedNames,
            identityGuard: $guard,
            autoApplyBlocked: $blocked,
            meta: [
                'ai_status' => 'ran',
                'ai_model' => $models[0] ?? null,
                'ai_slot_failures' => $failures,
                'source' => 'openrouter_pdf_per_slot',
            ],
        );

        $result = CvParseResult::fromIngestion($ingestion);
        $profile = $user->ensureProfile();
        $this->applyResult($user, $profile, $result);
        $profile->parse_status = ParseStatus::Parsed;
        $profile->save();
        $user->save();

        return [
            'profile' => $profile->fresh() ?? $profile,
            'result' => $result,
            'ingestion' => $ingestion,
        ];
    }

    /**
     * @return array{fields: array<string, mixed>, model: string}
     *
     * @throws DocumentTooLargeForAiException
     * @throws DocumentsIncompleteException
     * @throws LlmRequestException
     */
    private function analyzeOneSlot(
        string $applicantName,
        string $slotLabel,
        string $disk,
        string $path,
        ?string $mime,
        ?string $originalName,
    ): array {
        $filePart = $this->contentPartForAsset($slotLabel, $disk, $path, $mime, $originalName);

        $options = [
            'temperature' => 0,
            'max_tokens' => 1200,
        ];

        // OpenRouter PDF plugin only — Gemini uses native inline PDF/image parts.
        if ((string) config('joinera.ai.driver') === 'openrouter') {
            $engine = trim((string) config('joinera.ai.openrouter.pdf_engine', 'mistral-ocr'));
            if ($engine === '') {
                $engine = 'mistral-ocr';
            }
            $options['plugins'] = [
                [
                    'id' => 'file-parser',
                    'pdf' => ['engine' => $engine],
                ],
            ];
        }

        $chat = $this->llm->chat([
            [
                'role' => 'system',
                'content' => 'You extract structured Joinera applicant profile fields from one uploaded CV or certificate PDF/image. Reply with JSON only.',
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Applicant display name so far: {$applicantName}\n"
                            ."Document slot: {$slotLabel}\n"
                            .'Extract Joinera profile fields from this document. Return ONLY compact JSON with keys among: '
                            .'suggested_name, date_of_birth, preferred_occupation, about, education, education_entries, '
                            .'experiences, skills, languages, driving_licenses, desired_path. '
                            .'Use ISO date YYYY-MM-DD for date_of_birth. Use CEFR A1-C2 or native for languages[].level. '
                            .'Do not invent names, employers, degrees, languages, or dates not supported by the document. '
                            .'Leave keys out rather than guessing.',
                    ],
                    $filePart,
                ],
            ],
        ], $options);

        return [
            'fields' => $this->decodeFields($chat->content),
            'model' => $chat->model,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeFields(array $base, array $incoming): array
    {
        foreach (['suggested_name', 'name', 'date_of_birth', 'preferred_occupation', 'about', 'education', 'desired_path'] as $key) {
            if (! isset($base[$key]) || $base[$key] === null || $base[$key] === '') {
                if (isset($incoming[$key]) && is_string($incoming[$key]) && trim($incoming[$key]) !== '') {
                    $base[$key] = trim($incoming[$key]);
                }
            }
        }

        foreach (['education_entries', 'experiences', 'skills'] as $key) {
            $existing = $this->arrayField($base, $key);
            $extra = $this->arrayField($incoming, $key);
            if ($existing === [] && $extra !== []) {
                $base[$key] = $extra;
            } elseif ($existing !== [] && $extra !== []) {
                $base[$key] = array_merge($existing, $extra);
            }
        }

        $langMap = [];
        foreach ($this->languageEntries($base) as $row) {
            $langMap[$row['language']] = $row;
        }
        foreach ($this->languageEntries($incoming) as $row) {
            $langMap[$row['language']] ??= $row;
        }
        if ($langMap !== []) {
            $base['languages'] = array_values($langMap);
        }

        $licenses = array_values(array_unique([
            ...$this->stringListField($base, 'driving_licenses'),
            ...$this->stringListField($incoming, 'driving_licenses'),
        ]));
        if ($licenses !== []) {
            $base['driving_licenses'] = $licenses;
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPartForAsset(
        string $slotLabel,
        string $disk,
        string $path,
        ?string $mime,
        ?string $originalName,
    ): array {
        if (! Storage::disk($disk)->exists($path)) {
            throw new DocumentsIncompleteException("Stored {$slotLabel} file is missing.");
        }

        $size = (int) Storage::disk($disk)->size($path);
        if ($size <= 0) {
            throw new DocumentsIncompleteException("Stored {$slotLabel} file is empty.");
        }
        if ($size > self::MAX_BYTES_PER_FILE) {
            throw new DocumentTooLargeForAiException(
                "{$slotLabel} exceeds the 8MB AI analyze limit. Split or compress the PDF."
            );
        }

        $bytes = Storage::disk($disk)->get($path);
        if (! is_string($bytes) || $bytes === '') {
            throw new DocumentsIncompleteException("Stored {$slotLabel} file could not be read.");
        }

        $mime = strtolower(trim((string) ($mime ?: '')));
        $filename = $originalName !== null && trim($originalName) !== ''
            ? basename($originalName)
            : basename($path);
        $isPdf = str_contains($mime, 'pdf') || str_ends_with(strtolower($filename), '.pdf');

        if ($isPdf) {
            return [
                'type' => 'file',
                'file' => [
                    'filename' => $filename !== '' ? $filename : $slotLabel.'.pdf',
                    'file_data' => 'data:application/pdf;base64,'.base64_encode($bytes),
                ],
            ];
        }

        $imageMime = match (true) {
            str_contains($mime, 'png') => 'image/png',
            str_contains($mime, 'webp') => 'image/webp',
            str_contains($mime, 'gif') => 'image/gif',
            default => 'image/jpeg',
        };

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$imageMime.';base64,'.base64_encode($bytes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFields(string $content): array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/\{.*\}/s', $trimmed, $m)) {
            $trimmed = $m[0];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function stringField(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function arrayField(array $fields, string $key): array
    {
        $value = $fields[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<array{language: string, level: string}>
     */
    private function languageEntries(array $fields): array
    {
        $out = [];
        foreach ($this->arrayField($fields, 'languages') as $row) {
            if (! isset($row['language'], $row['level'])) {
                continue;
            }
            $language = trim((string) $row['language']);
            $level = trim((string) $row['level']);
            if ($language === '' || $level === '') {
                continue;
            }
            $out[] = ['language' => $language, 'level' => $level];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function stringListField(array $fields, string $key): array
    {
        $value = $fields[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function applyResult(User $user, Profile $profile, CvParseResult $result): void
    {
        if ($result->autoApplyBlocked) {
            return;
        }

        $suggestedName = $result->effectiveSuggestedName();
        if ($suggestedName !== null) {
            $user->name = $suggestedName;
        }

        foreach ($result->presentStructuredFields() as $field => $value) {
            if ($profile->isFieldEdited($field)) {
                continue;
            }

            if ($field === 'languages' && is_array($value)) {
                $profile->languages = MergeProfileLanguages::merge(
                    is_array($profile->languages) ? $profile->languages : [],
                    $value
                );
                continue;
            }

            $current = $profile->{$field};
            if (is_array($current) && $current !== []) {
                continue;
            }
            if (is_string($current) && trim($current) !== '') {
                continue;
            }

            $profile->{$field} = $value;
        }

        $profile->syncEducationFromEntriesIfEmpty();
    }
}
