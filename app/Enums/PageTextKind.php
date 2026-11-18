<?php

namespace App\Enums;

enum PageTextKind: string
{
    case Text = 'text';
    case Scan = 'scan';
    case Hybrid = 'hybrid';
    case Empty = 'empty';
}
