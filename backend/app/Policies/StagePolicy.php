<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StageStatus;
use App\Models\Stage;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * صلاحيات المرحلة — هنا الثغرة الأخطر في النسخة السابقة.
 *
 * كان stage_approve() يتحقق من ball_in_court فقط. وبعد أي رفض ترجع الكرة
 * للطرف الآخر بحالة active، فيقدر يستدعي approve فورًا ويقفل مرحلته بنفسه
 * نهائيًا بلا مراجعة من أحد. الشرط الناقص كان status = awaiting_approval،
 * وهو مفروض هنا صراحةً في approve() و reject().
 */
class StagePolicy
{
    public function view(User $user, Stage $stage): bool
    {
        return ProjectParty::for($user, $stage->project)->isMember();
    }

    /** تقديم المرحلة للطرف الآخر لمراجعتها. */
    public function submit(User $user, Stage $stage): bool
    {
        $party = ProjectParty::for($user, $stage->project);

        return ! $stage->isLocked()
            && $party->canActOnStages()
            && $party->holdsBall($stage->ball_in_court)
            // مرحلة لم تبدأ لا تُقدَّم — وإلا أمكن تخطّي ترتيب المراحل
            && $stage->status === StageStatus::Active;
    }

    /** الاعتماد يقفل المرحلة نهائيًا، فشروطه أضيق شيء في النظام. */
    public function approve(User $user, Stage $stage): bool
    {
        $party = ProjectParty::for($user, $stage->project);

        return ! $stage->isLocked()
            && $party->canActOnStages()
            && $party->holdsBall($stage->ball_in_court)
            // ← الشرط الذي كان ناقصًا: لا اعتماد لما لم يُقدَّم
            && $stage->status === StageStatus::AwaitingApproval
            // ولا يعتمد إلا صاحب الاعتماد على جهته (الشريك قبل العميل)
            && ($party->side() === \App\Enums\Side::Us || $party->isDesignatedApprover());
    }

    public function reject(User $user, Stage $stage): bool
    {
        return $this->approve($user, $stage);
    }

    /** تعديل بنود المرحلة (المدد، المخرجات) — فريق أرقام قبل الإقفال. */
    public function update(User $user, Stage $stage): bool
    {
        return ! $stage->isLocked()
            && $user->isStaff()
            && ProjectParty::for($user, $stage->project)->isMember();
    }

    public function delete(User $user, Stage $stage): bool
    {
        return ! $stage->isLocked() && $user->canPrice();
    }
}
