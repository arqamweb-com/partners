<?php

declare(strict_types=1);

namespace App\Enums;

enum ContentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
