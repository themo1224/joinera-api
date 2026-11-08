<?php

namespace App\Console\Commands;

use App\Services\StartIntakeRun;
use Illuminate\Console\Command;

class IntakeRunCommand extends Command
{
    protected $signature = 'intake:run';

    protected $description = 'Start a MIX intake run across configured sites.v1';

    public function handle(StartIntakeRun $startIntakeRun): int
    {
        $result = $startIntakeRun->handle();
        $siteCount = count($result['sites']);

        $this->info(sprintf(
            'Intake run %d started for %d site(s).',
            $result['run']->id,
            $siteCount,
        ));

        return self::SUCCESS;
    }
}
