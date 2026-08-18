<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Stage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * موعد استحقاق المرحلة.
 *
 * ═══ ما الذي كان ناقصًا ═══
 *
 * كل مرحلة تحمل مدّتين — our_duration_days و their_duration_days — تأتيان
 * من قالب نوع المشروع. والعمود due_at موجود في الجدول منذ أول مايجريشن.
 * ما لم يكن موجودًا هو السطر الذي يصل الاثنين: لا شيء في النظام كان يكتب
 * due_at، فكانت المدد بيانات لا تُقرأ وكان العمود فارغًا في كل مرحلة
 * أنشأها التطبيق.
 *
 * ═══ القاعدة ═══
 *
 * العدّاد يخصّ الطرف الذي بيده الكرة، لا المرحلة. المرحلة الواحدة لها
 * مدّتان لأن الدور فيها ينتقل: أرقام تنفّذ في N يوم، ثم العميل يراجع في M
 * يومًا. فكل مرة تنتقل الكرة يبدأ عدّاد جديد بمدّة صاحبه — لا يُكمل
 * ما تبقّى من عدّاد الطرف السابق.
 *
 * مدّة صفر تعني «بلا سقف زمني» فيبقى due_at فارغًا: مرحلة الضمان
 * ومراحل لم تُقدَّر بعد. الفراغ هنا اختيار لا نقص.
 */
final readonly class StageClock
{
    public function __construct(private BusinessDays $businessDays) {}

    /**
     * اللحظة التي أخذ فيها الطرف الحالي الكرة.
     *
     * ليس started_at دائمًا: المرحلة المقدَّمة عدّادها يبدأ من التقديم،
     * والمرفوضة من الرفض. والترتيب هنا يعتمد على أن التقديم يُمحى عند
     * الرفض (StageWorkflow::reject)، فأحدث الثلاثة هو الصحيح دائمًا.
     */
    public function anchor(Stage $stage): ?CarbonInterface
    {
        return $stage->submitted_at ?? $stage->rejected_at ?? $stage->started_at;
    }

    /** موعد الاستحقاق لو أخذ هذا الطرف الكرة في هذه اللحظة. */
    public function dueFrom(Stage $stage, Side $ball, CarbonInterface $from): ?CarbonImmutable
    {
        $days = $ball === Side::Us
            ? $stage->our_duration_days
            : $stage->their_duration_days;

        return $days > 0 ? $this->businessDays->add($from, $days) : null;
    }

    /**
     * إعادة حساب موعد مرحلة جارية من حالتها كما هي.
     *
     * تُستدعى حين تتغيّر المدد تحت مرحلة شغّالة (تعديل خطة المراحل)،
     * وحين نملأ مواعيد مراحل أُنشئت قبل وجود هذا الحساب.
     */
    public function recompute(Stage $stage): ?CarbonImmutable
    {
        $running = in_array(
            $stage->status,
            [StageStatus::Active, StageStatus::AwaitingApproval],
            true,
        );

        $from = $this->anchor($stage);

        if (! $running || $from === null) {
            return null;
        }

        return $this->dueFrom($stage, $stage->ball_in_court, $from);
    }
}
