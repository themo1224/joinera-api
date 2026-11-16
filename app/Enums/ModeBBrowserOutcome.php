<?php

namespace App\Enums;

enum ModeBBrowserOutcome: string
{
    case Success = 'success';
    case IdentityMismatch = 'identity_mismatch';
    case Challenge = 'challenge';
    case Retryable = 'retryable';
}
