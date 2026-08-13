<?php

declare(strict_types=1);

namespace App\Enums;

/** جهة الدورة: أرقام تُنفّذ، والطرف الآخر يستلم ويعتمد. */
enum Side: string
{
    case Us = 'us';
    case Them = 'them';

    public function other(): self
    {
        return $this === self::Us ? self::Them : self::Us;
    }

    public function label(): string
    {
        return $this === self::Us ? 'فريق أرقام' : 'الطرف الآخر';
    }
}
