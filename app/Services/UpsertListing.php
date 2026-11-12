<?php

namespace App\Services;

use App\Enums\ListingKind;
use App\Events\ListingCreated;
use App\Exceptions\IntakeIdentityContextException;
use App\Exceptions\ListingDroppedException;
use App\Models\Listing;
use Illuminate\Support\Carbon;

class UpsertListing
{
    private const FORBIDDEN_KEYS = [
        'user_id',
        'site_identity_id',
        'session_id',
        'user_session_id',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{listing: Listing, created: bool}|null
     */
    public function handle(string $source, array $payload): ?array
    {
        $this->rejectIdentityContext($payload);

        if ($this->shouldDropForCountry($payload)) {
            return null;
        }

        $kind = $this->resolveKind($payload);
        $title = trim((string) ($payload['title'] ?? ''));
        $company = trim((string) ($payload['company'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));
        $url = trim((string) ($payload['url'] ?? ''));

        if ($title === '' || $company === '' || $city === '' || $url === '') {
            throw new ListingDroppedException('Listing is missing required fields.');
        }

        $externalId = $payload['external_id'] ?? null;
        $externalId = is_string($externalId) && $externalId !== '' ? $externalId : null;
        $fingerprint = $this->fingerprint($title, $company, $city, $url);
        $category = $payload['category'] ?? null;
        $category = is_string($category) && $category !== '' ? $category : null;
        $applyUrl = $this->nullableString($payload, 'apply_url');
        $description = $this->nullableString($payload, 'description');
        $languageRequirement = $this->nullableString($payload, 'language_requirement');
        $educationRequirement = $this->nullableString($payload, 'education_requirement');
        $country = $this->nullableCountry($payload);
        $postedAt = $this->nullablePostedAt($payload);

        $existing = $this->findExisting($source, $externalId, $fingerprint);
        $now = Carbon::now();

        if ($existing !== null) {
            $existing->last_seen_at = $now;
            $existing->save();

            return ['listing' => $existing, 'created' => false];
        }

        $listing = Listing::query()->create([
            'source' => $source,
            'external_id' => $externalId,
            'fingerprint' => $fingerprint,
            'kind' => $kind,
            'category' => $category,
            'title' => $title,
            'company' => $company,
            'city' => $city,
            'url' => $url,
            'apply_url' => $applyUrl,
            'description' => $description,
            'language_requirement' => $languageRequirement,
            'education_requirement' => $educationRequirement,
            'country' => $country,
            'posted_at' => $postedAt,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        ListingCreated::dispatch($listing->id);

        return ['listing' => $listing, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function rejectIdentityContext(array $payload): void
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                throw new IntakeIdentityContextException(
                    'Intake payloads must not include user or site identity context.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldDropForCountry(array $payload): bool
    {
        if (! array_key_exists('country', $payload) || $payload['country'] === null || $payload['country'] === '') {
            return false;
        }

        $country = strtoupper(trim((string) $payload['country']));
        $allowNonDe = (bool) config('joinera.sites.allow_non_de', false);

        return $country !== 'DE' && ! $allowNonDe;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveKind(array $payload): ListingKind
    {
        $kind = strtolower(trim((string) ($payload['kind'] ?? '')));

        return match ($kind) {
            'job' => ListingKind::Job,
            'ausbildung' => ListingKind::Ausbildung,
            default => throw new ListingDroppedException('Listing kind is required.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableCountry(array $payload): ?string
    {
        if (! array_key_exists('country', $payload) || $payload['country'] === null || $payload['country'] === '') {
            return null;
        }

        return strtoupper(trim((string) $payload['country']));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullablePostedAt(array $payload): ?Carbon
    {
        if (! array_key_exists('posted_at', $payload) || $payload['posted_at'] === null || $payload['posted_at'] === '') {
            return null;
        }

        return Carbon::parse($payload['posted_at']);
    }

    private function fingerprint(string $title, string $company, string $city, string $url): string
    {
        $normalized = mb_strtolower(trim($title).'|'.trim($company).'|'.trim($city).'|'.trim($url), 'UTF-8');

        return hash('sha256', $normalized);
    }

    private function findExisting(string $source, ?string $externalId, string $fingerprint): ?Listing
    {
        if ($externalId !== null) {
            $byExternal = Listing::query()
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->first();

            if ($byExternal !== null) {
                return $byExternal;
            }
        }

        return Listing::query()
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->first();
    }
}
