<?php

namespace App\Enums;

enum ParseStatus: string
{
    case Unparsed = 'unparsed';
    case Parsed = 'parsed';
    case ParseFailed = 'parse_failed';
}
