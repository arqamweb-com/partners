<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // جلسة كوكي بدل التوكن — نفس عقد النسخة الحالية، فيبقى الفرونت
        // كما هو: credentials: 'same-origin' وكوكي HttpOnly.
        // الإضافة الوحيدة المطلوبة في الفرونت: ترويسة X-XSRF-TOKEN
        // (لارافيل يضع الكوكي تلقائيًا) — انظر src/lib/api.ts
        $middleware->statefulApi();

        // لارافيل ١١+ لم يعد يضع throttle على مجموعة api تلقائيًا.
        // الحد نفسه معرَّف في AppServiceProvider::boot تحت اسم 'api'.
        $middleware->throttleApi();

        // الرد على كل أخطاء الـ API بـ JSON لا HTML
        $middleware->api(prepend: [
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * منع الصلاحية يُقال بالعربية.
         *
         * كل abort_unless في النظام يكتب سببه بنفسه، أما $this->authorize()
         * فيرمي رسالة لارافيل الافتراضية: «This action is unauthorized.» —
         * فكان المستخدم يرى جملة إنجليزية وحيدة وسط واجهة عربية، ولا تقول
         * له شيئًا عن سبب المنع.
         *
         * الرسالة المخصّصة تمرّ كما هي: سياسة تقول Response::deny('...')
         * تعرف سببها أدقّ من هذا العموم.
         *
         * والنوع هنا AccessDeniedHttpException لا AuthorizationException:
         * لارافيل يحوّل الثاني إلى الأول في prepareException قبل أن يستدعي
         * callbacks العرض، فلا يُطابق النوع الأصلي شيئًا أبدًا.
         */
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $message = trim($e->getMessage());
            $isDefault = $message === '' || $message === 'This action is unauthorized.';

            return response()->json([
                'message' => $isDefault ? 'ليس لديك صلاحية لهذا الإجراء.' : $message,
            ], 403);
        });
    })->create();
