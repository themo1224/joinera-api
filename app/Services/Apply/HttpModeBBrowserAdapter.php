<?php

namespace App\Services\Apply;

use App\Contracts\ModeBBrowserPort;
use App\Enums\ModeBBrowserOutcome;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls the external Playwright Mode B worker over HTTP.
 * Request body is user_id + site_id + application_id only.
 */
class HttpModeBBrowserAdapter implements ModeBBrowserPort
{
    public function submit(int $userId, string $siteId, int $applicationId): ModeBBrowserResult
    {
        $base = (string) config('joinera.mode_b.worker_url');
        $secret = (string) config('joinera.mode_b.worker_secret');
        $timeout = (int) config('joinera.mode_b.timeout', 120);

        if ($base === '') {
            throw new RuntimeException('joinera.mode_b.worker_url is not configured.');
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($secret !== '' ? ['X-Mode-B-Worker-Secret' => $secret] : [])
            ->post($base.'/submit', [
                'user_id' => $userId,
                'site_id' => $siteId,
                'application_id' => $applicationId,
            ]);

        if (! $response->successful()) {
            return ModeBBrowserResult::retryable('worker_http_'.$response->status());
        }

        /** @var array{outcome?: mixed, confirmation_reference?: mixed, detail?: mixed, form_plan?: mixed} $payload */
        $payload = $response->json() ?? [];

        $outcomeRaw = is_string($payload['outcome'] ?? null) ? $payload['outcome'] : '';
        $outcome = ModeBBrowserOutcome::tryFrom($outcomeRaw);
        if ($outcome === null) {
            return ModeBBrowserResult::retryable('worker_invalid_outcome');
        }

        $confirmation = isset($payload['confirmation_reference']) && is_string($payload['confirmation_reference'])
            ? $payload['confirmation_reference']
            : null;
        $detail = isset($payload['detail']) && is_string($payload['detail'])
            ? $payload['detail']
            : null;
        $formPlan = isset($payload['form_plan']) && is_array($payload['form_plan'])
            ? $payload['form_plan']
            : null;

        return match ($outcome) {
            ModeBBrowserOutcome::Success => ModeBBrowserResult::success(
                $confirmation ?? ('worker-'.$applicationId),
                $formPlan,
            ),
            ModeBBrowserOutcome::IdentityMismatch => ModeBBrowserResult::identityMismatch($detail, $formPlan),
            ModeBBrowserOutcome::Challenge => ModeBBrowserResult::challenge($detail, $formPlan),
            ModeBBrowserOutcome::Retryable => ModeBBrowserResult::retryable($detail, $formPlan),
        };
    }
}
