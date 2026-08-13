<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackRoundStatus: string
{
    case Open = 'open';
    case Submitted = 'submitted';
    case Classified = 'classified';
    case Closed = 'closed';
}
