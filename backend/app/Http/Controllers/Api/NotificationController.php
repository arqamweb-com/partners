<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الإشعارات داخل التطبيق.
 *
 * كان المسار سطرًا واحدًا يعيد آخر خمسين ولا شيء غيرها، فما زاد على
 * الخمسين لم يكن له وجود من ناحية المستخدم. وكان «تعليم مقروء» فعلًا
 * واحدًا يبتلع الكل — من فتح الجرس ليقرأ إشعارًا خسر أثر ما لم يقرأه.
 *
 * الفعلان مفصولان هنا: إشعار بعينه، أو الكل صراحةً. والقائمة مصفّحة،
 * فالجرس يطلب صفحة قصيرة والصفحة الكاملة تطلب ما بعدها.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter'   => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        $page = $user->notifications()
            ->when(($filters['filter'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'data' => $page->items(),
            // العدّاد على غير المقروء كله لا على الصفحة: الشارة تقول
            // «كم ينتظرك»، لا «كم منها ظهر في هذه الصفحة»
            'unread'       => $user->unreadNotifications()->count(),
            'total'        => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'per_page'     => $page->perPage(),
        ]);
    }

    /** تعليم إشعار بعينه مقروءًا — للمستخدم على إشعاراته هو فقط. */
    public function read(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $item->markAsRead();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }
}
