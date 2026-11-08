<?php

namespace App\Services;

use App\Contracts\CvParser;
use App\Exceptions\CvUnreadableException;
use App\Models\CvAsset;
use App\Support\CvParseResult;
use Illuminate\Support\Facades\Storage;

class FixtureCvParser implements CvParser
{
    public const UNREADABLE_MARKER = 'UNREADABLE_CV';

    public function parse(CvAsset $asset): CvParseResult
    {
        if (! Storage::disk($asset->disk)->exists($asset->path)) {
            throw new CvUnreadableException('The CV could not be read.');
        }

        $contents = (string) Storage::disk($asset->disk)->get($asset->path);

        if (str_contains($contents, self::UNREADABLE_MARKER)) {
            throw new CvUnreadableException('The CV could not be read.');
        }

        return new CvParseResult(
            industry: 'IT',
            city: 'Berlin',
            province: 'BE',
            language: 'de',
        );
    }
}
