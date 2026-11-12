<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Matched = 'matched';
    case Queued = 'queued';
    case PreparingDocs = 'preparing_docs';
    case Applying = 'applying';
    case Submitted = 'submitted';
    case ReplyReceived = 'reply_received';
    case Interview = 'interview';
    case Rejected = 'rejected';
    case Offer = 'offer';
}
