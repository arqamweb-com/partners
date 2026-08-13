<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccessItemController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChangeRequestController;
use App\Http\Controllers\Api\ContentItemController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * مسارات الـ API.
 *
 * ملاحظة معمارية: لا يوجد هنا مكافئ لـ POST /api/db العام. ذلك المسار
 * هو ما جعل معظم الثغرات ممكنة — كان المتصفح يسمّي الجدول والأعمدة،
 * والسيرفر ينقّي بقائمة بيضاء. أي ثغرة في القائمة = ثغرة في النظام.
 *
 * البديل: كل فعل مسار باسمه ووراءه سياسة. لاحظ أن الأفعال التي كانت
 * تعديلًا لعمود واحد صارت مسارات منفصلة — لأن «العميل يقدّم» و«أرقام
 * يقبل» ليسا نفس الصلاحية وإن كانا نفس العمود.
 */

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('guest');
    Route::post('login', [AuthController::class, 'login'])->middleware('guest');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth');
    Route::get('me', [AuthController::class, 'me']);

    // استعادة كلمة المرور — للزائر، ومكبوحة على البريد وعلى الـ IP
    Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('guest');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('guest');
});

Route::middleware('auth')->group(function () {

    // ---- اللوحة والتقارير ----
    Route::get('overview', [OverviewController::class, 'index']);
    Route::get('reports', [OverviewController::class, 'reports']);

    // ---- المشاريع ----
    Route::get('project-types', [ProjectController::class, 'types']);
    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store']);
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::patch('projects/{project}', [ProjectController::class, 'update']);
    Route::patch('projects/{project}/charter', [ProjectController::class, 'updateCharter']);
    Route::post('projects/{project}/approve', [ProjectController::class, 'approve']);
    Route::post('projects/{project}/status', [ProjectController::class, 'changeStatus']);
    Route::get('projects/{project}/audit-log', [ProjectController::class, 'auditLog']);
    Route::put('projects/{project}/stage-plan', [ProjectController::class, 'saveStagePlan']);
    Route::post('projects/{project}/reactivate', [ProjectController::class, 'reactivate']);

    // ---- الأعضاء والدعوات ----
    Route::get('projects/{project}/members', [ProjectMemberController::class, 'index']);
    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store']);
    Route::patch('projects/{project}/members/{member}', [ProjectMemberController::class, 'update']);
    Route::delete('projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy']);

    // ---- دورة اعتماد المراحل ----
    Route::post('stages/{stage}/submit', [StageController::class, 'submit']);
    Route::post('stages/{stage}/approve', [StageController::class, 'approve']);
    Route::post('stages/{stage}/reject', [StageController::class, 'reject']);

    // ---- قائمة المحتوى: التقديم والمراجعة فعلان بسياستين ----
    Route::get('projects/{project}/content-items', [ContentItemController::class, 'index']);
    Route::post('projects/{project}/content-items', [ContentItemController::class, 'store']);
    Route::post('content-items/{contentItem}/submit', [ContentItemController::class, 'submit']);
    Route::post('content-items/{contentItem}/review', [ContentItemController::class, 'review']);

    // ---- قائمة الوصول ----
    Route::get('projects/{project}/access-items', [AccessItemController::class, 'index']);
    Route::post('projects/{project}/access-items', [AccessItemController::class, 'store']);
    Route::post('access-items/{accessItem}/toggle', [AccessItemController::class, 'toggle']);
    Route::patch('access-items/{accessItem}', [AccessItemController::class, 'update']);

    // ---- جولات الملاحظات ----
    Route::get('projects/{project}/feedback', [FeedbackController::class, 'index']);
    Route::post('projects/{project}/feedback', [FeedbackController::class, 'storeRound']);
    Route::post('feedback-rounds/{feedbackRound}/submit', [FeedbackController::class, 'submitRound']);
    Route::post('feedback-rounds/{feedbackRound}/classify', [FeedbackController::class, 'classifyRound']);
    Route::post('feedback-rounds/{feedbackRound}/items', [FeedbackController::class, 'storeItem']);
    Route::post('feedback-items/{feedbackItem}/classify', [FeedbackController::class, 'classifyItem']);
    Route::post('feedback-items/{feedbackItem}/object', [FeedbackController::class, 'objectToItem']);

    // ---- طلبات التغيير: التسعير والقرار فعلان بسياستين ----
    Route::get('projects/{project}/change-requests', [ChangeRequestController::class, 'index']);
    Route::post('projects/{project}/change-requests', [ChangeRequestController::class, 'store']);
    Route::post('change-requests/{changeRequest}/send', [ChangeRequestController::class, 'send']);
    Route::post('change-requests/{changeRequest}/decide', [ChangeRequestController::class, 'decide']);

    // ---- الملفات ----
    Route::post('files', [FileController::class, 'upload']);
    Route::get('files/{upload}', [FileController::class, 'download']);
    Route::post('projects/{project}/files/claim', [FileController::class, 'claim']);
    Route::delete('files/{upload}', [FileController::class, 'destroy']);

    // ---- الحسابات والأدوار: للأدمن وحده (UserPolicy) ----
    // لاحظ أن تغيير كلمة المرور مسار مستقل عن تعديل الحساب، وأن الدور
    // والتفعيل يمرّان بصلاحية أوسع داخل update — «عدّل الاسم» و«ارفعه
    // أدمن» ليسا نفس الفعل وإن كانا نفس الجدول.
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::patch('users/{user}', [UserController::class, 'update']);
    Route::post('users/{user}/password', [UserController::class, 'setPassword']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);

    // ---- الإعدادات ----
    Route::get('settings', [SettingsController::class, 'show']);
    Route::patch('settings', [SettingsController::class, 'update']);
    Route::post('settings/holidays', [SettingsController::class, 'storeHoliday']);
    Route::delete('settings/holidays/{holiday}', [SettingsController::class, 'destroyHoliday']);
    Route::post('settings/price-items', [SettingsController::class, 'storePriceItem']);
    Route::delete('settings/price-items/{crPriceItem}', [SettingsController::class, 'destroyPriceItem']);

    // ---- الإشعارات ----
    Route::get('notifications', fn (Request $r) => response()->json([
        'data'   => $r->user()->notifications()->limit(50)->get(),
        'unread' => $r->user()->unreadNotifications()->count(),
    ]));

    Route::post('notifications/read', function (Request $r) {
        $r->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    });
});

Route::get('health', fn () => response()->json([
    'ok'       => true,
    'time'     => now()->toIso8601String(),
    'database' => config('database.connections.mysql.database'),
]));
