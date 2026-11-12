<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Skipped = 'skipped';
    case Queued = 'queued';
}
