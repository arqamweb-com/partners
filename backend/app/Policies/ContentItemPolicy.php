<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ContentStatus;
use App\Enums\Side;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * كانت member_columns تسمح للعميل بكتابة status مباشرة، فيضع 'accepted'
 * لنفسه ويتخطى المراجعة. هنا الفعلان منفصلان: يقدّم، ولا يراجع.
 */
class ContentItemPolicy
{
    public function view(User $user, ContentItem $item): bool
    {
        return ProjectParty::for($user, $item->project)->isMember();
    }

    /** التقديم حق جهة المستلِم — وليس على عنصر مقبول. */
    public function submit(User $user, ContentItem $item): bool
    {
        $party = ProjectParty::for($user, $item->project);

        return $item->status !== ContentStatus::Accepted
            && in_array($party->side(), [Side::Them, Side::Us], true)
            && $party->canActOnStages();
    }

    /** القبول والرفض قرار فريق أرقام وحده. */
    public function review(User $user, ContentItem $item): bool
    {
        return $user->isStaff() && ProjectParty::for($user, $item->project)->isMember();
    }

    public function update(User $user, ContentItem $item): bool
    {
        return $user->isStaff() && ProjectParty::for($user, $item->project)->isMember();
    }
}
