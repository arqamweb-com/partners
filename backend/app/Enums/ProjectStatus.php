<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';                     // طلب ينتظر مراجعة فريق أرقام
    case Active = 'active';
    case AwaitingClient = 'awaiting_client';
    case Frozen = 'frozen';
    case Completed = 'completed';
    case Stopped = 'stopped';
}
