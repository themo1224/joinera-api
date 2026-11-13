<?php

namespace App\Enums;

enum DocGenerationRule: string
{
    case CoverOnly = 'cover_only';
    case CvAndCover = 'cv_and_cover';
    case CategoryUnknown = 'category_unknown';
}
