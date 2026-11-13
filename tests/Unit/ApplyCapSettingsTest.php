<?php

namespace Tests\Unit;

use App\Enums\ApplyCapWindow;
use App\Support\ApplyCapSettings;
use Tests\TestCase;

class ApplyCapSettingsTest extends TestCase
{
    public function test_apply_cap_window_enum_values(): void
    {
        $this->assertSame('daily', ApplyCapWindow::Daily->value);
        $this->assertSame('weekly', ApplyCapWindow::Weekly->value);
    }

    public function test_defaults_are_cap_ten_and_daily_window(): void
    {
        config([
            'joinera.apply.cap' => 10,
            'joinera.apply.cap_window' => 'daily',
        ]);

        $settings = ApplyCapSettings::fromConfig();

        $this->assertSame(10, $settings->cap);
        $this->assertSame(ApplyCapWindow::Daily, $settings->window);
    }

    public function test_config_overrides_cap_and_weekly_window(): void
    {
        config([
            'joinera.apply.cap' => 3,
            'joinera.apply.cap_window' => 'weekly',
        ]);

        $settings = ApplyCapSettings::fromConfig();

        $this->assertSame(3, $settings->cap);
        $this->assertSame(ApplyCapWindow::Weekly, $settings->window);
    }

    public function test_cap_zero_or_negative_floors_to_one(): void
    {
        config(['joinera.apply.cap' => 0]);
        $this->assertSame(1, ApplyCapSettings::fromConfig()->cap);

        config(['joinera.apply.cap' => -5]);
        $this->assertSame(1, ApplyCapSettings::fromConfig()->cap);
    }

    public function test_invalid_or_blank_window_falls_back_to_daily(): void
    {
        config(['joinera.apply.cap_window' => 'monthly']);
        $this->assertSame(ApplyCapWindow::Daily, ApplyCapSettings::fromConfig()->window);

        config(['joinera.apply.cap_window' => '']);
        $this->assertSame(ApplyCapWindow::Daily, ApplyCapSettings::fromConfig()->window);

        config(['joinera.apply.cap_window' => null]);
        $this->assertSame(ApplyCapWindow::Daily, ApplyCapSettings::fromConfig()->window);
    }
}
