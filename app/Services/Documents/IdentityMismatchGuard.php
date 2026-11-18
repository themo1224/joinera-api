<?php

namespace App\Services\Documents;

final class IdentityMismatchGuard
{
    /**
     * @param  list<string>  $detectedNames
     * @return array{ok: bool, expected: string, detected: list<string>, message: string|null}
     */
    public function evaluate(string $expectedApplicantName, array $detectedNames): array
    {
        $expected = $this->normalizeName($expectedApplicantName);
        $unique = [];
        foreach ($detectedNames as $name) {
            $n = $this->normalizeName($name);
            if ($n !== '') {
                $unique[$n] = $name;
            }
        }

        $detected = array_values($unique);
        if ($detected === []) {
            return [
                'ok' => true,
                'expected' => $expectedApplicantName,
                'detected' => [],
                'message' => null,
            ];
        }

        $matchesExpected = false;
        foreach ($detected as $name) {
            if ($this->namesCompatible($expected, $this->normalizeName($name))) {
                $matchesExpected = true;
                break;
            }
        }

        // Multiple distinct people inside one pack.
        $distinctPeople = [];
        foreach ($detected as $name) {
            $norm = $this->normalizeName($name);
            $merged = false;
            foreach ($distinctPeople as $existing) {
                if ($this->namesCompatible($existing, $norm)) {
                    $merged = true;
                    break;
                }
            }
            if (! $merged) {
                $distinctPeople[] = $norm;
            }
        }

        if (count($distinctPeople) > 1) {
            return [
                'ok' => false,
                'expected' => $expectedApplicantName,
                'detected' => $detected,
                'message' => 'Document pack appears to contain multiple identities; auto-apply blocked.',
                'reason' => 'multi_person',
            ];
        }

        if (! $matchesExpected && $expected !== '') {
            // Soft warning: staff can still get prefill; they must confirm the right applicant.
            return [
                'ok' => true,
                'expected' => $expectedApplicantName,
                'detected' => $detected,
                'message' => 'Detected document name differs from applicant name — review before saving.',
                'reason' => 'name_mismatch',
            ];
        }

        return [
            'ok' => true,
            'expected' => $expectedApplicantName,
            'detected' => $detected,
            'message' => null,
            'reason' => null,
        ];
    }

    public function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['-', '_'], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }

    public function namesCompatible(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        sort($ta);
        sort($tb);

        return implode(' ', $ta) === implode(' ', $tb)
            || str_contains($a, $b)
            || str_contains($b, $a);
    }
}
