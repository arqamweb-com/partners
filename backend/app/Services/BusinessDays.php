<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * أيام العمل — الأحد إلى الخميس، والجمعة والسبت إجازة دائمة،
 * بالإضافة للأجازات الرسمية في جدول holidays.
 *
 * نقل حرفي لـ add_business_days() في api/lib/rules.php.
 */
final class BusinessDays
{
    private const GUARD = 10_000;   // يمنع الدوران اللانهائي لو جت الأرقام غلط

    /** @return list<string> */
    public function holidays(): array
    {
        return Cache::remember('holidays', now()->addHour(), fn () => Holiday::query()
            ->pluck('holiday_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all());
    }

    public function isBusinessDay(CarbonImmutable $date): bool
    {
        // 5 = الجمعة، 6 = السبت (ISO)
        if (in_array($date->dayOfWeekIso, [5, 6], true)) {
            return false;
        }

        return ! in_array($date->toDateString(), $this->holidays(), true);
    }

    public function add(CarbonInterface|string $from, int $days): CarbonImmutable
    {
        // نقبل أي CarbonInterface: Eloquent يعيد Illuminate\Support\Carbon
        $date = CarbonImmutable::parse($from);
        $left = max(0, $days);
        $guard = 0;

        while ($left > 0 && $guard++ < self::GUARD) {
            $date = $date->addDay();
            if ($this->isBusinessDay($date)) {
                $left--;
            }
        }

        return $date->startOfDay();
    }

    /** عدد أيام العمل بين تاريخين (لحساب التأخير). */
    public function between(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end <= $start) {
            return 0;
        }

        $count = 0;
        $guard = 0;
        $cursor = $start;

        while ($cursor < $end && $guard++ < self::GUARD) {
            $cursor = $cursor->addDay();
            if ($this->isBusinessDay($cursor)) {
                $count++;
            }
        }

        return $count;
    }
}
