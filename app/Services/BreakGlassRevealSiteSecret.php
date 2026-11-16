<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Application;
use App\Models\SiteIdentity;
use App\Models\StaffAccessAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class BreakGlassRevealSiteSecret
{
    public const RATE_MAX = 3;

    public const RATE_DECAY_SECONDS = 600;

    /**
     * @return array{ok: true, secret: string, auditId: int}|array{ok: false, code: string}
     */
    public function handle(
        SiteIdentity $identity,
        User $actor,
        string $reason,
        ?int $applicationId = null,
    ): array {
        $identity->loadMissing('user');

        if ($identity->user === null || $identity->user->role !== Role::User) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            return ['ok' => false, 'code' => 'reason_required'];
        }

        $rateKey = 'break-glass:'.$actor->id;
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_MAX)) {
            return ['ok' => false, 'code' => 'rate_limited'];
        }

        $application = null;
        if ($applicationId !== null) {
            $application = Application::query()->find($applicationId);
            if (
                $application === null
                || $application->user_id !== $identity->user_id
                || $application->site !== $identity->site
            ) {
                return ['ok' => false, 'code' => 'not_found'];
            }
        }

        try {
            $result = DB::transaction(function () use ($identity, $actor, $trimmedReason, $application): array {
                $audit = StaffAccessAudit::query()->create([
                    'actor_id' => $actor->id,
                    'user_id' => $identity->user_id,
                    'site' => $identity->site,
                    'site_identity_id' => $identity->id,
                    'application_id' => $application?->id,
                    'reason' => $trimmedReason,
                ]);

                if (! $audit->exists || $audit->id === null) {
                    throw new RuntimeException('audit_failed');
                }

                // Force a fresh decrypt read after the audit row is durable.
                $identity->refresh();
                $secret = $identity->secret;
                if (! is_string($secret) || $secret === '') {
                    throw new RuntimeException('audit_failed');
                }

                return [
                    'ok' => true,
                    'secret' => $secret,
                    'auditId' => (int) $audit->id,
                ];
            });
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'audit_failed'];
        }

        RateLimiter::hit($rateKey, self::RATE_DECAY_SECONDS);

        return $result;
    }
}
