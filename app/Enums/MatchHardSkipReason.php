<?php

namespace App\Enums;

enum MatchHardSkipReason: string
{
    case Industry = 'industry';
    case NotGermany = 'not_germany';
    case Education = 'education';
}
