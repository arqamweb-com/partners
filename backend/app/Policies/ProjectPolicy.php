<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * صلاحيات المشروع.
 *
 * البديل الصريح لجدول TABLES في api/lib/schema.php — والفرق أن كل قاعدة
 * هنا دالة باسم مقروء تُختبر وحدها، بدل مصفوفة نصوص تُفسَّر وقت التنفيذ.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Project $project): bool
    {
        return ProjectParty::for($user, $project)->isMember();
    }

    /** أي مستخدم نشط يسجّل طلب مشروع. البنود التعاقدية شيء آخر — انظر updateCharter. */
    public function create(User $user): bool
    {
        return $user->is_active;
    }

    /** تعديل البيانات الأساسية: فريق أرقام، أو مالك الطلب قبل اعتماده. */
    public function update(User $user, Project $project): bool
    {
        $party = ProjectParty::for($user, $project);

        if (! $party->isMember()) {
            return false;
        }

        if ($user->isStaff()) {
            return true;
        }

        // بعد الاعتماد يصير الميثاق مرجعًا لا يُعدَّل إلا بطلب تغيير
        return $project->owner_id === $user->id && ! $project->isCharterLocked();
    }

    /**
     * البنود التعاقدية: التواريخ، الضمان، جولات التعديل، التسعير.
     * المشرف ينفّذ ولا يتعاقد.
     */
    public function updateCharter(User $user, Project $project): bool
    {
        return $user->canPrice() && ProjectParty::for($user, $project)->isMember();
    }

    /** حالة المشروع (اعتماد الطلب، التجميد، الإيقاف) قرار فريق أرقام. */
    public function changeStatus(User $user, Project $project): bool
    {
        return $user->canPrice() && ProjectParty::for($user, $project)->isMember();
    }

    /** إدارة الأعضاء والدعوات. */
    public function manageMembers(User $user, Project $project): bool
    {
        return $user->isStaff() && ProjectParty::for($user, $project)->isMember();
    }

    /** إسناد دور Lead أو Contributor لموظف — للأدمن والمدير فقط. */
    public function assignStaff(User $user, Project $project): bool
    {
        return $user->canPrice() && ProjectParty::for($user, $project)->isMember();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isSuperUser();
    }

    /** بذر المراحل والقوائم من القالب — مرة واحدة عند اعتماد الطلب. */
    public function seed(User $user, Project $project): bool
    {
        return $user->canPrice()
            && ProjectParty::for($user, $project)->role === ProjectRole::Lead;
    }
}
