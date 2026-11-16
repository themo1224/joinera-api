<?php

namespace App\Services;

use App\Enums\ApplyCapWindow;
use App\Models\Application;
use App\Models\User;
use App\Support\ApplyCapSettings;
use Carbon\CarbonInterface;

class ResolveApplyCapUsage
{
    /**
     * @return array{cap: int, window: string, used: int, remaining: int}
     */
    public function handle(User $user, ?ApplyCapSettings $settings = null): array
    {
        $settings ??= ApplyCapSettings::fromConfig();
        $since = $this->windowStart($settings->window);

        $used = Application::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'cap' => $settings->cap,
            'window' => $settings->window->value,
            'used' => $used,
            'remaining' => max(0, $settings->cap - $used),
        ];
    }

    private function windowStart(ApplyCapWindow $window): CarbonInterface
    {
        return match ($window) {
            ApplyCapWindow::Daily => now()->startOfDay(),
            ApplyCapWindow::Weekly => now()->startOfWeek(),
        };
    }
}
