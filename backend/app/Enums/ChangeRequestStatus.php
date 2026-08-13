<?php

declare(strict_types=1);

namespace App\Enums;

/** حالة طلب التغيير. الحالات النهائية لا يُعاد فتحها — هذا ما يمنع تكرار
 *  تمديد تاريخ التسليم (كانت ثغرة في النسخة السابقة). */
enum ChangeRequestStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Expired, self::Withdrawn], true);
    }
}
