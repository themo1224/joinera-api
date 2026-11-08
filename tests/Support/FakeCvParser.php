<?php

namespace Tests\Support;

use App\Contracts\CvParser;
use App\Exceptions\CvUnreadableException;
use App\Models\CvAsset;
use App\Support\CvParseResult;

class FakeCvParser implements CvParser
{
    /** @var list<CvParseResult|CvUnreadableException> */
    private array $queue;

    /**
     * @param  list<CvParseResult|CvUnreadableException>  $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function parse(CvAsset $asset): CvParseResult
    {
        $next = array_shift($this->queue);

        if ($next instanceof CvUnreadableException) {
            throw $next;
        }

        if ($next instanceof CvParseResult) {
            return $next;
        }

        throw new CvUnreadableException('No queued parse result.');
    }
}
