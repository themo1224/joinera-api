<?php

use App\Jobs\SyncAllReadyGmxMailboxesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('intake:run')->daily();
Schedule::job(new SyncAllReadyGmxMailboxesJob)->everyTenMinutes();
