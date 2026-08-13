<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * إدارة الحسابات من التيرمينال — بديل api/bin/admin.php.
 *
 * لا يوجد أي طريق من الواجهة لصنع حساب غير «عميل». الأدوار الأخرى
 * (أدمن، مدير، مشرف، شريك) تُنشأ من هنا وحدها.
 *
 *   php artisan arqam:user create you@site.com --role=manager --name="اسمك"
 *   php artisan arqam:user role  you@site.com --role=supervisor
 *   php artisan arqam:user passwd you@site.com
 *   php artisan arqam:user list  --role=admin
 */
class ManageUser extends Command
{
    protected $signature = 'arqam:user
        {action : create|role|passwd|list|disable|enable}
        {email? : بريد المستخدم}
        {--role= : admin|manager|supervisor|partner|client}
        {--name= : الاسم الكامل}
        {--agency= : اسم الوكالة (للشريك)}';

    protected $description = 'إدارة حسابات النظام وأدوارها';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'create'  => $this->create(),
            'role'    => $this->changeRole(),
            'passwd'  => $this->changePassword(),
            'list'    => $this->list(),
            'disable' => $this->setActive(false),
            'enable'  => $this->setActive(true),
            default   => $this->bail('إجراء غير معروف. المتاح: create|role|passwd|list|disable|enable'),
        };
    }

    private function create(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if ($email === '') {
            return $this->bail('البريد مطلوب.');
        }
        if (User::where('email', $email)->exists()) {
            return $this->bail("البريد $email مسجَّل من قبل.");
        }

        $role = $this->resolveRole();
        // كلمة المرور تُطلب أثناء التشغيل ولا تُكتب في الأمر — حتى لا تتسجّل في history
        $secret = password('كلمة المرور', required: true, validate: fn ($v) => mb_strlen($v) >= 8
            ? null
            : 'كلمة المرور لازم تكون 8 أحرف على الأقل.');

        $user = User::create([
            'email'          => $email,
            'password'       => $secret,
            'full_name'      => $this->option('name') ?: $email,
            'system_role'    => $role,
            'partner_agency' => $role === SystemRole::Partner ? $this->option('agency') : null,
            'agency_name'    => $this->option('agency'),
        ]);

        $this->info("تم إنشاء «{$user->full_name}» بدور {$role->label()}.");

        if ($role === SystemRole::Partner && ! $user->partner_agency) {
            $this->warn('تنبيه: الشريك بلا اسم وكالة لن يرى مشاريع وكالته. استخدم --agency');
        }

        return self::SUCCESS;
    }

    private function changeRole(): int
    {
        $user = $this->findUser();
        if (! $user) {
            return self::FAILURE;
        }

        $role = $this->resolveRole();
        $was = $user->system_role->label();
        $user->update(['system_role' => $role]);

        $this->info("{$user->email}: {$was} ← {$role->label()}");

        if (! $role->seesAllProjects()) {
            $this->warn('ملاحظة: هذا الدور يرى المشاريع المسندة إليه فقط — أضفه لأعضاء المشاريع.');
        }

        return self::SUCCESS;
    }

    private function changePassword(): int
    {
        $user = $this->findUser();
        if (! $user) {
            return self::FAILURE;
        }

        $user->update([
            'password' => password('كلمة المرور الجديدة', required: true,
                validate: fn ($v) => mb_strlen($v) >= 8 ? null : 'ثمانية أحرف على الأقل.'),
        ]);

        $this->info("تم تغيير كلمة مرور {$user->email}.");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $query = User::query()->orderBy('system_role')->orderBy('email');

        if ($role = $this->option('role')) {
            $query->where('system_role', $role);
        }

        $rows = $query->get()->map(fn (User $u) => [
            $u->email,
            $u->full_name,
            $u->system_role->label(),
            $u->partner_agency ?: '—',
            $u->is_active ? 'نشط' : 'موقوف',
        ]);

        $this->table(['البريد', 'الاسم', 'الدور', 'الوكالة', 'الحالة'], $rows);

        return self::SUCCESS;
    }

    private function setActive(bool $active): int
    {
        $user = $this->findUser();
        if (! $user) {
            return self::FAILURE;
        }

        $user->update(['is_active' => $active]);
        $this->info("{$user->email}: ".($active ? 'نشط' : 'موقوف'));

        return self::SUCCESS;
    }

    private function findUser(): ?User
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if (! $user) {
            $this->error('المستخدم غير موجود.');
        }

        return $user;
    }

    private function resolveRole(): SystemRole
    {
        $given = $this->option('role');

        if ($given && $role = SystemRole::tryFrom($given)) {
            return $role;
        }

        $choice = select('الدور', collect(SystemRole::cases())
            ->mapWithKeys(fn (SystemRole $r) => [$r->value => $r->label()])
            ->all());

        return SystemRole::from($choice);
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
