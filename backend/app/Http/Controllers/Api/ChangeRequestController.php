<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Enums\Side;
use App\Notifications\ChangeRequestCreated;
use App\Services\ChangeRequestService;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly ChangeRequestService $service,
        private readonly Notifier $notifier,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->changeRequests()->latest()->get()]);
    }

    /** طلب العميل يُسجَّل مسودة بلا سعر — التسعير فعل آخر بسياسة أخرى. */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('create', [ChangeRequest::class, $project]);

        $data = $request->validate([
            'title'                   => ['required', 'string', 'min:5', 'max:255'],
            'description'             => ['nullable', 'string', 'max:10000'],
            'source_feedback_item_id' => ['nullable', 'uuid', 'exists:feedback_items,id'],
            'resubmitted_from'        => ['nullable', 'uuid', 'exists:change_requests,id'],
        ]);

        // يُعاد تقديم الطلب المرفوض مرة واحدة فقط
        if (! empty($data['resubmitted_from'])
            && ChangeRequest::where('resubmitted_from', $data['resubmitted_from'])->exists()) {
            return response()->json([
                'message' => 'لا يمكن إعادة تقديم طلب التغيير أكثر من مرة واحدة.',
            ], 422);
        }

        $cr = $project->changeRequests()->create([
            ...$data,
            'requested_by' => $request->user()->id,
        ]);

        // طلب العميل ينتظر تسعيرًا من فريق أرقام
        if (! $request->user()->isStaff()) {
            $this->notifier->toSide($project, Side::Us,
                new ChangeRequestCreated($cr->fresh(), $request->user()));
        }

        return response()->json(['data' => $cr], 201);
    }

    /** التسعير والإرسال. */
    public function send(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $this->authorize('price', $changeRequest);

        $data = $request->validate([
            'price'                => ['required', 'numeric', 'min:0'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'duration_days'        => ['nullable', 'integer', 'min:0', 'max:3650'],
            'delivery_impact_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'quote_valid_until'    => ['nullable', 'date'],
            'decision_deadline'    => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->service->send($changeRequest, $request->user(), $data),
        ]);
    }

    /** الاعتماد أو الرفض — صاحب الاعتماد على جهة المستلِم وحده. */
    public function decide(Request $request, ChangeRequest $changeRequest): JsonResponse
    {
        $this->authorize('decide', $changeRequest);

        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'note'    => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json([
            'data' => $this->service->decide(
                $changeRequest,
                $request->user(),
                $data['approve'],
                $data['note'] ?? '',
            ),
        ]);
    }
}
