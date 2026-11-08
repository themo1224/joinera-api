<?php

namespace App\Contracts;

use App\Exceptions\CvUnreadableException;
use App\Models\CvAsset;
use App\Support\CvParseResult;

interface CvParser
{
    /**
     * @throws CvUnreadableException
     */
    public function parse(CvAsset $asset): CvParseResult;
}
