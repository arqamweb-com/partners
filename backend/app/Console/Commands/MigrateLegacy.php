<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectRole;
use App\Enums\SystemRole;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * ترحيل البيانات من قاعدة النسخة السابقة (arqam_flow) إلى الجديدة.
 *
 *   php artisan arqam:migrate-legacy --dry-run    # تقرير بلا كتابة
 *   php artisan arqam:migrate-legacy --fresh      # يمسح الهدف ثم يرحّل
 *
 * ═══ التحوّلات غير البديهية ═══
 *
 * 1. الهوية: كانت في ثلاثة جداول (users + profiles + user_roles) فصارت
 *    جدولًا واحدًا. الدور كان صفًا في user_roles، وصار عمودًا.
 *
 * 2. العضوية: كان الوصول «مالك المشروع أو صف في project_members»، والدعوات
 *    في جدول ثالث. الآن العضوية هي المصدر الوحيد — ولذلك **نُنشئ صف عضوية
 *    لكل مالك**. بدون ذلك يفقد كل عميل مشاريعه بعد الترحيل.
 *
 * 3. أدوار المشروع: النظام القديم يعرف أدمن/عميل فقط، فيُشتق الدور منه:
 *    الأدمن يصير مسؤول تنفيذ (lead) وغيره عميلًا (client). المدير والمشرف
 *    والشريك أدوار جديدة تُسنَد يدويًا بعد الترحيل.
 *
 * 4. الملفات: المسار كان نسبيًا لـ api/storage/uploads، وصار داخل قرص
 *    لارافيل الخاص. تُنسخ الملفات ويُعاد كتابة المسار.
 */
class MigrateLegacy extends Command
{
    protected $signature = 'arqam:migrate-legacy
        {--dry-run : تقرير بما سيحدث بلا أي كتابة}
        {--fresh : امسح قاعدة الهدف قبل الترحيل}
        {--files= : مسار api/storage/uploads القديم}
        {--skip-files : لا تنسخ الملفات}';

    protected $description = 'ترحيل البيانات من قاعدة النسخة السابقة';

    private const CHUNK = 500;

    /** الجداول بترتيب الاعتمادية — الحذف بالعكس. */
    private const TARGET_TABLES = [
        'users', 'holidays', 'app_settings', 'cr_price_items',
        'projects', 'project_members', 'stages', 'gate_approvals', 'audit_logs',
        'access_items', 'content_items', 'feedback_rounds', 'feedback_items',
        'change_requests', 'uploads',
    ];

    /** user_id => 'admin'|'client' في النظام القديم */
    private array $legacyRoles = [];

    /** "project_id:user_id" => ProjectRole — لاشتقاق الصفة في السجلات */
    private array $memberRoles = [];

    private array $counts = [];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! $this->preflight($dry)) {
            return self::FAILURE;
        }

        if ($dry) {
            $this->components->info('تشغيل جاف — لم يُكتب شيء.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function (): void {
                $this->loadLegacyRoles();
                $this->migrateUsers();
                $this->migrateSimple('holidays', ['id', 'holiday_date', 'label']);
                $this->migrateAppSettings();
                $this->migrateSimple('cr_price_items', ['id', 'name', 'price', 'currency', 'duration_days'], true);
                $this->migrateProjects();
                $this->migrateMemberships();
                $this->migrateStages();
                $this->migrateGateApprovals();
                $this->migrateAuditLog();
                $this->migrateAccessItems();
                $this->migrateContentItems();
                $this->migrateFeedback();
                $this->migrateChangeRequests();
                $this->migrateUploads();
            });
        } catch (Throwable $e) {
            $this->components->error('فشل الترحيل وتراجعت المعاملة كاملة: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------

    private function preflight(bool $dry): bool
    {
        try {
            $legacyUsers = $this->legacy('users')->count();
        } catch (Throwable $e) {
            $this->components->error('تعذّر الاتصال بالقاعدة القديمة: '.$e->getMessage());
            $this->line('اضبط LEGACY_DB_* في ملف .env');

            return false;
        }

        $this->components->info("القاعدة القديمة: {$legacyUsers} مستخدم");

        $rows = [];
        foreach ([
            'users', 'projects', 'project_members', 'project_invites', 'stages',
            'gate_approvals', 'audit_log', 'access_items', 'content_items',
            'feedback_rounds', 'feedback_items', 'change_requests', 'uploads',
            'holidays', 'cr_price_items',
        ] as $table) {
            $rows[] = [$table, $this->legacy($table)->count()];
        }
        $this->table(['الجدول', 'صفوف'], $rows);

        // مشاريع بلا مالك ولا أعضاء: لن يراها بعد الترحيل إلا الأدمن والمدير
        $orphans = $this->legacy('projects')
            ->whereNull('owner_id')
            ->whereNotIn('id', fn ($q) => $q->from('project_members')->select('project_id'))
            ->count();

        if ($orphans > 0) {
            $this->components->warn(
                "{$orphans} مشروعًا بلا مالك ولا أعضاء. بعد الترحيل لن يراها إلا الأدمن "
                .'والمدير — أضف لها أعضاء من صفحة المشروع.'
            );
        }

        // الأدوار الجديدة لا يمكن اشتقاقها من نظام بدورين
        $admins = $this->legacy('user_roles')->where('role', 'admin')->count();
        $this->components->warn(
            "{$admins} أدمن سيبقون أدمن، والباقون عملاء. المدير والمشرف والشريك "
            .'أدوار جديدة — أسندها بعد الترحيل: php artisan arqam:user role <email> --role=manager'
        );

        $existing = collect(self::TARGET_TABLES)
            ->filter(fn (string $t) => DB::table($t)->exists());

        if ($existing->isNotEmpty() && ! $dry) {
            if (! $this->option('fresh')) {
                $this->components->error(
                    'قاعدة الهدف ليست فارغة ('.$existing->implode('، ').'). '
                    .'استخدم --fresh للمسح، أو رحّل إلى قاعدة نظيفة.'
                );

                return false;
            }

            if (! $this->confirm('سيُمسح كل ما في قاعدة الهدف. متأكد؟', false)) {
                return false;
            }

            $this->truncateTarget();
        }

        return true;
    }

    private function truncateTarget(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TARGET_TABLES) as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        $this->components->info('مُسحت قاعدة الهدف.');
    }

    // -----------------------------------------------------------------------
    // الهوية
    // -----------------------------------------------------------------------

    private function loadLegacyRoles(): void
    {
        $this->legacyRoles = $this->legacy('user_roles')
            ->pluck('role', 'user_id')
            ->all();
    }

    /** users + profiles + user_roles → users */
    private function migrateUsers(): void
    {
        $this->each('users', function (Collection $rows): void {
            $profiles = $this->legacy('profiles')
                ->whereIn('id', $rows->pluck('id'))
                ->get()
                ->keyBy('id');

            $insert = $rows->map(function ($u) use ($profiles): array {
                $profile = $profiles->get($u->id);
                $isAdmin = ($this->legacyRoles[$u->id] ?? 'client') === 'admin';

                return [
                    'id'          => $u->id,
                    'email'       => $u->email,
                    // نفس صيغة bcrypt — كلمات المرور تعمل بلا إعادة ضبط
                    'password'    => $u->password_hash,
                    'full_name'   => $profile->full_name ?? '',
                    'agency_name' => $profile->agency_name ?? null,
                    'system_role' => $isAdmin ? SystemRole::Admin->value : SystemRole::Client->value,
                    // الشريك دور جديد؛ لا مقابل له في القديم
                    'partner_agency'    => null,
                    'is_active'         => 1,
                    // لم يكن هناك تفعيل بريد، والحسابات كانت تعمل بالفعل
                    'email_verified_at' => $u->created_at,
                    'created_at'        => $u->created_at,
                    'updated_at'        => $u->created_at,
                ];
            })->all();

            DB::table('users')->insert($insert);
        });
    }

    // -----------------------------------------------------------------------
    // المشاريع والعضوية
    // -----------------------------------------------------------------------

    private function migrateProjects(): void
    {
        $this->each('projects', function (Collection $rows): void {
            DB::table('projects')->insert($rows->map(fn ($p) => [
                ...(array) $p,
                'updated_at' => $p->updated_at ?? $p->created_at,
            ])->all());
        });
    }

    /**
     * العضوية: project_members + project_invites + المالكون.
     *
     * الجزء الثالث هو الأهم. في القديم كان `owner_id` وحده يكفي للوصول،
     * وفي الجديد لا وصول بلا صف عضوية — فلولا هذا لفقد كل عميل مشاريعه.
     */
    private function migrateMemberships(): void
    {
        $seen = [];   // "project:user" لمنع التكرار بين المصادر الثلاثة
        $batch = [];

        $push = function (array $row) use (&$seen, &$batch): void {
            $key = $row['project_id'].':'.($row['user_id'] ?? $row['invited_email']);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            if ($row['user_id'] !== null) {
                $this->memberRoles[$row['project_id'].':'.$row['user_id']] = $row['role'];
            }

            $batch[] = $row;
        };

        // 1) الأعضاء الصريحون
        foreach ($this->legacy('project_members')->cursor() as $m) {
            $push([
                'id'            => $m->id,
                'project_id'    => $m->project_id,
                'user_id'       => $m->user_id,
                'invited_email' => null,
                'role'          => $this->projectRoleFor($m->user_id),
                'invited_by'    => null,
                'claimed_at'    => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // 2) المالكون — الإضافة الجوهرية
        foreach ($this->legacy('projects')->whereNotNull('owner_id')->cursor() as $p) {
            $push([
                'id'            => (string) Str::uuid(),
                'project_id'    => $p->id,
                'user_id'       => $p->owner_id,
                'invited_email' => null,
                'role'          => $this->projectRoleFor($p->owner_id),
                'invited_by'    => null,
                'claimed_at'    => $p->created_at,
                'created_at'    => $p->created_at,
                'updated_at'    => $p->created_at,
            ]);
        }

        // 3) الدعوات المعلّقة — صارت صف عضوية بلا user_id
        foreach ($this->legacy('project_invites')->cursor() as $i) {
            $push([
                'id'            => $i->id,
                'project_id'    => $i->project_id,
                'user_id'       => null,
                'invited_email' => mb_strtolower($i->email),
                'role'          => ProjectRole::Client->value,
                'invited_by'    => $i->invited_by,
                'claimed_at'    => $i->claimed_at,
                'created_at'    => $i->created_at,
                'updated_at'    => $i->created_at,
            ]);
        }

        foreach (array_chunk($batch, self::CHUNK) as $chunk) {
            DB::table('project_members')->insert($chunk);
        }

        $this->counts['project_members'] = count($batch);
    }

    private function projectRoleFor(?string $userId): string
    {
        $isAdmin = ($this->legacyRoles[$userId] ?? 'client') === 'admin';

        return $isAdmin ? ProjectRole::Lead->value : ProjectRole::Client->value;
    }

    /** صفة المستخدم في مشروع — للسجلات والاعتمادات. */
    private function roleIn(?string $projectId, ?string $userId): ?string
    {
        if ($projectId === null || $userId === null) {
            return null;
        }

        return $this->memberRoles["{$projectId}:{$userId}"]
            ?? $this->projectRoleFor($userId);
    }

    // -----------------------------------------------------------------------
    // سير العمل
    // -----------------------------------------------------------------------

    private function migrateStages(): void
    {
        $this->each('stages', fn (Collection $rows) => DB::table('stages')->insert(
            $rows->map(fn ($s) => [
                ...(array) $s,
                'is_parallel' => (int) $s->is_parallel,
                'updated_at'  => $s->created_at,
            ])->all(),
        ));
    }

    private function migrateGateApprovals(): void
    {
        $this->each('gate_approvals', fn (Collection $rows) => DB::table('gate_approvals')->insert(
            $rows->map(fn ($g) => [
                ...(array) $g,
                'is_silent'     => (int) $g->is_silent,
                // بأي صفة اعتمد — عمود جديد يُشتق من عضويته
                'approver_role' => $this->roleIn($g->project_id, $g->approved_by),
            ])->all(),
        ));
    }

    /** audit_log → audit_logs (تغيّر الاسم، وأُضيف actor_role) */
    private function migrateAuditLog(): void
    {
        $this->each('audit_log', fn (Collection $rows) => DB::table('audit_logs')->insert(
            $rows->map(fn ($a) => [
                ...(array) $a,
                'actor_role' => $this->roleIn($a->project_id, $a->actor_id),
            ])->all(),
        ), target: 'audit_logs');
    }

    private function migrateAccessItems(): void
    {
        $this->each('access_items', fn (Collection $rows) => DB::table('access_items')->insert(
            $rows->map(fn ($a) => [
                ...(array) $a,
                'is_slow' => (int) $a->is_slow,
                'is_done' => (int) $a->is_done,
            ])->all(),
        ));
    }

    private function migrateContentItems(): void
    {
        $this->each('content_items', fn (Collection $rows) => DB::table('content_items')->insert(
            $rows->map(fn ($c) => [
                ...(array) $c,
                'auto_accepted' => (int) $c->auto_accepted,
            ])->all(),
        ));
    }

    private function migrateFeedback(): void
    {
        $this->each('feedback_rounds', fn (Collection $rows) => DB::table('feedback_rounds')->insert(
            $rows->map(fn ($r) => [
                ...(array) $r,
                'is_paid'    => (int) $r->is_paid,
                'updated_at' => $r->created_at,
            ])->all(),
        ));

        $this->each('feedback_items', fn (Collection $rows) => DB::table('feedback_items')
            ->insert($rows->map(fn ($i) => (array) $i)->all()));
    }

    /**
     * طلبات التغيير + ختم delivery_extended_at للمعتمَدة.
     *
     * العمود جديد وحارسه يمنع تمديد التسليم مرتين. لو تُرك فارغًا على طلب
     * معتمَد قديم، وأُعيد اعتماده بأي طريق، لتمدّد التاريخ مرة ثانية.
     */
    private function migrateChangeRequests(): void
    {
        $this->each('change_requests', fn (Collection $rows) => DB::table('change_requests')->insert(
            $rows->map(fn ($c) => [
                ...(array) $c,
                'delivery_extended_at' => ($c->status === 'approved' && (int) $c->delivery_impact_days > 0)
                    ? ($c->decided_at ?? $c->updated_at)
                    : null,
            ])->all(),
        ));
    }

    /**
     * الملفات: البيانات + نسخ الملف نفسه.
     * القديم: <جذر النظام السابق>/api/storage/uploads/<Y/m/uuid.ext>
     * الجديد: storage/app/private/uploads/<Y/m/uuid.ext>
     *
     * مجلد api/ لم يعد في المستودع (حُذف بعد اكتمال الهجرة، وهو في تاريخ
     * git لمن احتاجه)، فمصدر الملفات يُمرَّر صراحةً بـ --files. الافتراضي
     * باقٍ للنسخ المحلية التي ما زال المجلدان فيها متجاورين.
     */
    private function migrateUploads(): void
    {
        $skipFiles = (bool) $this->option('skip-files');
        $source = $this->option('files') ?: base_path('../api/storage/uploads');

        if (! $skipFiles && ! is_dir($source)) {
            $this->warn("مجلد ملفات النظام السابق غير موجود: {$source}");
            $this->warn('مرّر مساره بـ --files=/path/to/api/storage/uploads أو تخطَّ الملفات بـ --skip-files.');
        }
        $target = storage_path('app/private');

        $copied = 0;
        $missing = [];

        $this->each('uploads', function (Collection $rows) use ($source, $target, $skipFiles, &$copied, &$missing): void {
            $insert = [];

            foreach ($rows as $u) {
                $newPath = 'uploads/'.ltrim($u->stored_path, '/');

                if (! $skipFiles) {
                    $from = rtrim($source, '/').'/'.$u->stored_path;
                    $to = $target.'/'.$newPath;

                    if (is_file($from)) {
                        File::ensureDirectoryExists(dirname($to));
                        File::copy($from, $to);
                        $copied++;
                    } else {
                        $missing[] = $u->original_name;
                    }
                }

                $insert[] = [
                    ...(array) $u,
                    'stored_path' => $newPath,
                    'updated_at'  => $u->created_at,
                ];
            }

            DB::table('uploads')->insert($insert);
        });

        if ($copied > 0) {
            $this->components->info("نُسخ {$copied} ملفًا.");
        }

        if ($missing !== []) {
            $this->components->warn(
                count($missing).' ملفًا مفقودًا على القرص (السجلات رُحّلت): '
                .implode('، ', array_slice($missing, 0, 5))
                .(count($missing) > 5 ? '…' : '')
            );
        }
    }

    // -----------------------------------------------------------------------
    // أدوات
    // -----------------------------------------------------------------------

    private function legacy(string $table)
    {
        return DB::connection('legacy')->table($table);
    }

    /** يمرّ على جدول قديم على دفعات ويستدعي المعالج. */
    private function each(string $table, callable $handler, ?string $target = null): void
    {
        $total = $this->legacy($table)->count();
        $this->counts[$target ?? $table] = $total;

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setMessage($table);

        $this->legacy($table)->orderBy('id')->chunk(self::CHUNK, function (Collection $rows) use ($handler, $bar): void {
            $handler($rows);
            $bar->advance($rows->count());
        });

        $bar->finish();
        $this->newLine();
    }

    /** الجداول التي تنتقل كما هي، مع إضافة الطوابع الزمنية الناقصة. */
    private function migrateSimple(string $table, array $columns, bool $hasCreatedAt = false): void
    {
        $this->each($table, function (Collection $rows) use ($table, $columns, $hasCreatedAt): void {
            DB::table($table)->insert($rows->map(function ($row) use ($columns, $hasCreatedAt): array {
                $values = collect($columns)->mapWithKeys(fn ($c) => [$c => $row->$c])->all();
                $stamp = $hasCreatedAt ? $row->created_at : now();

                return [...$values, 'created_at' => $stamp, 'updated_at' => $stamp];
            })->all());
        });
    }

    /** app_settings: صف واحد، وليس فيه created_at في القديم. */
    private function migrateAppSettings(): void
    {
        $row = $this->legacy('app_settings')->first();
        $this->counts['app_settings'] = $row ? 1 : 0;

        if (! $row) {
            return;
        }

        DB::table('app_settings')->insert([
            ...(array) $row,
            'created_at' => $row->updated_at,
        ]);
    }

    private function report(): void
    {
        $this->newLine();
        $this->components->info('اكتمل الترحيل.');

        $rows = [];
        foreach ($this->counts as $table => $expected) {
            $actual = DB::table($table)->count();
            $rows[] = [$table, $expected, $actual, $actual === $expected ? '✓' : '⚠'];
        }
        $this->table(['الجدول', 'المصدر', 'الهدف', ''], $rows);

        $this->components->warn(
            'الخطوة التالية: أسند الأدوار الجديدة — '
            .'php artisan arqam:user role <email> --role=manager|supervisor|partner'
        );
    }
}
