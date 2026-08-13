<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChangeRequest;
use App\Models\FeedbackRound;
use App\Models\Project;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * البيانات العابرة للمشاريع: اللوحة والتقارير.
 *
 * كان الفرونت يقول `select * from stages` بلا شرط ويصفّي في المتصفح —
 * وهو ما كان يعمل فقط لأن تقييد الصفوف كان يُحقن في السيرفر. هنا النطاق
 * صريح: مشاريع هذا المستخدم فقط، ومراحلها فقط.
 */
class OverviewController extends Controller
{
    /** اللوحة: المشاريع المرئية ومراحلها. */
    public function index(Request $request): JsonResponse
    {
        $projectIds = Project::query()->visibleTo($request->user())->pluck('id');

        return response()->json([
            'projects' => Project::whereIn('id', $projectIds)->latest('created_at')->get(),
            'stages'   => Stage::whereIn('project_id', $projectIds)->get(),
        ]);
    }

    /** التقارير: نفس النطاق، مع ما وقع داخل الشهر المطلوب. */
    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $start = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end = $start->copy()->addMonth();

        $projectIds = Project::query()->visibleTo($request->user())->pluck('id');

        return response()->json([
            'projects' => Project::whereIn('id', $projectIds)->get(),
            'stages'   => Stage::whereIn('project_id', $projectIds)->get(),
            'change_requests' => ChangeRequest::whereIn('project_id', $projectIds)
                ->whereBetween('created_at', [$start, $end])->get(),
            'feedback_rounds' => FeedbackRound::whereIn('project_id', $projectIds)
                ->whereBetween('created_at', [$start, $end])
                ->get(['id', 'project_id', 'created_at']),
        ]);
    }
}
