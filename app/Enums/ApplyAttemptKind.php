<?php

namespace App\Enums;

enum ApplyAttemptKind: string
{
    case Retryable = 'retryable';
    case Challenge = 'challenge';
    case HardFail = 'hard_fail';
    case Success = 'success';
}
