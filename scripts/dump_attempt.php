<?php

use App\Models\ApplyAttempt;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 81);
$t = ApplyAttempt::query()->findOrFail($id);
$fp = $t->form_plan;
echo "stop={$t->stop_reason}\n";
$answers = $fp['answers'] ?? $fp['answers_all'] ?? [];
foreach ($answers as $a) {
    $val = $a['value'] ?? null;
    if (is_string($val) && strlen($val) > 60) {
        $val = substr($val, 0, 60) . '...';
    }
    echo ($a['label'] ?? $a['field_id']) . ' | ' . ($a['status'] ?? '') . ' | ' . json_encode($val) . PHP_EOL;
}
