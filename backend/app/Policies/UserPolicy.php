<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * إدارة الحسابات.
 *
 * قاعدة واحدة تحكم الملف كله: الحسابات والأدوار للأدمن وحده. المدير يسعّر
 * ويعتمد كل المشاريع، لكنه لا يصنع أدمن — وإلا صار الفرق بين الدورين
 * إجراءً شكليًا يتجاوزه أي مدير في نقرتين.
 *
 * فوق ذلك قاعدة ثانية: الأدمن لا يحكم نفسه. تغيير دوره أو تعطيله أو حذفه
 * كلها أفعال قد تُخرجه من النظام وهو آخر أدمن فيه، فالباب مقفول من أصله
 * ولا يُترك لفحص «هل بقي غيره؟» وحده — انظر UserController::guardLastAdmin.
 *
 * المنع هنا يردّ Response بنص عربي لا مجرد false: من يُمنع يحتاج أن يعرف
 * لماذا، وإلا قرأ «This action is unauthorized» وظنّها عطلًا.
 */
class UserPolicy
{
    public function viewAny(User $actor): Response
    {
        return $this->onlyAdmin($actor);
    }

    public function view(User $actor, User $target): Response
    {
        return $this->onlyAdmin($actor);
    }

    public function create(User $actor): Response
    {
        return $this->onlyAdmin($actor);
    }

    /** البيانات الوصفية: الاسم، الوكالة، البريد. */
    public function update(User $actor, User $target): Response
    {
        return $this->onlyAdmin($actor);
    }

    /**
     * الدور والتفعيل — ما يمسّ الصلاحية نفسها.
     *
     * مفصولة عن update لأنها ليست نفس الخطورة: تصحيح اسم شيء، ورفع حساب
     * إلى أدمن شيء آخر. والأدمن لا يمارسها على نفسه.
     */
    public function govern(User $actor, User $target): Response
    {
        if (! $actor->isSuperUser()) {
            return $this->onlyAdmin($actor);
        }

        return $actor->id === $target->id
            ? Response::deny('لا تغيّر دور حسابك ولا حالته بنفسك. اطلب ذلك من أدمن آخر.')
            : Response::allow();
    }

    /** تعيين كلمة مرور جديدة نيابة عن المستخدم. */
    public function resetPassword(User $actor, User $target): Response
    {
        return $this->onlyAdmin($actor);
    }

    public function delete(User $actor, User $target): Response
    {
        if (! $actor->isSuperUser()) {
            return $this->onlyAdmin($actor);
        }

        return $actor->id === $target->id
            ? Response::deny('لا تحذف حسابك بنفسك.')
            : Response::allow();
    }

    private function onlyAdmin(User $actor): Response
    {
        return $actor->isSuperUser()
            ? Response::allow()
            : Response::deny('إدارة الحسابات والأدوار للأدمن وحده.');
    }
}
