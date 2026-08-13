<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * إدارة الحسابات والأدوار — الواجهة المقابلة لـ php artisan arqam:user.
 *
 * لماذا صار للأمر مسار في الواجهة أصلًا؟ لأن التيرمينال يفترض وصولًا
 * للسيرفر، ومن يدير الفريق يوميًا ليس بالضرورة من يملك ذلك الوصول. الأمر
 * باقٍ كما هو ويعمل كبديل — هذا الملف لا يستبدله بل يفتح نفس الأفعال
 * لمن يملكها في النظام (الأدمن وحده، انظر UserPolicy).
 *
 * ما لم يُفتح: التسجيل الذاتي ما زال ينشئ «عميلًا» دائمًا. الأدوار الأخرى
 * لا يبلغها أحد إلا بقرار أدمن صريح من هنا أو من التيرمينال.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'q'        => ['nullable', 'string', 'max:255'],
            'role'     => ['nullable', Rule::enum(SystemRole::class)],
            'status'   => ['nullable', Rule::in(['active', 'disabled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $users = User::query()
            ->withCount(['memberships', 'ownedProjects'])
            ->when($filters['q'] ?? null, function ($query, string $term) {
                // الهروب يدوي: % و _ في بحث المستخدم معناهما «أي شيء»،
                // وبحث عن «a_b» يجب أن يجد a_b لا axb
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

                $query->where(fn ($q) => $q
                    ->where('email', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('agency_name', 'like', $like)
                    ->orWhere('partner_agency', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($q, string $role) => $q->where('system_role', $role))
            ->when($filters['status'] ?? null,
                fn ($q, string $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('system_role')
            ->orderBy('full_name')
            ->paginate($filters['per_page'] ?? 50);

        $users->through(fn (User $user) => $this->row($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8', 'max:255'],
            'full_name'   => ['required', 'string', 'max:255'],
            'system_role' => ['required', Rule::enum(SystemRole::class)],
            'agency_name' => ['nullable', 'string', 'max:255'],
            // الشريك بلا وكالة لا يرى شيئًا — انظر Project::visibleTo
            'partner_agency' => ['nullable', 'string', 'max:255', 'required_if:system_role,partner'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $role = SystemRole::from($data['system_role']);

        $user = DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'email'          => mb_strtolower(trim($data['email'])),
                'password'       => $data['password'],
                'full_name'      => $data['full_name'],
                'agency_name'    => $data['agency_name'] ?? null,
                'system_role'    => $role,
                'partner_agency' => $role === SystemRole::Partner ? $data['partner_agency'] : null,
                'is_active'      => $data['is_active'] ?? true,
            ]);

            // دعوات سابقة بنفس البريد تُربط فورًا، تمامًا كما لو سجّل بنفسه
            ProjectMember::claimInvitesFor($user);

            return $user;
        });

        $this->record($request, 'account_created', $user, "بدور {$role->value}");

        return response()->json(['data' => $this->row($user->loadCount(['memberships', 'ownedProjects']))], 201);
    }

    /**
     * تعديل حساب.
     *
     * الدور والتفعيل يمرّان بصلاحية أوسع من بقية الحقول (govern لا update)
     * لأنهما وحدهما ما يغيّر ما يستطيع الحساب فعله.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'full_name'      => ['sometimes', 'string', 'max:255'],
            'email'          => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'agency_name'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'partner_agency' => ['sometimes', 'nullable', 'string', 'max:255'],
            'system_role'    => ['sometimes', Rule::enum(SystemRole::class)],
            'is_active'      => ['sometimes', 'boolean'],
        ]);

        $touchesPower = array_key_exists('system_role', $data) || array_key_exists('is_active', $data);
        $this->authorize($touchesPower ? 'govern' : 'update', $user);

        $role = isset($data['system_role']) ? SystemRole::from($data['system_role']) : $user->system_role;
        $active = $data['is_active'] ?? $user->is_active;

        if ($role !== SystemRole::Admin || ! $active) {
            $this->guardLastAdmin($user);
        }

        $agency = array_key_exists('partner_agency', $data) ? $data['partner_agency'] : $user->partner_agency;
        abort_if(
            $role === SystemRole::Partner && blank($agency),
            422,
            'الشريك بلا اسم وكالة لن يرى أي مشروع. حدّد الوكالة.',
        );

        $wasActive = $user->is_active;

        $user->update([
            ...$data,
            'email'          => isset($data['email']) ? mb_strtolower(trim($data['email'])) : $user->email,
            'system_role'    => $role,
            // الوكالة تُمسح مع الخروج من دور الشريك حتى لا يبقى نطاق قديم
            // معلّقًا على الحساب فيعود بعودة الدور
            'partner_agency' => $role === SystemRole::Partner ? $agency : null,
        ]);

        // التعطيل يُفحص عند الدخول فقط، فجلسة مفتوحة تبقى صالحة بعده.
        // إنهاؤها هنا هو ما يجعل «موقوف» تعني موقوفًا الآن لا بعد خروجه.
        if ($wasActive && ! $user->is_active) {
            $this->endSessions($user);
        }

        if ($touchesPower) {
            $this->record($request, 'account_governed', $user,
                sprintf('الدور %s، الحالة %s', $role->value, $user->is_active ? 'نشط' : 'موقوف'));
        }

        return response()->json(['data' => $this->row($user->fresh()->loadCount(['memberships', 'ownedProjects']))]);
    }

    /** تعيين كلمة مرور نيابة عن المستخدم — للحسابات التي فقدت وصولها. */
    public function setPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $user->update($request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]));

        // كلمة مرور جديدة بجلسات قديمة ما زالت مفتوحة تعني أن التغيير لم
        // يطرد أحدًا. إن كان السبب اختراقًا، فهذا هو بيت القصيد.
        $this->endSessions($user);

        $this->record($request, 'account_password_reset', $user);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * حذف حساب.
     *
     * الحذف يزيل عضويات المستخدم من المشاريع (cascade) ويترك أثره في سجل
     * التدقيق باسمه بلا رابط. لذلك هو الاستثناء لا القاعدة: من ترك الفريق
     * يُعطَّل، ولا يُحذف إلا حساب أُنشئ خطأً.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        $this->guardLastAdmin($user);

        abort_if(
            $user->ownedProjects()->exists(),
            422,
            'هذا الحساب مالك لمشاريع قائمة. عطّله بدل حذفه حتى لا تفقد المشاريع مالكها.',
        );

        $this->endSessions($user);
        $email = $user->email;
        $user->delete();

        $this->record($request, 'account_deleted', $user, $email);

        return response()->json(['data' => ['ok' => true]]);
    }

    // -----------------------------------------------------------------------

    /**
     * لا يبقى النظام بلا أدمن نشط.
     *
     * UserPolicy::govern يمنع الأدمن من حكم نفسه، فهذا الفحص نظريًا زائد
     * ما دام الفاعل أدمن نشطًا. تركه هنا لأن الشرط الذي نحميه — «أدمن واحد
     * على الأقل» — يجب أن يكون مكتوبًا في مكان واحد صريح، لا مستنتَجًا من
     * تركيب قاعدتين في ملفين.
     */
    private function guardLastAdmin(User $target): void
    {
        if ($target->system_role !== SystemRole::Admin || ! $target->is_active) {
            return;
        }

        $others = User::where('system_role', SystemRole::Admin)
            ->where('is_active', true)
            ->whereKeyNot($target->id)
            ->exists();

        abort_unless($others, 422, 'لا يمكن ترك النظام بلا أدمن نشط واحد على الأقل.');
    }

    /** إنهاء كل جلسات المستخدم المفتوحة (مخزّن الجلسات جدول — انظر config/session). */
    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }

    /**
     * أثر إداري.
     *
     * AuditLogger مربوط بمشروع، وهذه أفعال خارج أي مشروع — فتذهب للّوج.
     */
    private function record(Request $request, string $event, User $target, string $detail = ''): void
    {
        Log::info("arqam.{$event}", [
            'actor'  => $request->user()->email,
            'target' => $target->email,
            'detail' => $detail,
            'ip'     => $request->ip(),
        ]);
    }

    private function row(User $user): array
    {
        return [
            'id'                   => $user->id,
            'email'                => $user->email,
            'full_name'            => $user->full_name,
            'agency_name'          => $user->agency_name,
            'partner_agency'       => $user->partner_agency,
            'system_role'          => $user->system_role->value,
            'role_label'           => $user->system_role->label(),
            'is_active'            => $user->is_active,
            'created_at'           => $user->created_at?->toIso8601String(),
            'memberships_count'    => $user->memberships_count ?? 0,
            'owned_projects_count' => $user->owned_projects_count ?? 0,
        ];
    }
}
