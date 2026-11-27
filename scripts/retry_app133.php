<?php

use App\Models\ApplyAttempt;
use App\Models\Application;
use App\Services\RetryApplyApplication;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Clear stuck open attempts that block Staff retry.
$closed = ApplyAttempt::query()
    ->where('application_id', 133)
    ->whereNull('kind')
    ->update([
        'kind' => 'retryable',
        'stop_reason' => 'interrupted_restart',
    ]);
echo "closed_open={$closed}\n";

// SubmitApplicationJob is ShouldBeUnique — a stale cache_locks row swallows dispatch (ok:true, no job).
$locks = Illuminate\Support\Facades\DB::table('cache_locks')
    ->where('key', 'like', '%SubmitApplicationJob:133%')
    ->orWhere('key', 'like', '%apply-submit-133%')
    ->delete();
echo "cleared_unique_locks={$locks}\n";

$result = app(RetryApplyApplication::class)->handle(Application::query()->findOrFail(133));
echo json_encode($result) . PHP_EOL;
