<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ChangeRequestStatus;
use App\Enums\Side;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectParty;
use Illuminate\Auth\Access\Response;

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

    /**
     * الاعتماد أو الرفض: صاحب الاعتماد على جهة المستلِم، وعلى طلب مُرسَل فقط.
     *
     * يردّ Response لا bool: المنع هنا له ثلاثة أسباب مختلفة تمامًا، و«ليس
     * لديك صلاحية» تخفيها كلها. أشيعها أن موظفًا من أرقام ضغط الاعتماد —
     * وهو ليس نقص صلاحية بل سوء فهم للدورة: من يدفع هو من يعتمد.
     */
    public function decide(User $user, ChangeRequest $cr): Response
    {
        $party = ProjectParty::for($user, $cr->project);

        if (! $party->isMember()) {
            return Response::deny('لست عضوًا في هذا المشروع.');
        }

        if ($party->side() === Side::Us) {
            return Response::deny(
                'اعتماد طلب التغيير قرار العميل وحده — فريق أرقام يسعّر ويرسل، ولا يعتمد نيابة عنه.',
            );
        }

        if ($cr->status !== ChangeRequestStatus::Sent || $cr->isDecided()) {
            return Response::deny('هذا الطلب ليس معروضًا للقرار الآن.');
        }

        return $party->isDesignatedApprover()
            ? Response::allow()
            : Response::deny('الاعتماد لصاحب القرار على جهتك — الوكالة الشريكة قبل العميل النهائي.');
    }

    public function delete(User $user, ChangeRequest $cr): bool
    {
        return $user->isSuperUser();
    }
}
