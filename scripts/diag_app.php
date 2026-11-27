<?php

use App\Models\ApplyAttempt;
use App\Models\Application;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 0);
if ($id < 1) {
    fwrite(STDERR, "usage: php scripts/diag_app.php <application_id>\n");
    exit(1);
}

$application = Application::query()->find($id);
echo 'status=' . ($application?->status?->value ?? $application?->status) . PHP_EOL;

foreach (
    ApplyAttempt::query()->where('application_id', $id)->orderByDesc('id')->limit(5)->get() as $attempt
) {
    $fp = $attempt->form_plan;
    $steps = is_array($fp) ? ($fp['steps'] ?? []) : [];
    echo "id={$attempt->id} stop={$attempt->stop_reason} at={$attempt->created_at} steps=" . count($steps) . PHP_EOL;
    foreach ($steps as $step) {
        $nav = $step['nav']['label'] ?? '';
        $typed = implode(',', $step['fill']['typed'] ?? []);
        $attached = implode(',', $step['fill']['attached'] ?? []);
        echo "  #{$step['step']} {$step['action']} nav={$nav} typed=[{$typed}] attached=[{$attached}]\n";
    }
}
