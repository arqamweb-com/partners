<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * رسالة استعادة كلمة المرور.
 *
 * تحلّ محل إشعار لارافيل الافتراضي لسببين: نصّه إنجليزي، ورابطه يشير إلى
 * مسار خادم (`password.reset`) لا وجود له هنا — الواجهة تطبيق منفصل، فالرابط
 * لازم يشير إليها هي ومعه التوكن والبريد.
 *
 * وهي — وحدها بين إشعاراتنا — ليست ShouldQueue. الباقي يُرسَل في الطابور
 * لأن لا أحد ينتظره، أما هذه فالمستخدم واقف أمام الشاشة ينتظرها: التأخير
 * يبدو عطلًا، وفشل الإرسال يجب أن يظهر الآن لا أن يقبع في طابور صامت
 * (ولو نُسي إعداد الكرون لما وصلت أصلًا).
 */
class ResetPassword extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        // الاستعادة بريد دائمًا مهما كانت تفضيلات المستخدم — بلا بريد لا استعادة
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('استعادة كلمة المرور — أرقام ويب')
            ->greeting('أهلًا '.($notifiable->full_name ?: ''))
            ->line('وصلنا طلب لاستعادة كلمة مرور حسابك.')
            ->action('تعيين كلمة مرور جديدة', $this->resetUrl($notifiable))
            ->line("الرابط صالح لمدة {$minutes} دقيقة.")
            ->line('لو مش أنت من طلب ده، تجاهل الرسالة — حسابك زي ما هو ولا يحتاج أي إجراء.')
            ->salutation('تحياتنا، فريق أرقام');
    }

    /**
     * رابط الواجهة لا السيرفر.
     *
     * FRONTEND_URL يفصل عنوان الواجهة عن APP_URL: في التطوير الواجهة على
     * منفذ Vite والـ API على منفذ آخر، وفي الإنتاج قد يكونان نفس الأصل.
     */
    private function resetUrl(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/auth/reset?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
