<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackRoundStatus;
use App\Http\Controllers\Controller;
use App\Models\FeedbackItem;
use App\Models\FeedbackRound;
use App\Models\Project;
use App\Enums\Side;
use App\Notifications\FeedbackClassified;
use App\Notifications\FeedbackRoundOpened;
use App\Notifications\FeedbackRoundSubmitted;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * جولات الملاحظات وبنودها.
 *
 * الحالة تتحرك في اتجاه واحد: open -> submitted -> classified -> closed،
 * والعميل يملك الخطوة الأولى وحدها. كان يقدر يقفز مباشرة لأي حالة.
 */
class FeedbackController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->feedbackRounds()->with('items')->get()]);
    }

    /** فتح جولة جديدة — فريق أرقام. */
    public function storeRound(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'stage_id' => ['nullable', 'uuid', 'exists:stages,id'],
            'is_paid'  => ['boolean'],
        ]);

        $round = $project->feedbackRounds()->create([
            ...$data,
            'round_number' => (int) $project->feedbackRounds()->max('round_number') + 1,
            'status'       => FeedbackRoundStatus::Open,
            'opened_at'    => now(),
        ]);

        $this->notifier->toSide($project, Side::Them,
            new FeedbackRoundOpened($round->fresh(), $request->user()));

        return response()->json(['data' => $round], 201);
    }

    /** إرسال الجولة — جهة المستلِم، وعلى جولة مفتوحة فقط. */
    public function submitRound(Request $request, FeedbackRound $feedbackRound): JsonResponse
    {
        $this->authorize('submit', $feedbackRound);

        if ($feedbackRound->items()->count() === 0) {
            return response()->json([
                'message' => 'أضف ملاحظة واحدة على الأقل قبل الإرسال.',
            ], 422);
        }

        $feedbackRound->update([
            'status'       => FeedbackRoundStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $this->audit->log($feedbackRound->project, $request->user(), 'feedback_round_submitted',
            sprintf('إرسال جولة الملاحظات رقم %d بـ %d ملاحظة.',
                $feedbackRound->round_number, $feedbackRound->items()->count()));

        $this->notifier->toSide($feedbackRound->project, Side::Us,
            new FeedbackRoundSubmitted($feedbackRound->fresh(), $request->user()));

        return response()->json(['data' => $feedbackRound->fresh('items')]);
    }

    /** التصنيف والإقفال — فريق أرقام. */
    public function classifyRound(Request $request, FeedbackRound $feedbackRound): JsonResponse
    {
        $this->authorize('classify', $feedbackRound);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                FeedbackRoundStatus::Classified->value,
                FeedbackRoundStatus::Closed->value,
            ])],
        ]);

        $status = FeedbackRoundStatus::from($data['status']);

        $feedbackRound->update([
            'status'    => $status,
            'closed_at' => $status === FeedbackRoundStatus::Closed ? now() : null,
        ]);

        $this->notifier->toSide($feedbackRound->project, Side::Them,
            new FeedbackClassified($feedbackRound->fresh(), $request->user()));

        return response()->json(['data' => $feedbackRound->fresh()]);
    }

    /** إضافة ملاحظة — نافذة الجولة لازم تكون مفتوحة. */
    public function storeItem(Request $request, FeedbackRound $feedbackRound): JsonResponse
    {
        $this->authorize('addItem', $feedbackRound);

        $item = $feedbackRound->items()->create([
            ...$request->validate([
                'description'     => ['required', 'string', 'max:10000'],
                'page_or_section' => ['nullable', 'string', 'max:255'],
            ]),
            'project_id' => $feedbackRound->project_id,
        ]);

        return response()->json(['data' => $item], 201);
    }

    /** تصنيف الملاحظة — قرار فريق أرقام. */
    public function classifyItem(Request $request, FeedbackItem $feedbackItem): JsonResponse
    {
        $this->authorize('classify', $feedbackItem->round);

        $data = $request->validate([
            'classification' => ['required', 'in:defect,enhancement,new_scope'],
            'resolution'     => ['nullable', 'in:fixed,converted_to_cr,goodwill_fix'],
        ]);

        $feedbackItem->update([
            ...$data,
            'classified_at' => now(),
            'classified_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $feedbackItem->fresh()]);
    }

    /** اعتراض العميل على التصنيف — وقت الاعتراض من السيرفر. */
    public function objectToItem(Request $request, FeedbackItem $feedbackItem): JsonResponse
    {
        $this->authorize('addItem', $feedbackItem->round);

        $feedbackItem->update([
            'objection_text' => $request->validate([
                'objection_text' => ['required', 'string', 'min:5', 'max:4000'],
            ])['objection_text'],
            'objection_at' => now(),
        ]);

        return response()->json(['data' => $feedbackItem->fresh()]);
    }
}
