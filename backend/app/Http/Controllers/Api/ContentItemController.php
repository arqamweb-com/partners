<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Project;
use App\Enums\Side;
use App\Notifications\ContentReviewed;
use App\Notifications\ContentSubmitted;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\ProjectParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * قائمة المحتوى الحاكم.
 *
 * فعلان منفصلان بسياستين: العميل يقدّم، وفريق أرقام يراجع. في النسخة
 * السابقة كان الاثنان تعديلًا لعمود status، فأمكن للعميل أن يقبل محتواه.
 */
class ContentItemController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->contentItems]);
    }

    /** تقديم العنصر للمراجعة. */
    public function submit(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('submit', $contentItem);

        $data = $request->validate([
            'value' => ['required', 'string', 'max:4000'],
        ]);

        $contentItem->update([
            'value'            => $data['value'],
            'status'           => ContentStatus::Submitted,
            'submitted_by'     => $request->user()->id,
            // تاريخ التقديم الأصلي لا يتحرّك عند إعادة التقديم — عليه يُحسب التأخير
            'submitted_at'     => $contentItem->submitted_at ?? now(),
            'reviewed_at'      => null,
            'reviewed_by'      => null,
            'rejection_reason' => null,
        ]);

        $this->audit->log($contentItem->project, $request->user(), 'content_submitted',
            "تقديم عنصر المحتوى «{$contentItem->name}» للمراجعة.");

        // المراجعة على الطرف المقابل لمن قدّم
        $side = ProjectParty::for($request->user(), $contentItem->project)->side() ?? Side::Them;
        $this->notifier->toSide($contentItem->project, $side->other(),
            new ContentSubmitted($contentItem->fresh(), $request->user()));

        return response()->json(['data' => $contentItem->fresh()]);
    }

    /** القبول أو الرفض — فريق أرقام وحده. */
    public function review(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('review', $contentItem);

        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'reason' => ['required_if:accept,false', 'nullable', 'string', 'min:5', 'max:4000'],
        ]);

        $accept = $data['accept'];

        $contentItem->update([
            'status'           => $accept ? ContentStatus::Accepted : ContentStatus::Rejected,
            'reviewed_at'      => now(),
            'reviewed_by'      => $request->user()->id,
            'rejection_reason' => $accept ? null : $data['reason'],
        ]);

        $this->audit->log($contentItem->project, $request->user(),
            $accept ? 'content_accepted' : 'content_rejected',
            $accept
                ? "قبول عنصر المحتوى «{$contentItem->name}»."
                : "رفض عنصر المحتوى «{$contentItem->name}» — {$data['reason']}"
                  .' (التأخير يُحتسب من تاريخ التقديم الأصلي).');

        $this->notifier->toSide($contentItem->project, Side::Them,
            new ContentReviewed($contentItem->fresh(), $request->user(), $accept));

        return response()->json(['data' => $contentItem->fresh()]);
    }

    /**
     * القبول التلقائي بعد تجاوز مهلة المراجعة.
     * كان في المتصفح داخل useEffect — يعمل فقط إن صادف أدمن فاتح الصفحة.
     * الآن أمر مجدول: php artisan arqam:auto-accept
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'item_group'          => ['required', 'in:blocking,non_blocking'],
            'name'                => ['required', 'string', 'max:255'],
            'acceptance_criteria' => ['nullable', 'string', 'max:4000'],
            'due_at'              => ['nullable', 'date'],
        ]);

        $item = $project->contentItems()->create([
            ...$data,
            'item_order' => (int) $project->contentItems()->max('item_order') + 1,
        ]);

        return response()->json(['data' => $item], 201);
    }
}
