<?php

namespace App\Services\Documents;

use App\Contracts\DocumentOcrClient;
use App\Enums\DocumentPageRole;
use App\Enums\PageTextKind;
use App\Exceptions\CvUnreadableException;
use App\Services\Documents\Parsers\CvHeuristicParser;
use App\Services\Documents\Parsers\EducationCertParser;
use App\Services\Documents\Parsers\LanguageCertParser;
use App\Services\Documents\Parsers\PassportMrzParser;
use App\Support\Documents\DocumentIngestionResult;
use App\Support\Documents\LetterSpacingNormalizer;
use App\Support\Documents\PageProbe;

final class DocumentIngestionPipeline
{
    public function __construct(
        private readonly PdfPageTextExtractor $extractor,
        private readonly LetterSpacingNormalizer $normalizer,
        private readonly PageTriage $triage,
        private readonly PageRoleClassifier $roles,
        private readonly CvHeuristicParser $cvParser,
        private readonly LanguageCertParser $languageCertParser,
        private readonly EducationCertParser $educationCertParser,
        private readonly PassportMrzParser $mrzParser,
        private readonly IdentityMismatchGuard $identityGuard,
        private readonly ResidualLlmStructurer $residualLlm,
        private readonly DocumentOcrClient $ocr,
        private readonly PdfPageImageRenderer $pageRenderer,
    ) {
    }

    /**
     * @throws CvUnreadableException
     */
    public function ingestImageFile(string $absoluteImagePath, string $expectedApplicantName = ''): DocumentIngestionResult
    {
        $raw = '';
        $ocrUsed = false;
        if ($this->ocr->isAvailable()) {
            $langs = $this->detectOcrLanguagesFromHint($absoluteImagePath);
            $raw = $this->ocr->recognizeFile($absoluteImagePath, $langs);
            $ocrUsed = $raw !== '';
        }

        return $this->ingestPageTexts([$raw], $expectedApplicantName, $ocrUsed, ! $ocrUsed);
    }

    /**
     * @throws CvUnreadableException
     */
    public function ingestFile(string $absolutePdfPath, string $expectedApplicantName = ''): DocumentIngestionResult
    {
        $pageTexts = $this->extractor->extractPageTexts($absolutePdfPath);
        $ocrUsed = false;
        $ocrSkipped = false;

        foreach ($pageTexts as $index => $raw) {
            $kind = $this->triage->classify($raw);
            $needsOcr = in_array($kind, [PageTextKind::Scan, PageTextKind::Empty, PageTextKind::Hybrid], true)
                || $this->triage->looksLikeGarbledExtract($raw);

            if (! $needsOcr) {
                continue;
            }

            if (! $this->ocr->isAvailable() || ! $this->pageRenderer->isAvailable()) {
                $ocrSkipped = true;

                continue;
            }

            $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'joinera-ocr-'.uniqid('', true).'.png';
            $rendered = $this->pageRenderer->renderPage($absolutePdfPath, $index + 1, $tmp);
            if ($rendered === null) {
                $ocrSkipped = true;

                continue;
            }

            $langs = ['deu', 'eng'];
            $ocrText = $this->ocr->recognizeFile($rendered, $langs);
            @unlink($rendered);

            if ($ocrText !== '') {
                $pageTexts[$index] = $ocrText;
                $ocrUsed = true;
            } else {
                $ocrSkipped = true;
            }
        }

        return $this->ingestPageTexts($pageTexts, $expectedApplicantName, $ocrUsed, $ocrSkipped);
    }

    /**
     * @param  list<string>  $pageTexts
     */
    private function ingestPageTexts(
        array $pageTexts,
        string $expectedApplicantName,
        bool $ocrUsed,
        bool $ocrSkipped,
    ): DocumentIngestionResult {
        $probes = [];
        $cvChunks = [];
        $langChunks = [];
        $eduChunks = [];
        $experienceChunks = [];
        $passportText = '';

        foreach ($pageTexts as $index => $raw) {
            $pageNumber = $index + 1;
            $kind = $this->triage->classify($raw);
            $normalized = $this->normalizer->normalize($raw);

            // Garbled extract: treat normalized as empty for routing keywords.
            if ($this->triage->looksLikeGarbledExtract($raw)) {
                $kind = PageTextKind::Scan;
                $normalized = '';
            }

            $role = $normalized !== ''
                ? $this->roles->classify($normalized, $kind)
                : ($kind === PageTextKind::Empty
                    ? DocumentPageRole::WatermarkOnly
                    : DocumentPageRole::Unknown);

            $skipped = $this->roles->shouldSkip($role);
            $probe = new PageProbe(
                pageNumber: $pageNumber,
                rawText: $raw,
                normalizedText: $normalized,
                nonWsChars: $this->triage->nonWsCount($normalized),
                textKind: $kind,
                role: $role,
                skipped: $skipped,
            );
            $probes[] = $probe;

            if ($skipped) {
                continue;
            }

            match ($role) {
                DocumentPageRole::Cv => $cvChunks[] = $normalized,
                DocumentPageRole::LanguageCert => $langChunks[] = $normalized,
                DocumentPageRole::EducationCert => $eduChunks[] = $normalized,
                DocumentPageRole::ExperienceProof => $experienceChunks[] = $normalized,
                DocumentPageRole::Passport => $passportText .= "\n".$normalized,
                DocumentPageRole::Unknown => $this->bucketUnknown($normalized, $cvChunks, $langChunks, $eduChunks),
                default => null,
            };
        }

        $languages = [];
        $educationEntries = [];
        $experiences = [];
        $skills = [];
        $licenses = [];
        $detectedNames = [];
        $preferredOccupation = null;
        $about = null;
        $education = null;
        $desiredPath = null;
        $dateOfBirth = null;
        $suggestedName = null;
        $passport = [];

        foreach ($langChunks as $chunk) {
            foreach ($this->languageCertParser->parse($chunk) as $entry) {
                if (isset($entry['candidate_name'])) {
                    $detectedNames[] = $entry['candidate_name'];
                }
                if (isset($entry['date_of_birth']) && $dateOfBirth === null) {
                    $dateOfBirth = $entry['date_of_birth'];
                }
                $languages[$entry['language']] = [
                    'language' => $entry['language'],
                    'level' => $entry['level'],
                ];
            }
        }

        foreach ($eduChunks as $chunk) {
            $parsed = $this->educationCertParser->parse($chunk);
            if (! empty($parsed['candidate_name'])) {
                $detectedNames[] = $parsed['candidate_name'];
            }
            if (! empty($parsed['date_of_birth']) && $dateOfBirth === null) {
                $dateOfBirth = $parsed['date_of_birth'];
            }
            foreach ($parsed['education_entries'] as $entry) {
                $educationEntries[] = $entry;
            }
            if (! empty($parsed['education']) && $education === null) {
                $education = $parsed['education'];
            }
        }

        foreach ($cvChunks as $chunk) {
            $parsed = $this->cvParser->parse($chunk);
            if (! empty($parsed['candidate_name'])) {
                $detectedNames[] = $parsed['candidate_name'];
            }
            if (! empty($parsed['date_of_birth']) && $dateOfBirth === null) {
                $dateOfBirth = $parsed['date_of_birth'];
            }
            $preferredOccupation ??= $parsed['preferred_occupation'] ?? null;
            $about ??= $parsed['about'] ?? null;
            $desiredPath ??= $parsed['desired_path'] ?? null;
            $education ??= $parsed['education'] ?? null;
            foreach ($parsed['education_entries'] as $entry) {
                $educationEntries[] = $entry;
            }
            foreach ($parsed['experiences'] as $entry) {
                $experiences[] = $entry;
            }
            foreach ($parsed['skills'] as $entry) {
                $skills[] = $entry;
            }
            foreach ($parsed['driving_licenses'] as $lic) {
                $licenses[] = $lic;
            }
            // Cert CEFR wins: only fill language if not already from cert.
            foreach ($parsed['languages'] as $entry) {
                $languages[$entry['language']] ??= $entry;
            }
        }

        foreach ($experienceChunks as $chunk) {
            // Reuse education name/date extractors lightly via CV experience heuristic.
            $parsed = $this->cvParser->parse($chunk);
            foreach ($parsed['experiences'] as $entry) {
                $experiences[] = $entry;
            }
            if (! empty($parsed['candidate_name'])) {
                $detectedNames[] = $parsed['candidate_name'];
            }
        }

        if (trim($passportText) !== '') {
            $mrz = $this->mrzParser->parseFromText($passportText);
            if ($mrz !== null) {
                $passport = $mrz;
                if (! empty($mrz['full_name'])) {
                    $detectedNames[] = $mrz['full_name'];
                    $suggestedName = $mrz['full_name'];
                }
                if (! empty($mrz['date_of_birth'])) {
                    $dateOfBirth = $mrz['date_of_birth'];
                }
            } else {
                // DE passport translation table fallback.
                $eduLike = $this->educationCertParser->parse($passportText);
                if (! empty($eduLike['candidate_name'])) {
                    $detectedNames[] = $eduLike['candidate_name'];
                    $suggestedName ??= $eduLike['candidate_name'];
                }
                if (! empty($eduLike['date_of_birth'])) {
                    $dateOfBirth ??= $eduLike['date_of_birth'];
                }
            }
        }

        $suggestedName ??= $detectedNames[0] ?? null;

        $residualChunks = array_values(array_filter(
            [...$cvChunks, ...$langChunks, ...$eduChunks, ...$experienceChunks, trim($passportText)],
            static fn (string $chunk): bool => trim($chunk) !== '',
        ));
        $residualText = implode("\n\n", $residualChunks);

        $current = [
            'preferred_occupation' => $preferredOccupation,
            'about' => $about,
            'education' => $education,
            'desired_path' => $desiredPath,
            'education_entries' => $educationEntries,
            'experiences' => $experiences,
            'skills' => $skills,
            'languages' => array_values($languages),
            'driving_licenses' => array_values(array_unique($licenses)),
            'suggested_name' => $suggestedName,
            'date_of_birth' => $dateOfBirth,
        ];

        // Call whenever residual is on and page text exists: heuristics often miss
        // name/DOB/education/experiences, and languages need multi-fill top-up by key.
        $residualStatus = 'disabled';
        if (! $this->residualLlm->enabled()) {
            $residualStatus = 'disabled';
        } elseif (trim($residualText) === '') {
            $residualStatus = 'skipped';
        } else {
            $residual = $this->residualLlm->fillGaps($residualText, $current);
            $residualStatus = $residual['status'];
            $gaps = $residual['fields'];

            $preferredOccupation ??= isset($gaps['preferred_occupation']) ? (string) $gaps['preferred_occupation'] : null;
            $about ??= isset($gaps['about']) ? (string) $gaps['about'] : null;
            $education ??= isset($gaps['education']) ? (string) $gaps['education'] : null;
            $desiredPath ??= isset($gaps['desired_path']) ? (string) $gaps['desired_path'] : null;

            $aiName = null;
            if (isset($gaps['suggested_name']) && is_string($gaps['suggested_name']) && trim($gaps['suggested_name']) !== '') {
                $aiName = trim($gaps['suggested_name']);
            } elseif (isset($gaps['name']) && is_string($gaps['name']) && trim($gaps['name']) !== '') {
                $aiName = trim($gaps['name']);
            }
            if ($aiName !== null) {
                $detectedNames[] = $aiName;
                $suggestedName ??= $aiName;
            }

            if ($dateOfBirth === null && isset($gaps['date_of_birth']) && is_string($gaps['date_of_birth']) && trim($gaps['date_of_birth']) !== '') {
                $dateOfBirth = trim($gaps['date_of_birth']);
            }

            if (isset($gaps['education_entries']) && is_array($gaps['education_entries']) && $educationEntries === []) {
                $educationEntries = $gaps['education_entries'];
            }
            if (isset($gaps['experiences']) && is_array($gaps['experiences']) && $experiences === []) {
                $experiences = $gaps['experiences'];
            }
            if (isset($gaps['skills']) && is_array($gaps['skills']) && $skills === []) {
                $skills = $gaps['skills'];
            }
            if (isset($gaps['languages']) && is_array($gaps['languages'])) {
                foreach ($gaps['languages'] as $entry) {
                    if (is_array($entry) && isset($entry['language'], $entry['level'])) {
                        $key = (string) $entry['language'];
                        $languages[$key] ??= [
                            'language' => $key,
                            'level' => (string) $entry['level'],
                        ];
                    }
                }
            }
            if (isset($gaps['driving_licenses']) && is_array($gaps['driving_licenses']) && $licenses === []) {
                foreach ($gaps['driving_licenses'] as $lic) {
                    if (is_string($lic) && $lic !== '') {
                        $licenses[] = $lic;
                    }
                }
            }
        }

        $guard = $this->identityGuard->evaluate($expectedApplicantName, $detectedNames);
        $blocked = ! $guard['ok'];

        return new DocumentIngestionResult(
            pages: $probes,
            preferredOccupation: $preferredOccupation,
            about: $about,
            education: $education,
            desiredPath: $desiredPath,
            educationEntries: $educationEntries,
            experiences: $experiences,
            skills: $skills,
            languages: array_values($languages),
            drivingLicenses: array_values(array_unique($licenses)),
            suggestedName: $suggestedName,
            dateOfBirth: $dateOfBirth,
            detectedNames: array_values(array_unique($detectedNames)),
            identityGuard: $guard,
            passport: $passport,
            autoApplyBlocked: $blocked,
            meta: [
                'ocr_used' => $ocrUsed,
                'ocr_unavailable_or_skipped' => $ocrSkipped,
                'residual_llm' => $this->residualLlm->enabled(),
                'residual_llm_status' => $residualStatus,
                'page_count' => count($probes),
            ],
        );
    }

    /**
     * @param  list<string>  $cvChunks
     * @param  list<string>  $langChunks
     * @param  list<string>  $eduChunks
     */
    private function bucketUnknown(string $normalized, array &$cvChunks, array &$langChunks, array &$eduChunks): void
    {
        $hay = mb_strtolower($normalized);
        if (str_contains($hay, 'telc') || str_contains($hay, 'goethe')) {
            $langChunks[] = $normalized;

            return;
        }
        if (str_contains($hay, 'abitur') || str_contains($hay, 'bac')) {
            $eduChunks[] = $normalized;

            return;
        }
        if (mb_strlen($normalized) > 200) {
            $cvChunks[] = $normalized;
        }
    }

    /**
     * @return list<string>
     */
    private function detectOcrLanguagesFromHint(string $path): array
    {
        return ['deu', 'eng', 'ara'];
    }
}
