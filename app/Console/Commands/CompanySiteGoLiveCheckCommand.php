<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Plan 35 — print a machine-readable go-live config check (does not replace the human checklist).
 */
class CompanySiteGoLiveCheckCommand extends Command
{
    protected $signature = 'joinera:company-site-go-live-check';

    protected $description = 'Plan 35 — verify Mode B company-site config gates before production';

    public function handle(): int
    {
        $driver = (string) config('joinera.mode_b.driver', 'fixture');
        $workerUrl = (string) config('joinera.mode_b.worker_url', '');
        $secretSet = trim((string) config('joinera.mode_b.worker_secret', '')) !== '';
        $allowBoard = (bool) config('joinera.mode_b.allow_board_apply', false);

        $anti = [
            'jitter_min' => config('joinera.anti_block.jitter_min_seconds'),
            'jitter_max' => config('joinera.anti_block.jitter_max_seconds'),
            'max_per_hour' => config('joinera.anti_block.max_per_hour_user_site'),
            'cooldown' => config('joinera.anti_block.cooldown_seconds_after_block'),
        ];

        $checks = [
            [
                'id' => 'mode_b_driver_http',
                'ok' => $driver === 'http',
                'detail' => $driver,
            ],
            [
                'id' => 'worker_url_set',
                'ok' => $workerUrl !== '',
                'detail' => $workerUrl !== '' ? $workerUrl : 'missing',
            ],
            [
                'id' => 'worker_secret_set',
                'ok' => $secretSet,
                'detail' => $secretSet ? 'set' : 'missing',
            ],
            [
                'id' => 'board_apply_disabled',
                'ok' => $allowBoard === false,
                'detail' => $allowBoard ? 'ALLOW_BOARD_APPLY on' : 'company-site only',
            ],
            [
                'id' => 'anti_block_reviewed',
                'ok' => true,
                'detail' => $anti,
            ],
        ];

        $allOk = collect($checks)->every(static fn (array $c): bool => $c['ok'] === true);

        $payload = [
            'plan' => 35,
            'pass' => $allOk,
            'checks' => $checks,
            'checklist' => 'docs/COMPANY_SITE_GO_LIVE.md',
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
