<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models;
use App\Policies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** ربط صريح: كل موديل وسياسته — أوضح من الاكتشاف بالاسم. */
    private const POLICIES = [
        Models\Project::class       => Policies\ProjectPolicy::class,
        Models\Stage::class         => Policies\StagePolicy::class,
        Models\ContentItem::class   => Policies\ContentItemPolicy::class,
        Models\AccessItem::class    => Policies\AccessItemPolicy::class,
        Models\FeedbackRound::class => Policies\FeedbackRoundPolicy::class,
        Models\ChangeRequest::class => Policies\ChangeRequestPolicy::class,
        Models\GateApproval::class  => Policies\GateApprovalPolicy::class,
        Models\AuditLog::class      => Policies\AuditLogPolicy::class,
        Models\User::class          => Policies\UserPolicy::class,
    ];

    public function register(): void {}

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // مسارات الـ API لا تُعيد توجيهًا أبدًا: مستخدم مسجَّل يطلب
        // /auth/register يستحق 403 بنص مفهوم، لا 302 إلى الصفحة الرئيسية
        // يفسّرها عميل JSON كنجاح غامض.
        \Illuminate\Auth\Middleware\RedirectIfAuthenticated::redirectUsing(
            function (Request $request): string {
                abort_if($request->expectsJson(), 403, 'أنت مسجّل دخول بالفعل.');

                return '/';
            },
        );

        // الأدمن ليس فوق كل شيء: اعتمادات البوابات وسجل التدقيق لا تُعدَّل
        // ولا تُحذف من أحد إطلاقًا، فلا نضع Gate::before يتخطى السياسات.
    }
}
