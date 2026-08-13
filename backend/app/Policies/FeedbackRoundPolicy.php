<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FeedbackRoundStatus;
use App\Enums\Side;
use App\Models\FeedbackRound;
use App\Models\User;
use App\Services\ProjectParty;

class FeedbackRoundPolicy
{
    public function view(User $user, FeedbackRound $round): bool
    {
        return ProjectParty::for($user, $round->project)->isMember();
    }

    /** إرسال الجولة: جهة المستلِم، وعلى جولة مفتوحة فقط. */
    public function submit(User $user, FeedbackRound $round): bool
    {
        $party = ProjectParty::for($user, $round->project);

        return $round->status === FeedbackRoundStatus::Open
            && $party->side() === Side::Them
            && $party->canActOnStages();
    }

    /** التصنيف والإقفال قرار فريق أرقام — كان العميل يقفز إليهما مباشرة. */
    public function classify(User $user, FeedbackRound $round): bool
    {
        return $user->isStaff() && ProjectParty::for($user, $round->project)->isMember();
    }

    public function addItem(User $user, FeedbackRound $round): bool
    {
        // نافذة الملاحظات مقفولة بعد الإرسال
        return $round->isOpen() && ProjectParty::for($user, $round->project)->canActOnStages();
    }
}
