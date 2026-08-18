<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * الواجهة تطبيق صفحة واحدة، والتوجيه بداخلها في المتصفح لا هنا. فأي مسار
 * ليس ‎/api‎ يردّ index.html ويكمل TanStack Router من عنده.
 *
 * بدون هذا: فتح ‎/dashboard‎ مباشرة أو تحديث الصفحة يصل لارافيل، فلا يجد
 * مسارًا بهذا الاسم ويردّ 404 — والمستخدم يظن التطبيق معطوبًا. المشكلة لا
 * تظهر أثناء التطوير لأن vite هو من يقدّم الصفحة هناك.
 *
 * ‎/api‎ مستثنى صراحةً: مسار API غير معروف يستحق 404 بصيغة JSON، لا صفحة
 * HTML يحاول عميل JSON تفسيرها فيقع في خطأ لا علاقة له بالسبب.
 */
Route::fallback(function (Request $request) {
    abort_if($request->is('api/*'), 404, 'مسار غير معروف.');

    $index = public_path('index.html');

    // نصّ صريح بدل صفحة 404 الفارغة: هذا الخطأ لا يقع إلا في نشر ناقص،
    // ومن يقرأه هو من ينشر — فليقرأ سببه لا رقمه
    if (! is_file($index)) {
        return response(
            'الواجهة غير مبنية. شغّل npm run build وانسخ محتويات dist/ إلى backend/public.',
            404,
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    return response()->file($index);
});
