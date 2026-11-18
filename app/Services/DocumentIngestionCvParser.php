<?php

namespace App\Services;

use App\Contracts\CvParser;
use App\Exceptions\CvUnreadableException;
use App\Models\CvAsset;
use App\Services\Documents\DocumentIngestionPipeline;
use App\Support\CvParseResult;
use Illuminate\Support\Facades\Storage;

class DocumentIngestionCvParser implements CvParser
{
    public function __construct(private readonly DocumentIngestionPipeline $pipeline)
    {
    }

    public function parse(CvAsset $asset): CvParseResult
    {
        if (! Storage::disk($asset->disk)->exists($asset->path)) {
            throw new CvUnreadableException('The CV could not be read.');
        }

        $absolute = Storage::disk($asset->disk)->path($asset->path);
        $expectedName = (string) ($asset->user?->name ?? '');

        $ingestion = $this->pipeline->ingestFile($absolute, $expectedName);

        return CvParseResult::fromIngestion($ingestion);
    }
}
