<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\SystemRole;
use App\Enums\Side;
use App\Notifications\ProjectRequested;
use App\Notifications\ProjectStatusChanged;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\ChangeRequestService;
use App\Services\ProjectSeeder;
use App\Services\ProjectTypeRegistry;
use App\Services\StagePlanEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * المشاريع.
 *
 * الأفعال مفصولة عمدًا: البيانات الأساسية شيء، والبنود التعاقدية
 * (التواريخ والضمان وجولات التعديل) شيء آخر لا يملكه إلا من يسعّر،
 * واعتماد الطلب فعل ثالث. في النسخة السابقة كانت الثلاثة عمودًا في
 * نفس طلب التعديل، والتمييز بينها قائمة أعمدة في مصفوفة.
 */
class ProjectController extends Controller
{
    /** نصوص الحالات كما تُعرض في الإشعار. */
    private const STATUS_LABELS = [
        'active'          => 'صار نشطًا',
        'awaiting_client' => 'في انتظارك',
        'frozen'          => 'اتجمّد',
        'completed'       => 'اكتمل',
        'stopped'         => 'اتوقف',
    ];

    public function __construct(
        private readonly ProjectTypeRegistry $types,
        private readonly ProjectSeeder $seeder,
        private readonly ChangeRequestService $changeRequests,
        private readonly StagePlanEditor $stagePlan,
        private readonly AuditLogger $audit,
        private readonly \App\Services\Notifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        // الأرشيف شاشة مستقلة للأدمن، لا حالة إضافية في القائمة العادية:
        // من لا يملك الحذف لا يرى المحذوف أصلًا
        $archived = $request->boolean('archived');

        if ($archived) {
            $this->authorize('viewArchive', Project::class);
        }

        $projects = Project::query()
            ->when($archived, fn ($q) => $q->onlyTrashed()->with('archivedBy:id,full_name,email'))
            ->visibleTo($request->user())
            ->with('members:id,project_id,user_id,role')
            ->when($request->string('status')->isNotEmpty(),
                fn ($q) => $q->where('status', $request->string('status')))
            ->latest($archived ? 'deleted_at' : 'updated_at')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json($projects);
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'data' => $project->load([
                'stages', 'accessItems', 'contentItems',
                'feedbackRounds.items', 'changeRequests',
                'members.user:id,full_name,email',
            ]),
        ]);
    }

    /**
     * تسجيل طلب مشروع.
     *
     * العميل يسجّل الأساسيات ومواصفاته. المدد والتسعير لا يُقبلان من هنا
     * إطلاقًا — لا بقائمة أعمدة تُنقّى، بل لأن التحقق لا يعرفها أصلًا.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'end_client_name' => ['nullable', 'string', 'max:255'],
            'partner_agency'  => ['nullable', 'string', 'max:255'],
            'project_type'    => ['required', Rule::in($this->types->ids())],
            'type_details'    => ['nullable', 'array'],
            'intake_data'     => ['nullable', 'array'],
            'scope'           => ['nullable', 'string', 'max:20000'],
            'out_of_scope'    => ['nullable', 'string', 'max:20000'],
            'owner_name'      => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $project = Project::create([
            ...$data,
            'owner_id'   => $user->id,
            'owner_name' => $data['owner_name'] ?? '' ?: $user->full_name,
            // طلب العميل يبدأ مسودة تنتظر مراجعة فريق أرقام؛ ومشروع
            // فريق أرقام يبدأ نشطًا مباشرة
            'status'     => $user->isStaff() ? ProjectStatus::Active : ProjectStatus::Draft,
        ]);

        // المنشئ عضو في مشروعه بالصفة المناسبة لدوره
        $project->members()->create([
            'user_id' => $user->id,
            'role'    => $user->isStaff()
                ? \App\Enums\ProjectRole::Lead
                : \App\Enums\ProjectRole::Client,
        ]);

        $this->audit->log($project, $user, 'project_created',
            sprintf('تسجيل %s «%s» من نوع %s.',
                $user->isStaff() ? 'مشروع' : 'طلب مشروع',
                $project->name, $this->types->label($project->project_type)));

        // طلب العميل ينتظر مراجعة: لا أحد من الفريق عضو فيه بعد، فالتوجيه
        // بدور النظام لا بالعضوية — وإلا لم يعرف أحد أن هناك طلبًا
        if ($project->status === ProjectStatus::Draft) {
            $this->notifier->toSystemRoles(
                new ProjectRequested($project, $user),
                SystemRole::Admin,
                SystemRole::Manager,
            );
        }

        return response()->json(['data' => $project->fresh('members')], 201);
    }

    /** البيانات الأساسية والمواصفات. */
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'end_client_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'partner_agency'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'type_details'    => ['sometimes', 'nullable', 'array'],
            'intake_data'     => ['sometimes', 'nullable', 'array'],
            'scope'           => ['sometimes', 'nullable', 'string', 'max:20000'],
            'out_of_scope'    => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes'           => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        $project->update($data);

        return response()->json(['data' => $project->fresh()]);
    }

    /**
     * البنود التعاقدية — لمن يملك التسعير وحده.
     * تعديل تاريخ التسليم أو أيام التأخير يعيد حساب التسليم المعدَّل.
     */
    public function updateCharter(Request $request, Project $project): JsonResponse
    {
        $this->authorize('updateCharter', $project);

        $data = $request->validate([
            'original_delivery_date'  => ['sometimes', 'nullable', 'date'],
            'client_delay_days'       => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'warranty_days'           => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'revision_rounds_allowed' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'track'                   => ['sometimes', Rule::in(['normal', 'fast_track'])],
            'payment_milestones'      => ['sometimes', 'nullable', 'array'],
            'supported_devices'       => ['sometimes', 'nullable', 'string', 'max:2000'],
            'supported_browsers'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'supported_screens'       => ['sometimes', 'nullable', 'string', 'max:2000'],
            'queue_slot_date'         => ['sometimes', 'nullable', 'date'],
            'reactivation_fee'        => ['sometimes', 'numeric', 'min:0'],
            'credit_amount'           => ['sometimes', 'numeric', 'min:0'],
            'credit_expires_at'       => ['sometimes', 'nullable', 'date'],
        ]);

        $project->update($data);

        // adjusted_delivery_date لا تُقبل من الطلب — تُحسب دائمًا
        if (array_intersect(['original_delivery_date', 'client_delay_days'], array_keys($data))) {
            $this->changeRequests->syncAdjustedDelivery($project->fresh());
        }

        return response()->json(['data' => $project->fresh()]);
    }

    /**
     * اعتماد الطلب: يبذر المراحل والقوائم من القالب ويحوّله لمشروع نشط.
     * كان البذر في المتصفح بأربع عمليات بلا معاملة — الآن معاملة واحدة.
     */
    public function approve(Request $request, Project $project): JsonResponse
    {
        $this->authorize('seed', $project);

        $data = $request->validate([
            'stages'                 => ['nullable', 'array'],
            'stages.*.name'          => ['required_with:stages', 'string', 'max:255'],
            'stages.*.gate_name'     => ['nullable', 'string', 'max:255'],
            'stages.*.gate_size'     => ['nullable', 'string', 'max:32'],
            'stages.*.our_duration_days'   => ['required_with:stages', 'integer', 'min:0', 'max:3650'],
            'stages.*.their_duration_days' => ['required_with:stages', 'integer', 'min:0', 'max:3650'],
        ]);

        return response()->json([
            'data' => $this->seeder->seed($project, $request->user(), $data['stages'] ?? null),
        ]);
    }

    /** تغيير حالة المشروع (تجميد، إيقاف، إتمام). */
    public function changeStatus(Request $request, Project $project): JsonResponse
    {
        $this->authorize('changeStatus', $project);

        $data = $request->validate([
            'status' => ['required', Rule::enum(ProjectStatus::class),
                          Rule::notIn([ProjectStatus::Draft->value])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = ProjectStatus::from($data['status']);

        // الاعتماد في اتجاه واحد: لا رجوع بمشروع قائم إلى حالة طلب
        $project->update([
            'status'    => $status,
            'frozen_at' => $status === ProjectStatus::Frozen ? now() : null,
        ]);

        $this->audit->log($project, $request->user(), 'project_status_changed',
            sprintf('تغيير حالة المشروع إلى «%s».%s',
                $status->value, $data['reason'] ? ' السبب: '.$data['reason'] : ''));

        $this->notifier->toSide($project, Side::Them, new ProjectStatusChanged(
            $project->fresh(), $request->user(),
            self::STATUS_LABELS[$status->value] ?? $status->value,
            (string) ($data['reason'] ?? ''),
        ));

        return response()->json(['data' => $project->fresh()]);
    }

    /**
     * حفظ خطة المراحل دفعة واحدة.
     * قواعد المقفولة (لا تُحذف ولا تتحرّك) في السيرفر لا في المتصفح.
     */
    public function saveStagePlan(Request $request, Project $project): JsonResponse
    {
        $this->authorize('updateCharter', $project);

        $data = $request->validate([
            'stages'                       => ['required', 'array', 'max:60'],
            'stages.*.id'                  => ['nullable', 'uuid'],
            'stages.*.name'                => ['required', 'string', 'max:200'],
            'stages.*.gate_name'           => ['nullable', 'string', 'max:200'],
            'stages.*.gate_size'           => ['nullable', 'string', 'max:32'],
            'stages.*.our_duration_days'   => ['required', 'integer', 'min:0', 'max:3650'],
            'stages.*.their_duration_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        return response()->json([
            'data' => $this->stagePlan->save($project, $request->user(), $data['stages']),
        ]);
    }

    /**
     * إعادة تنشيط مشروع مجمَّد بموعد دور جديد ورسوم.
     * فعل تعاقدي مستقل، لا تعديل أعمدة متفرّقة.
     */
    public function reactivate(Request $request, Project $project): JsonResponse
    {
        $this->authorize('updateCharter', $project);

        $data = $request->validate([
            'queue_slot_date'  => ['required', 'date'],
            'reactivation_fee' => ['required', 'numeric', 'min:0'],
            'note'             => ['nullable', 'string', 'max:2000'],
        ]);

        $project->update([
            'status'                 => ProjectStatus::Active,
            'frozen_at'              => null,
            'queue_slot_date'        => $data['queue_slot_date'],
            'reactivation_fee'       => $data['reactivation_fee'],
            'reactivated_at'         => now(),
            // الدور الجديد يعيد ضبط خط التسليم من أوله
            'original_delivery_date' => $data['queue_slot_date'],
            'adjusted_delivery_date' => $data['queue_slot_date'],
            'client_delay_days'      => 0,
        ]);

        $this->notifier->toSide($project, Side::Them, new ProjectStatusChanged(
            $project->fresh(), $request->user(), 'أُعيد تنشيطه بموعد دور جديد',
            'موعد الدور: '.$data['queue_slot_date'],
        ));

        $this->audit->log($project, $request->user(), 'project_reactivated', sprintf(
            'إعادة تنشيط المشروع برسوم %s، وموعد دور جديد %s.%s',
            $data['reactivation_fee'], $data['queue_slot_date'],
            ! empty($data['note']) ? ' ملاحظة: '.$data['note'] : '',
        ));

        return response()->json(['data' => $project->fresh()]);
    }

    /** قوالب الأنواع — يقرأها الفرونت لبناء المعالج. */
    public function types(): JsonResponse
    {
        return response()->json(['data' => array_values($this->types->all())]);
    }

    public function auditLog(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['data' => $project->auditLogs()->limit(500)->get()]);
    }

    /**
     * أرشفة المشروع.
     *
     * حذف ناعم: الصف يبقى بكل ما تحته — المراحل والاعتمادات وسجل التدقيق —
     * ويختفي المشروع من كل استعلام لأن SoftDeletes نطاق عام على الموديل.
     * فلا شاشة تحتاج أن تتذكّر استثناءه.
     *
     * الإشعارات وحدها تُمسح فعلًا لا تُخفى: الإشعار وعدٌ بأن الضغط عليه
     * يفتح شيئًا، وإشعار على مشروع مؤرشف وعد مكسور. وهي — بخلاف سجل
     * التدقيق — ليست مستند إثبات.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $user = $request->user();

        // السجل يُكتب قبل الأرشفة لا بعدها: بعدها المشروع خارج كل استعلام
        $this->audit->log($project, $user, 'project_archived',
            sprintf('أرشفة المشروع «%s» وإخفاؤه من كل الشاشات.', $project->name));

        $counts = DB::transaction(function () use ($project, $user) {
            $notifications = DB::table('notifications')->where('project_id', $project->id)->delete();

            $project->forceFill(['deleted_by' => $user->id])->save();
            $project->delete();

            return ['notifications' => $notifications];
        });

        Log::info('arqam.project_archived', [
            'actor'         => $user->email,
            'project'       => $project->id,
            'name'          => $project->name,
            'status'        => $project->status->value,
            'notifications' => $counts['notifications'],
            'ip'            => $request->ip(),
        ]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** إعادة مشروع من الأرشيف كما كان. */
    public function restore(Request $request, Project $project): JsonResponse
    {
        $this->authorize('restore', $project);

        abort_unless($project->trashed(), 422, 'هذا المشروع ليس في الأرشيف.');

        $user = $request->user();

        $project->restore();
        $project->forceFill(['deleted_by' => null])->save();

        $this->audit->log($project, $user, 'project_restored',
            sprintf('إعادة المشروع «%s» من الأرشيف.', $project->name));

        Log::info('arqam.project_restored', [
            'actor'   => $user->email,
            'project' => $project->id,
            'name'    => $project->name,
            'ip'      => $request->ip(),
        ]);

        return response()->json(['data' => $project->fresh()]);
    }

    /**
     * الحذف النهائي — من الأرشيف وحده، ولا رجعة فيه.
     *
     * القيود في قاعدة البيانات تتولّى الصفوف التابعة (cascade). ما لا
     * تتولّاه هو الملفات على القرص: صف الرفع يُمسح والملف يبقى يتيمًا
     * لا يعرف أحد أنه هناك. فتُمسح هنا صراحة قبل الحذف.
     *
     * وقبل كل ذلك يُكتب ملخّص في اللوج: سجل التدقيق نفسه يُمسح بعد لحظة،
     * فلو لم يُكتب هنا لم يبقَ أثر لأن المشروع كان موجودًا أصلًا.
     */
    public function forceDestroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('forceDelete', $project);

        $user    = $request->user();
        $uploads = $project->uploads()->get(['id', 'stored_path']);

        Log::warning('arqam.project_purged', [
            'actor'     => $user->email,
            'project'   => $project->id,
            'name'      => $project->name,
            'status'    => $project->status->value,
            'stages'    => $project->stages()->count(),
            'audit_log' => $project->auditLogs()->count(),
            'files'     => $uploads->count(),
            'ip'        => $request->ip(),
        ]);

        DB::transaction(function () use ($project) {
            $project->forceDelete();
        });

        // بعد نجاح المعاملة لا قبلها: ملف محذوف ومعاملة فاشلة = صف يشير
        // إلى لا شيء، وهو أسوأ من ملف يتيم
        foreach ($uploads as $upload) {
            Storage::disk('private')->delete($upload->stored_path);
        }

        return response()->json(['data' => ['ok' => true]]);
    }
}
