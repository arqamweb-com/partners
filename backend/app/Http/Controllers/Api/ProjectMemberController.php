<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProjectRole;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Notifications\AddedToProject;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * أعضاء المشروع ودعواتهم.
 *
 * صف واحد يخدم الحالتين: عضو مرتبط (user_id) أو دعوة تنتظر التسجيل
 * (invited_email بلا user_id). عند إنشاء حساب بنفس البريد تُربط تلقائيًا
 * في AuthController::claimInvites().
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->members()->with('user:id,full_name,email,system_role')->get(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role'  => ['required', Rule::enum(ProjectRole::class)],
        ]);

        $role = ProjectRole::from($data['role']);

        // إسناد دور تنفيذي من فريق أرقام قرار أوسع من مجرد الدعوة
        if (in_array($role, [ProjectRole::Lead, ProjectRole::Contributor], true)) {
            $this->authorize('assignStaff', $project);
        }

        $email = mb_strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        $member = ProjectMember::updateOrCreate(
            $user
                ? ['project_id' => $project->id, 'user_id' => $user->id]
                : ['project_id' => $project->id, 'invited_email' => $email],
            [
                'user_id'       => $user?->id,
                'invited_email' => $email,
                'role'          => $role,
                'invited_by'    => $request->user()->id,
                'claimed_at'    => $user ? now() : null,
            ],
        );

        // المستخدم القائم يُشعَر فورًا؛ المدعوّ بالبريد يجدها عند تسجيله
        if ($user) {
            $this->notifier->toUser($user, new AddedToProject($project, $request->user(), $role));
        }

        $this->audit->log($project, $request->user(), 'member_invited',
            sprintf('%s %s بصفة %s.',
                $user ? 'ربط' : 'دعوة', $email, $role->label()));

        return response()->json(['data' => $member->fresh('user')], 201);
    }

    public function update(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->authorize('assignStaff', $project);
        abort_unless($member->project_id === $project->id, 404);

        $member->update([
            'role' => ProjectRole::from($request->validate([
                'role' => ['required', Rule::enum(ProjectRole::class)],
            ])['role']),
        ]);

        return response()->json(['data' => $member->fresh('user')]);
    }

    public function destroy(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        $this->authorize('manageMembers', $project);
        abort_unless($member->project_id === $project->id, 404);

        // المالك لا يُخرَج من مشروعه
        abort_if($member->user_id === $project->owner_id, 422, 'لا يمكن إخراج مالك المشروع.');

        $member->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
