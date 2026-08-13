<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Side;
use App\Enums\SystemRole;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * توجيه الإشعارات.
 *
 * السؤال الذي يجيب عليه: «حصل حدث على مشروع — مين يتبلّغ؟»
 * والجواب من عضوية المشروع، لا من دور النظام: إشعار تقديم مرحلة يذهب
 * لجهة المراجعة على هذا المشروع تحديدًا، لا لكل موظفي أرقام.
 *
 * تفضيلات المستخدم (in_app / email / digest) تُطبَّق هنا مرة واحدة،
 * فلا تتكرر في كل Notification.
 */
final class Notifier
{
    /**
     * يُشعر أعضاء جهة بعينها في مشروع.
     *
     * إن لم يكن على المشروع أحد من فريق أرقام بعد (طلب عميل لم يُراجَع)،
     * يسقط الإشعار على من يملك المراجعة بدل أن يضيع صامتًا. التوجيه
     * بالعضوية هو الأصل، وهذا احتياط لئلا يُهمَل حدث لا مالك له.
     */
    public function toSide(Project $project, Side $side, Notification $notification): void
    {
        $recipients = $this->membersOnSide($project, $side);

        if ($recipients->isEmpty() && $side === Side::Us) {
            $recipients = $this->usersWithRoles(SystemRole::Admin, SystemRole::Manager);
        }

        $this->send($recipients, $notification);
    }

    /**
     * يُشعر بدور النظام لا بعضوية المشروع.
     *
     * لازم لحالة واحدة لا تغطيها العضوية: طلب مشروع جديد لم يُسنَد لأحد
     * بعد. لا أعضاء من الفريق فيه، فلا جهة «us» تُشعَر — ومع ذلك يجب أن
     * يعرف من يملك المراجعة أن هناك طلبًا ينتظر.
     */
    public function toSystemRoles(Notification $notification, SystemRole ...$roles): void
    {
        $this->send($this->usersWithRoles(...$roles), $notification);
    }

    /** @return Collection<int, User> */
    private function usersWithRoles(SystemRole ...$roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('system_role', array_map(fn (SystemRole $r) => $r->value, $roles))
            ->get();
    }

    /** يُشعر أعضاء بأدوار بعينها. */
    public function toRoles(Project $project, array $roles, Notification $notification): void
    {
        $recipients = $project->members
            ->filter(fn ($m) => $m->user_id !== null && in_array($m->role, $roles, true))
            ->map(fn ($m) => $m->user)
            ->filter();

        $this->send($recipients, $notification);
    }

    public function toUser(User $user, Notification $notification): void
    {
        $this->send(collect([$user]), $notification);
    }

    /** @return Collection<int, User> */
    private function membersOnSide(Project $project, Side $side): Collection
    {
        return $project->members()
            ->with('user')
            ->whereNotNull('user_id')
            ->get()
            ->filter(fn ($m) => $m->role->side() === $side)
            ->map(fn ($m) => $m->user)
            ->filter(fn (?User $u) => $u !== null && $u->is_active)
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, Notification $notification): void
    {
        $eventKey = method_exists($notification, 'eventKey')
            ? $notification->eventKey()
            : class_basename($notification);

        $allowed = $recipients->filter(
            fn (User $user) => NotificationPreference::allows($user, $eventKey)
        );

        if ($allowed->isEmpty()) {
            return;
        }

        // ShouldQueue على كل إشعار: الإرسال في الطابور فلا يبطّئ الطلب
        NotificationFacade::send($allowed, $notification);
    }
}
