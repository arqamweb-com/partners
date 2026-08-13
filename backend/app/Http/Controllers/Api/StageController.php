<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stage;
use App\Services\StageWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * انتقالات المراحل — أفعال صريحة، لا تعديل أعمدة.
 *
 * الفارق عن /api/db العام في النسخة السابقة: هناك كان المتصفح يرسل أي
 * أعمدة والسيرفر ينقّيها بقائمة بيضاء. هنا لا يوجد مسار لكتابة عمود
 * أصلًا — فقط «قدّم» و«اعتمد» و«ارفض»، وكل واحد وراءه سياسة.
 */
class StageController extends Controller
{
    public function __construct(private readonly StageWorkflow $workflow) {}

    public function submit(Request $request, Stage $stage): JsonResponse
    {
        $this->authorize('submit', $stage);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:4000']]);

        return response()->json([
            'data' => $this->workflow->submit($stage, $request->user(), $data['note'] ?? ''),
        ]);
    }

    public function approve(Request $request, Stage $stage): JsonResponse
    {
        $this->authorize('approve', $stage);

        $data = $request->validate([
            'approver_name'   => ['required', 'string', 'min:3', 'max:255'],
            'acknowledgement' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json([
            'data' => $this->workflow->approve(
                $stage,
                $request->user(),
                $data['approver_name'],
                $data['acknowledgement'] ?? '',
            ),
        ]);
    }

    public function reject(Request $request, Stage $stage): JsonResponse
    {
        $this->authorize('reject', $stage);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:4000'],
        ]);

        return response()->json([
            'data' => $this->workflow->reject($stage, $request->user(), $data['reason']),
        ]);
    }
}
