<?php

declare(strict_types=1);

namespace App\Enums;

/** حالة المرحلة. الانتقال في اتجاه واحد وينتهي عند locked نهائيًا. */
enum StageStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case AwaitingApproval = 'awaiting_approval';
    case Locked = 'locked';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Pending          => 'لم تبدأ',
            self::Active           => 'جارية',
            self::AwaitingApproval => 'في انتظار المراجعة',
            self::Locked           => 'مقفولة',
            self::Frozen           => 'مجمّدة',
        };
    }
}
