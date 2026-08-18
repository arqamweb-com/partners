<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * الالتزامات الزمنية لا تعتمد على من فتح متصفحه.
 * على الاستضافة المشتركة: كرون كل دقيقة على `php artisan schedule:run`.
 */
Schedule::command('arqam:auto-accept')->hourly();

/*
 * التأخير يُحتسب مرة واحدة في اليوم وقبل انتهاء طلبات التغيير: المهلة
 * التعاقدية للطلب تُقاس على تسليم قد يكون تحرّك للتوّ بتأخير العميل.
 */
Schedule::command('arqam:accrue-client-delay')->dailyAt('01:00');
Schedule::command('arqam:expire-change-requests')->dailyAt('02:00');
Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->withoutOverlapping();
