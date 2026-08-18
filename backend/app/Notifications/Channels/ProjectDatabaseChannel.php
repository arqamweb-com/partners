<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Notifications\ProjectNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * قناة الإشعارات داخل التطبيق، ومعها عمود project_id.
 *
 * القناة الأصلية تكتب المشروع داخل data كنص (رابط في JSON)، وهذا يكفي
 * للعرض ولا يكفي للاستعلام: عند أرشفة مشروع نحتاج أن نعرف أي إشعارات
 * تخصّه لنرفعها معه، وبحث نصي في JSON ليس جوابًا يُبنى عليه.
 *
 * لماذا هنا لا في كل إشعار على حدة؟ لأن الإشعار يقول «ماذا حدث»،
 * والقناة تقول «كيف يُخزَّن» — وإضافة إشعار جديد يجب ألا تتطلب تذكّر
 * عمود في جدول.
 */
class ProjectDatabaseChannel extends DatabaseChannel
{
    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification)
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'project_id' => $notification instanceof ProjectNotification
                ? $notification->project()->id
                : null,
        ];
    }
}
