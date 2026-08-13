<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * الأساس لكل إشعارات المشاريع.
 *
 * القنوات تُقرأ من تفضيلات المستخدم لا من الإشعار نفسه، فإضافة إشعار جديد
 * = صنف واحد صغير، بلا لمس منطق التوجيه.
 */
abstract class ProjectNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** مفتاح الحدث في التفضيلات: stage.submitted، cr.sent … */
    abstract public function eventKey(): string;

    /** العنوان القصير — يظهر في الجرس داخل التطبيق. */
    abstract public function title(): string;

    /** النص الكامل. */
    abstract public function body(): string;

    /** المشروع الذي وقع عليه الحدث — منه يُبنى الرابط. */
    abstract public function project(): Project;

    /**
     * الرابط الذي يفتحه الإشعار.
     * صفحة المشروع تكفي لكل الأحداث؛ من احتاج غيرها يتجاوز هذه.
     */
    public function url(): string
    {
        return '/projects/'.$this->project()->id;
    }

    /** اسم الفاعل كما يظهر في نص الإشعار. */
    protected function actorName(?User $user): string
    {
        return $user?->full_name ?: ($user?->email ?? 'النظام');
    }

    public function via(object $notifiable): array
    {
        return NotificationPreference::channelsFor($notifiable, $this->eventKey());
    }

    /**
     * قناة لكل اتصال طابور.
     *
     * الإشعار داخل التطبيق يُكتب فورًا (sync): المستخدم يفتح الجرس بعد
     * ثانية من الحدث ويتوقّع أن يجده — والكتابة في جدول لا تستحق تأجيلًا.
     * البريد وحده يذهب للطابور، فهو البطيء وهو الذي قد يفشل ويحتاج إعادة.
     *
     * بدون هذا كان الإشعار كله ينتظر عامل الطابور، فلا يظهر شيء محليًا
     * ما لم يكن queue:work شغّالًا.
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail'     => config('queue.default'),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_key' => $this->eventKey(),
            'title'     => $this->title(),
            'body'      => $this->body(),
            'url'       => $this->url(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('أهلًا '.($notifiable->full_name ?: ''))
            ->line($this->body())
            ->action('افتح المشروع', url($this->url()))
            ->salutation('تحياتنا، فريق أرقام');
    }
}
