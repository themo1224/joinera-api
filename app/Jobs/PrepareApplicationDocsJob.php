<?php

namespace App\Jobs;

use App\Exceptions\LlmRequestException;
use App\Services\Docs\PrepareApplicationDocuments;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PrepareApplicationDocsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $applicationId,
    ) {
        $this->onQueue((string) config('joinera.queues.docs', 'docs'));
    }

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('docs-prepare-'.$this->applicationId))->releaseAfter(60),
        ];
    }

    public function handle(PrepareApplicationDocuments $prepare): void
    {
        try {
            $prepare->handle($this->applicationId);
        } catch (LlmRequestException $exception) {
            throw $exception;
        }
    }
}
