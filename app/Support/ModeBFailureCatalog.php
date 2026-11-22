<?php

namespace App\Support;

use App\Enums\ApplyAttemptKind;
use App\Models\BlockEvent;

/**
 * Plan 31 — map machine stop/challenge codes to Staff-readable labels.
 * Worker keeps raw detail; ops payloads expose failureCode + failureLabel.
 */
final class ModeBFailureCatalog
{
    /**
     * @return array{
     *     code: string,
     *     labelEn: string,
     *     labelDe: string,
     *     severity: 'info'|'warn'|'hard'|'block',
     *     needsHelp: bool
     * }
     */
    public static function resolve(?string $stopReason, ?ApplyAttemptKind $kind = null): array
    {
        $raw = trim((string) $stopReason);
        if ($raw === '') {
            return self::entry(
                code: $kind?->value ?? 'unknown',
                labelEn: self::kindFallbackEn($kind),
                labelDe: self::kindFallbackDe($kind),
                severity: self::kindSeverity($kind),
                needsHelp: $kind === ApplyAttemptKind::Challenge,
            );
        }

        if (str_starts_with(strtolower($raw), 'missing_info:')) {
            $labels = trim(substr($raw, strlen('missing_info:')));
            $pretty = $labels !== '' ? $labels : 'required fields';

            return self::entry(
                code: 'missing_info',
                labelEn: 'Missing candidate info: '.$pretty,
                labelDe: 'Fehlende Bewerberdaten: '.$pretty,
                severity: 'hard',
                needsHelp: false,
            );
        }

        if (preg_match('/^external_apply:(https?:\/\/\S+)$/i', $raw, $m) === 1) {
            return self::entry(
                code: 'external_apply',
                labelEn: 'Company sent us to an external apply page',
                labelDe: 'Firma leitet auf externe Bewerbungsseite weiter',
                severity: 'hard',
                needsHelp: true,
            );
        }

        $key = strtolower($raw);
        $known = self::map()[$key] ?? null;
        if ($known !== null) {
            return $known;
        }

        // Prefix-style worker details (navigation_failed:…).
        if (str_contains($key, ':')) {
            $prefix = explode(':', $key, 2)[0];
            $knownPrefix = self::map()[$prefix] ?? null;
            if ($knownPrefix !== null) {
                return array_merge($knownPrefix, ['code' => $prefix]);
            }
        }

        return self::entry(
            code: $key,
            labelEn: 'Apply stopped ('.$raw.')',
            labelDe: 'Bewerbung gestoppt ('.$raw.')',
            severity: self::kindSeverity($kind),
            needsHelp: $kind === ApplyAttemptKind::Challenge,
        );
    }

    /**
     * Block-event observed_kind → Staff label (Plan 31 + blocks list).
     *
     * @return array{code: string, labelEn: string, labelDe: string, severity: string, needsHelp: bool}
     */
    public static function forBlockKind(string $observedKind): array
    {
        $key = strtolower(trim($observedKind));

        return self::map()[$key] ?? self::entry(
            code: $key !== '' ? $key : 'unknown_block',
            labelEn: 'Company site needs Staff help',
            labelDe: 'Firmenseite braucht Staff-Hilfe',
            severity: 'block',
            needsHelp: true,
        );
    }

    /**
     * @return array<string, array{code: string, labelEn: string, labelDe: string, severity: string, needsHelp: bool}>
     */
    private static function map(): array
    {
        return [
            BlockEvent::KIND_CAPTCHA => self::entry(
                'captcha',
                'Captcha blocked the apply — Staff must finish',
                'Captcha blockiert die Bewerbung — Staff muss fertigstellen',
                'block',
                true,
            ),
            BlockEvent::KIND_TWO_FACTOR => self::entry(
                'two_factor',
                'Login code / 2FA required — Staff must finish',
                'Login-Code / 2FA nötig — Staff muss fertigstellen',
                'block',
                true,
            ),
            BlockEvent::KIND_LOGIN_WALL => self::entry(
                'login_wall',
                'Company requires an account login',
                'Firma verlangt einen Account-Login',
                'block',
                true,
            ),
            BlockEvent::KIND_COOKIE_WALL => self::entry(
                'cookie_wall',
                'Cookie banner could not be dismissed',
                'Cookie-Banner ließ sich nicht schließen',
                'block',
                true,
            ),
            'soft_signup_failed' => self::entry(
                'soft_signup_failed',
                'Automatic account signup failed',
                'Automatische Konto-Registrierung fehlgeschlagen',
                'block',
                true,
            ),
            'missing_apply_email' => self::entry(
                'missing_apply_email',
                'No GMX apply email available for signup',
                'Keine GMX-Bewerbungs-E-Mail für Registrierung',
                'block',
                true,
            ),
            'not_simple_signup' => self::entry(
                'not_simple_signup',
                'Signup page is too complex for automation',
                'Registrierungsseite zu komplex für Automation',
                'block',
                true,
            ),
            'missing_company_apply_url' => self::entry(
                'missing_company_apply_url',
                'No company apply page — board-only link',
                'Keine Firmen-Bewerbungsseite — nur Board-Link',
                'hard',
                false,
            ),
            'profile_not_approved' => self::entry(
                'profile_not_approved',
                'Candidate profile is not approved yet',
                'Bewerberprofil noch nicht freigegeben',
                'hard',
                false,
            ),
            'profile_incomplete' => self::entry(
                'profile_incomplete',
                'Candidate profile is missing required apply fields',
                'Bewerberprofil unvollständig für Bewerbung',
                'hard',
                false,
            ),
            'mailbox_not_ready' => self::entry(
                'mailbox_not_ready',
                'GMX apply mailbox is not ready',
                'GMX-Bewerbungs-Postfach nicht bereit',
                'hard',
                false,
            ),
            'no_healthy_site' => self::entry(
                'no_healthy_site',
                'Board login identity is paused or missing',
                'Board-Login-Identität pausiert oder fehlt',
                'hard',
                false,
            ),
            'identity_mismatch' => self::entry(
                'identity_mismatch',
                'Browser worker returned a different candidate',
                'Browser-Worker lieferte anderen Bewerber',
                'hard',
                false,
            ),
            'missing_artifacts' => self::entry(
                'missing_artifacts',
                'CV or cover letter PDF is missing',
                'Lebenslauf oder Anschreiben-PDF fehlt',
                'hard',
                false,
            ),
            'empty_document_upload' => self::entry(
                'empty_document_upload',
                'CV or cover letter upload was empty on a present form slot',
                'Lebenslauf- oder Anschreiben-Upload auf vorhandenem Feld leer',
                'hard',
                false,
            ),
            'missing_profile_document' => self::entry(
                'missing_profile_document',
                'Required profile document (passport/language/education) is missing for upload',
                'Erforderliches Profildokument (Pass/Sprache/Abschluss) fehlt für Upload',
                'hard',
                false,
            ),
            'step_nav_not_found' => self::entry(
                'step_nav_not_found',
                'Could not find Next/Submit on this step (will retry)',
                'Weiter/Absenden auf diesem Schritt nicht gefunden (Retry)',
                'warn',
                false,
            ),
            'max_steps_exceeded' => self::entry(
                'max_steps_exceeded',
                'Multi-step form exceeded step limit (will retry)',
                'Mehrstufiges Formular überschreitet Schrittlimit (Retry)',
                'warn',
                false,
            ),
            'confirmation_not_found' => self::entry(
                'confirmation_not_found',
                'Submit ran but thank-you page was not confirmed (will retry)',
                'Absenden ok, Danke-Seite nicht bestätigt (Retry)',
                'warn',
                false,
            ),
            'cap_reached' => self::entry(
                'cap_reached',
                'Apply cap reached for this candidate',
                'Bewerbungslimit für diesen Bewerber erreicht',
                'warn',
                false,
            ),
            'site_cooldown' => self::entry(
                'site_cooldown',
                'Site is in cooldown after a block',
                'Seite in Abkühlzeit nach einem Block',
                'warn',
                false,
            ),
        ];
    }

    /**
     * @return array{code: string, labelEn: string, labelDe: string, severity: string, needsHelp: bool}
     */
    private static function entry(
        string $code,
        string $labelEn,
        string $labelDe,
        string $severity,
        bool $needsHelp,
    ): array {
        return [
            'code' => $code,
            'labelEn' => $labelEn,
            'labelDe' => $labelDe,
            'severity' => $severity,
            'needsHelp' => $needsHelp,
        ];
    }

    private static function kindFallbackEn(?ApplyAttemptKind $kind): string
    {
        return match ($kind) {
            ApplyAttemptKind::Success => 'Application submitted',
            ApplyAttemptKind::Challenge => 'Company site needs Staff help',
            ApplyAttemptKind::Retryable => 'Temporary apply problem — will retry',
            ApplyAttemptKind::HardFail => 'Apply stopped',
            default => 'No stop reason recorded',
        };
    }

    private static function kindFallbackDe(?ApplyAttemptKind $kind): string
    {
        return match ($kind) {
            ApplyAttemptKind::Success => 'Bewerbung abgeschickt',
            ApplyAttemptKind::Challenge => 'Firmenseite braucht Staff-Hilfe',
            ApplyAttemptKind::Retryable => 'Vorübergehendes Problem — wird erneut versucht',
            ApplyAttemptKind::HardFail => 'Bewerbung gestoppt',
            default => 'Kein Stoppgrund erfasst',
        };
    }

    private static function kindSeverity(?ApplyAttemptKind $kind): string
    {
        return match ($kind) {
            ApplyAttemptKind::Challenge => 'block',
            ApplyAttemptKind::HardFail => 'hard',
            ApplyAttemptKind::Retryable => 'warn',
            ApplyAttemptKind::Success => 'info',
            default => 'info',
        };
    }
}
