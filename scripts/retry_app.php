<?php

use App\Models\ApplyAttempt;
use App\Models\Application;
use App\Services\RetryApplyApplication;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 0);
if ($id < 1) {
    fwrite(STDERR, "usage: php scripts/retry_app.php <application_id>\n");
    exit(1);
}

$closed = ApplyAttempt::query()
    ->where('application_id', $id)
    ->whereNull('kind')
    ->update([
        'kind' => 'retryable',
        'stop_reason' => 'interrupted_restart',
    ]);
echo "closed_open={$closed}\n";

$result = app(RetryApplyApplication::class)->handle(Application::query()->findOrFail($id));
echo json_encode($result) . PHP_EOL;
