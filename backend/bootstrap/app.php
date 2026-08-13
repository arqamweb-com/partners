<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        // الرد على كل أخطاء الـ API بـ JSON لا HTML
        $middleware->api(prepend: [
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
