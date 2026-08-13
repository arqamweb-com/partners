<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * كان العميل يكتب status مباشرة، فيقدر:
 *   - يعتمد طلبًا لم يُسعَّر ولم يُرسَل له
 *   - يتنقّل approved -> sent -> approved فيمدّ تاريخ التسليم بلا حد
 * الفعلان مفصولان هنا، والقرار النهائي لا يُعاد فتحه.
 */
class ChangeRequestPolicy
{
    public function view(User $user, ChangeRequest $cr): bool
    {
        return ProjectParty::for($user, $cr->project)->isMember();
    }

    public function create(User $user, Project $project): bool
    {
        return ProjectParty::for($user, $project)->canActOnStages();
    }

    /** التسعير والإرسال — لمن يملك قرار التسعير. */
    public function price(User $user, ChangeRequest $cr): bool
    {
        return $user->canPrice()
            && ! $cr->isDecided()
            && ProjectParty::for($user, $cr->project)->isMember();
    }

    /** الاعتماد أو الرفض: صاحب الاعتماد على جهة المستلِم، وعلى طلب مُرسَل فقط. */
    public function decide(User $user, ChangeRequest $cr): bool
    {
        $party = ProjectParty::for($user, $cr->project);

        return $cr->status === ChangeRequestStatus::Sent
            && ! $cr->isDecided()
            && $party->isDesignatedApprover();
    }

    public function delete(User $user, ChangeRequest $cr): bool
    {
        return $user->isSuperUser();
    }
}
