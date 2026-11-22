<?php

namespace App\Support;

use App\Enums\MailboxState;
use App\Models\MailboxIdentity;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds the Candidate pack for Mode B apply context (no secrets).
 */
class ModeBCandidatePack
{
    /**
     * @return array{
     *     name: string|null,
     *     apply_email: string|null,
     *     date_of_birth: string|null,
     *     city: list<mixed>,
     *     province: list<mixed>,
     *     language: string|null,
     *     education: string|null,
     *     desired_path: string|null,
     *     preferred_occupation: string|null,
     *     available_from: string|null,
     *     willing_to_relocate: bool|null,
     *     about: string|null,
     *     cover_letter: string|null,
     *     work_residence_note: string|null,
     *     driving_licenses: list<mixed>,
     *     education_entries: list<mixed>,
     *     experiences: list<mixed>,
     *     skills: list<mixed>,
     *     languages: list<mixed>
     * }
     */
    public static function fromUser(User $user): array
    {
        $profile = $user->relationLoaded('profile')
            ? $user->profile
            : $user->profile()->first();

        $mailbox = $user->relationLoaded('mailboxIdentity')
            ? $user->mailboxIdentity
            : $user->mailboxIdentity()->first();

        return self::fromParts($user, $profile, $mailbox);
    }

    /**
     * Empty pack when the application has no user row (should not happen in production).
     *
     * @return array{
     *     name: string|null,
     *     apply_email: string|null,
     *     date_of_birth: string|null,
     *     city: list<mixed>,
     *     province: list<mixed>,
     *     language: string|null,
     *     education: string|null,
     *     desired_path: string|null,
     *     preferred_occupation: string|null,
     *     available_from: string|null,
     *     willing_to_relocate: bool|null,
     *     about: string|null,
     *     cover_letter: string|null,
     *     work_residence_note: string|null,
     *     driving_licenses: list<mixed>,
     *     education_entries: list<mixed>,
     *     experiences: list<mixed>,
     *     skills: list<mixed>,
     *     languages: list<mixed>
     * }
     */
    public static function empty(): array
    {
        return self::fromParts(new User(['name' => '']), null, null);
    }

    /**
     * @return array{
     *     name: string|null,
     *     apply_email: string|null,
     *     date_of_birth: string|null,
     *     city: list<mixed>,
     *     province: list<mixed>,
     *     language: string|null,
     *     education: string|null,
     *     desired_path: string|null,
     *     preferred_occupation: string|null,
     *     available_from: string|null,
     *     willing_to_relocate: bool|null,
     *     about: string|null,
     *     cover_letter: string|null,
     *     work_residence_note: string|null,
     *     driving_licenses: list<mixed>,
     *     education_entries: list<mixed>,
     *     experiences: list<mixed>,
     *     skills: list<mixed>,
     *     languages: list<mixed>
     * }
     */
    public static function fromParts(User $user, ?Profile $profile, ?MailboxIdentity $mailbox): array
    {
        $name = trim((string) $user->name);

        return [
            'name' => $name !== '' ? $name : null,
            // GMX apply mailbox only — never User.email / personal login.
            'apply_email' => self::gmxApplyEmail($mailbox),
            'date_of_birth' => self::dateString($profile?->date_of_birth),
            'city' => self::list($profile?->city),
            'province' => self::list($profile?->province),
            'language' => self::nullableString($profile?->language),
            'education' => self::nullableString($profile?->education),
            'desired_path' => self::nullableString($profile?->desired_path),
            'preferred_occupation' => self::nullableString($profile?->preferred_occupation),
            'available_from' => self::dateString($profile?->available_from),
            'willing_to_relocate' => is_bool($profile?->willing_to_relocate) ? $profile->willing_to_relocate : null,
            'about' => self::nullableString($profile?->about),
            // Filled by InternalModeBContextController from cover artifact sidecar when present.
            'cover_letter' => null,
            'work_residence_note' => self::nullableString($profile?->work_residence_note),
            'driving_licenses' => self::list($profile?->driving_licenses),
            'education_entries' => self::list($profile?->education_entries),
            'experiences' => self::list($profile?->experiences),
            'skills' => self::list($profile?->skills),
            'languages' => self::list($profile?->languages),
        ];
    }

    /**
     * Ready + allowed GMX host only. Personal User.email must never appear here.
     */
    private static function gmxApplyEmail(?MailboxIdentity $mailbox): ?string
    {
        if ($mailbox === null || $mailbox->state !== MailboxState::Ready) {
            return null;
        }

        $address = self::nullableString($mailbox->address);
        if ($address === null) {
            return null;
        }

        $validator = app(GmxAddressValidator::class);
        if (! $validator->isAllowed($address)) {
            return null;
        }

        return $validator->normalize($address);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->format('Y-m-d');
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }
}
