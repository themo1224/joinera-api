<?php

namespace App\Services\Documents;

use App\Enums\DocumentPageRole;
use App\Enums\PageTextKind;

final class PageRoleClassifier
{
    public function classify(string $normalizedText, PageTextKind $textKind): DocumentPageRole
    {
        $hay = mb_strtolower($normalizedText);
        $compact = preg_replace('/\s+/u', '', $hay) ?? '';

        if ($textKind === PageTextKind::Empty || $compact === '' || $compact === 'camscanner') {
            return DocumentPageRole::WatermarkOnly;
        }

        if ($this->isCertLegend($hay, $compact)) {
            return DocumentPageRole::CertLegend;
        }

        if ($this->isPassport($hay, $compact)) {
            return DocumentPageRole::Passport;
        }

        if ($this->isLanguageCert($hay, $compact)) {
            return DocumentPageRole::LanguageCert;
        }

        if ($this->isEducationCert($hay, $compact)) {
            return DocumentPageRole::EducationCert;
        }

        if ($this->isExperienceProof($hay)) {
            return DocumentPageRole::ExperienceProof;
        }

        if ($this->isAnschreiben($hay, $compact)) {
            return DocumentPageRole::Anschreiben;
        }

        if ($this->isCv($hay, $compact)) {
            return DocumentPageRole::Cv;
        }

        if ($this->isCover($hay, $compact)) {
            return DocumentPageRole::Cover;
        }

        if ($this->isPhotoCollage($hay, $textKind)) {
            return DocumentPageRole::PhotoCollage;
        }

        return DocumentPageRole::Unknown;
    }

    public function shouldSkip(DocumentPageRole $role): bool
    {
        return in_array($role, [
            DocumentPageRole::Anschreiben,
            DocumentPageRole::PhotoCollage,
            DocumentPageRole::CertLegend,
            DocumentPageRole::WatermarkOnly,
            DocumentPageRole::Cover,
            DocumentPageRole::Skip,
        ], true);
    }

    private function isPassport(string $hay, string $compact): bool
    {
        if (str_contains($compact, 'p<mar') || preg_match('/p<[a-z]{3}/', $compact)) {
            return true;
        }

        return str_contains($hay, 'passeport')
            || str_contains($hay, 'passport')
            || str_contains($hay, 'reisepass')
            || (str_contains($hay, 'beglaubigte übersetzung') && str_contains($hay, 'reisepass'));
    }

    private function isLanguageCert(string $hay, string $compact): bool
    {
        if (str_contains($compact, 'goethe-zertifikat') || str_contains($hay, 'goethe-zertifikat')) {
            return ! $this->isCertLegend($hay, $compact);
        }

        return str_contains($hay, 'telc deutsch')
            || str_contains($compact, 'telcdeutsch')
            || (str_contains($hay, 'telc') && (str_contains($hay, 'zertifikat') || str_contains($hay, 'b1') || str_contains($hay, 'b2')));
    }

    private function isCertLegend(string $hay, string $compact): bool
    {
        // Goethe reverse/legend pages: grading scale, no personal name block.
        $hasScale = str_contains($hay, 'nicht bestanden') && str_contains($hay, 'sehr gut');
        $hasModules = str_contains($hay, 'lesen') && str_contains($hay, 'hören') && str_contains($hay, 'schreiben');
        $noPerson = ! preg_match('/\b(vorname|geburtsdatum|name\s*:)/u', $hay);

        return str_contains($hay, 'goethe-zertifikat') && $hasScale && $hasModules && $noPerson;
    }

    private function isEducationCert(string $hay, string $compact): bool
    {
        return str_contains($hay, 'baccalauréat')
            || str_contains($hay, 'baccalaureat')
            || str_contains($hay, 'reifezeugnis')
            || str_contains($hay, 'abiturzeugnis')
            || str_contains($hay, 'abitur-noten')
            || str_contains($hay, 'notenspiegel')
            || str_contains($hay, 'releve des notes')
            || str_contains($hay, 'relevé des notes')
            || str_contains($hay, 'schulbescheinigung')
            || str_contains($hay, 'diplome d\'etudes')
            || str_contains($hay, 'diplôme')
            || (str_contains($hay, 'beglaubigte übersetzung') && (
                str_contains($hay, 'abitur') || str_contains($hay, 'schule') || str_contains($hay, 'universit')
            ));
    }

    private function isExperienceProof(string $hay): bool
    {
        return str_contains($hay, 'praktikumsnachweis')
            || str_contains($hay, 'praktikumsbescheinigung')
            || str_contains($hay, 'arbeitszeugnis')
            || str_contains($hay, 'beschäftigungsnachweis');
    }

    private function isAnschreiben(string $hay, string $compact): bool
    {
        if (str_contains($hay, 'anschreiben') && ! str_contains($hay, 'lebenslauf')) {
            return true;
        }

        $letterOpen = str_contains($hay, 'sehr geehrte') || str_contains($hay, 'sehr geehrter');
        $closing = str_contains($hay, 'mit freundlichen grüßen') || str_contains($hay, 'mit freundlichen gruessen');
        $cvMarkers = str_contains($hay, 'berufserfahrung') || str_contains($hay, 'bildungsweg') || str_contains($hay, 'sprachkenntnisse');

        return $letterOpen && $closing && ! $cvMarkers;
    }

    private function isCv(string $hay, string $compact): bool
    {
        return str_contains($hay, 'lebenslauf')
            || str_contains($hay, 'curriculum vitae')
            || (str_contains($hay, 'berufserfahrung') && (str_contains($hay, 'bildung') || str_contains($hay, 'sprachen') || str_contains($hay, 'persönliche daten')))
            || (str_contains($hay, 'kurzprofil') && str_contains($hay, 'sprachen'));
    }

    private function isCover(string $hay, string $compact): bool
    {
        return str_contains($hay, 'anlagen')
            && (str_contains($hay, 'lebenslauf') || str_contains($hay, 'zeugnisse'))
            && mb_strlen($compact) < 400;
    }

    private function isPhotoCollage(string $hay, PageTextKind $textKind): bool
    {
        if ($textKind !== PageTextKind::Empty && $textKind !== PageTextKind::Scan) {
            return false;
        }

        // Heuristic placeholder: empty/scan with no form keywords → may be photo; callers may refine via OCR.
        return $hay === '' || mb_strlen(preg_replace('/\s+/u', '', $hay) ?? '') < 20;
    }
}
