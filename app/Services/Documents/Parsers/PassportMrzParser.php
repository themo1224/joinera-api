<?php

namespace App\Services\Documents\Parsers;

final class PassportMrzParser
{
    /**
     * @return array{
     *   document_type?: string,
     *   country?: string,
     *   surname?: string,
     *   given_names?: string,
     *   full_name?: string,
     *   passport_number?: string,
     *   nationality?: string,
     *   date_of_birth?: string,
     *   sex?: string,
     *   expiry_date?: string,
     *   personal_number?: string
     * }|null
     */
    public function parseFromText(string $text): ?array
    {
        $lines = $this->extractMrzLines($text);
        if (count($lines) < 2) {
            return null;
        }

        $line1 = $lines[0];
        $line2 = $lines[1];

        if (strlen($line1) < 30 || strlen($line2) < 30) {
            return null;
        }

        if ($line1[0] !== 'P') {
            return null;
        }

        $country = $this->cleanAlpha(substr($line1, 2, 3));
        $namesPart = substr($line1, 5);
        $nameBits = explode('<<', $namesPart, 2);
        $surname = $this->humanizeName($nameBits[0] ?? '');
        $given = $this->humanizeName($nameBits[1] ?? '');

        $passportNumber = rtrim(substr($line2, 0, 9), '<');
        $nationality = $this->cleanAlpha(substr($line2, 10, 3));
        $dob = $this->parseYyMmDd(substr($line2, 13, 6));
        $sex = substr($line2, 20, 1);
        $expiry = $this->parseYyMmDd(substr($line2, 21, 6));
        $personal = rtrim(substr($line2, 28, 14), '<');

        $fullName = trim(implode(' ', array_filter([$given, $surname])));

        return array_filter([
            'document_type' => 'P',
            'country' => $country !== '' ? $country : null,
            'surname' => $surname !== '' ? $surname : null,
            'given_names' => $given !== '' ? $given : null,
            'full_name' => $fullName !== '' ? $fullName : null,
            'passport_number' => $passportNumber !== '' ? $passportNumber : null,
            'nationality' => $nationality !== '' ? $nationality : null,
            'date_of_birth' => $dob,
            'sex' => in_array($sex, ['M', 'F', 'X'], true) ? $sex : null,
            'expiry_date' => $expiry,
            'personal_number' => $personal !== '' ? $personal : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return list<string>
     */
    private function extractMrzLines(string $text): array
    {
        $candidates = [];
        foreach (preg_split("/\R/u", $text) ?: [] as $line) {
            $compact = strtoupper(preg_replace('/\s+/', '', $line) ?? '');
            $compact = preg_replace('/[^A-Z0-9<]/', '', $compact) ?? '';
            if (strlen($compact) >= 28 && substr_count($compact, '<') >= 2) {
                $candidates[] = $compact;
            }
        }

        // Prefer TD3 lines (~44 chars).
        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $mrz = [];
        foreach ($candidates as $line) {
            if (str_starts_with($line, 'P<') || (isset($mrz[0]) && strlen($line) >= 30)) {
                $mrz[] = str_pad(substr($line, 0, 44), 44, '<');
            }
            if (count($mrz) >= 2) {
                break;
            }
        }

        return $mrz;
    }

    private function cleanAlpha(string $value): string
    {
        return rtrim(preg_replace('/[^A-Z]/', '', strtoupper($value)) ?? '', '<');
    }

    private function humanizeName(string $mrzName): string
    {
        $parts = array_filter(explode('<', $mrzName));
        $words = array_map(
            static fn (string $p): string => ucwords(strtolower($p)),
            $parts,
        );

        return trim(implode(' ', $words));
    }

    private function parseYyMmDd(string $yymmdd): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);
        $year = $yy >= 50 ? 1900 + $yy : 2000 + $yy;

        if (! checkdate($mm, $dd, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }
}
