<?php

namespace App\Enums;

enum MailboxState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
