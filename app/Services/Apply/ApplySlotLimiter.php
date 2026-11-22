<?php

namespace App\Services\Apply;

use App\Models\ApplyAttempt;
use App\Models\BlockEvent;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Plan 32 / P0-14 — human-like anti-block slot gate.
 * All layers are inactive when config is null (configured=false).
 * Uses Cache (array in tests; Redis in production when CACHE_STORE=redis).
 */
class ApplySlotLimiter
{
    public const DENY_CAP = 'cap_reached';

    public const DENY_COOLDOWN = 'site_cooldown';

    public const DENY_INTAKE_SESSION = 'intake_user_session';

    /**
     * @return array{
     *     allowed: bool,
     *     code: string|null,
     *     jitterSeconds: int|null,
     *     profileKey: string,
     *     layers: array<string, array{configured: bool, value: int|null}>
     * }
     */
    public function requestSlot(int $userId, string $site, bool $isIntake = false): array
    {
        if ($isIntake) {
            return $this->denied($userId, $site, self::DENY_INTAKE_SESSION, null);
        }

        $layers = $this->layers();
        $profileKey = $this->profileKey($userId, $site);

        $cooldown = $layers['cooldown_after_block']['value'];
        if ($layers['cooldown_after_block']['configured'] && $cooldown !== null) {
            $openBlock = BlockEvent::query()
                ->where('user_id', $userId)
                ->where('site', $site)
                ->whereNull('resolved_at')
                ->latest('id')
                ->first();

            if ($openBlock !== null) {
                $elapsed = $openBlock->created_at?->diffInSeconds(now()) ?? 0;
                if ($elapsed < $cooldown) {
                    return $this->denied($userId, $site, self::DENY_COOLDOWN, null);
                }
            }
        }

        $perHour = $layers['max_per_hour_user_site']['value'];
        if ($layers['max_per_hour_user_site']['configured'] && $perHour !== null) {
            $used = $this->hourlyUsage($userId, $site);
            if ($used >= $perHour) {
                return $this->denied($userId, $site, self::DENY_CAP, null);
            }
        }

        $jitter = $this->drawJitter($layers);

        if ($layers['max_per_hour_user_site']['configured'] && $perHour !== null) {
            $this->incrementHourlyUsage($userId, $site);
        }

        return [
            'allowed' => true,
            'code' => null,
            'jitterSeconds' => $jitter,
            'profileKey' => $profileKey,
            'layers' => $layers,
        ];
    }

    /**
     * @return array{
     *     profileKey: string,
     *     layers: array<string, array{configured: bool, value: int|null}>
     * }
     */
    public function describe(int $userId, string $site): array
    {
        return [
            'profileKey' => $this->profileKey($userId, $site),
            'layers' => $this->layers(),
        ];
    }

    public function profileKey(int $userId, string $site): string
    {
        $site = strtolower(trim($site));

        return 'user:'.$userId.'|site:'.$site;
    }

    /**
     * Reject a jitter draw outside the configured band (limiter self-check).
     */
    public function assertJitterInBand(int $seconds): void
    {
        $min = $this->nullableInt(config('joinera.anti_block.jitter_min_seconds'));
        $max = $this->nullableInt(config('joinera.anti_block.jitter_max_seconds'));
        if ($min === null || $max === null) {
            return;
        }
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        if ($seconds < $min || $seconds > $max) {
            throw new InvalidArgumentException(
                "jitter {$seconds}s outside band {$min}-{$max}",
            );
        }
    }

    /**
     * @return array<string, array{configured: bool, value: int|null}>
     */
    public function layers(): array
    {
        $jitterMin = $this->nullableInt(config('joinera.anti_block.jitter_min_seconds'));
        $jitterMax = $this->nullableInt(config('joinera.anti_block.jitter_max_seconds'));
        $perHour = $this->nullableInt(config('joinera.anti_block.max_per_hour_user_site'));
        $cooldown = $this->nullableInt(config('joinera.anti_block.cooldown_seconds_after_block'));

        return [
            'jitter_min_seconds' => [
                'configured' => $jitterMin !== null,
                'value' => $jitterMin,
            ],
            'jitter_max_seconds' => [
                'configured' => $jitterMax !== null,
                'value' => $jitterMax,
            ],
            'max_per_hour_user_site' => [
                'configured' => $perHour !== null,
                'value' => $perHour,
            ],
            'cooldown_after_block' => [
                'configured' => $cooldown !== null,
                'value' => $cooldown,
            ],
        ];
    }

    /**
     * @param  array<string, array{configured: bool, value: int|null}>  $layers
     */
    private function drawJitter(array $layers): ?int
    {
        $min = $layers['jitter_min_seconds']['value'];
        $max = $layers['jitter_max_seconds']['value'];
        if (! $layers['jitter_min_seconds']['configured'] || ! $layers['jitter_max_seconds']['configured']) {
            return null;
        }
        if ($min === null || $max === null) {
            return null;
        }
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        if ($min === $max) {
            return $min;
        }

        // Inclusive band; must vary across draws when range > 0 (P0-14 acceptance).
        return random_int($min, $max);
    }

    private function hourlyUsage(int $userId, string $site): int
    {
        $key = $this->hourlyCacheKey($userId, $site);
        $cached = Cache::get($key);
        if (is_int($cached) || (is_string($cached) && ctype_digit($cached))) {
            return (int) $cached;
        }

        // Fallback: count recent apply attempts for this user×site in the last hour.
        return ApplyAttempt::query()
            ->whereHas('application', static function ($q) use ($userId, $site): void {
                $q->where('user_id', $userId)->where('site', $site);
            })
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    private function incrementHourlyUsage(int $userId, string $site): void
    {
        $key = $this->hourlyCacheKey($userId, $site);
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addHour());

            return;
        }
        Cache::increment($key);
    }

    private function hourlyCacheKey(int $userId, string $site): string
    {
        return 'joinera:anti_block:hour:'.$this->profileKey($userId, $site);
    }

    /**
     * @return array{
     *     allowed: bool,
     *     code: string|null,
     *     jitterSeconds: int|null,
     *     profileKey: string,
     *     layers: array<string, array{configured: bool, value: int|null}>
     * }
     */
    private function denied(int $userId, string $site, string $code, ?int $jitter): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'jitterSeconds' => $jitter,
            'profileKey' => $this->profileKey($userId, $site),
            'layers' => $this->layers(),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
