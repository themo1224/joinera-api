<?php

namespace App\Enums;

enum SiteIdentityState: string
{
    case Healthy = 'healthy';
    case Failed = 'failed';
}
