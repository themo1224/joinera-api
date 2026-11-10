<?php

namespace App\Enums;

enum MailboxMessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
