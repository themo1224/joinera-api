<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Proposed = 'proposed';
    case Skipped = 'skipped';
    case Queued = 'queued';
}
