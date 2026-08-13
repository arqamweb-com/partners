<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * دور المستخدم في النظام — واحد لكل مستخدم.
 *
 * هذا نصف الصورة فقط. النصف الآخر هو ProjectRole: دور المستخدم داخل مشروع
 * بعينه. الصلاحية الفعلية تُحسب من الاثنين معًا في app/Services/ProjectParty.php
 *
 * السبب: النسخة السابقة كانت تسأل is_admin() فقط، فكان كل من ليس أدمن
 * «عميلًا» — ومع المدير والمشرف والشريك ينهار هذا التقسيم تمامًا.
 */
enum SystemRole: string
{
    /** فريق أرقام — صلاحية كاملة بما فيها إعدادات النظام وإدارة الحسابات. */
    case Admin = 'admin';

    /** مدير — كل المشاريع: تسعير، اعتماد، تقارير. بلا إعدادات نظام ولا حسابات. */
    case Manager = 'manager';

    /** مشرف — المشاريع المسندة إليه فقط، ينفّذ ويقدّم ولا يسعّر ولا يحذف. */
    case Supervisor = 'supervisor';

    /** وكالة شريكة — مشاريع وكالتها فقط، تتعامل كطرف مستلِم أمام أرقام. */
    case Partner = 'partner';

    /** عميل — مشاريعه هو فقط. */
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin      => 'أدمن',
            self::Manager    => 'مدير',
            self::Supervisor => 'مشرف',
            self::Partner    => 'شريك',
            self::Client     => 'عميل',
        };
    }

    /** هل ينتمي لفريق أرقام (جهة التنفيذ)؟ */
    public function isStaff(): bool
    {
        return in_array($this, [self::Admin, self::Manager, self::Supervisor], true);
    }

    /** هل يرى كل المشاريع بلا عضوية؟ المشرف لا — يرى المسند إليه فقط. */
    public function seesAllProjects(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /** التسعير وبنود العقد — قرار تجاري لا يملكه المشرف. */
    public function canPrice(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /** إعدادات النظام وإدارة الحسابات والحذف — للأدمن وحده. */
    public function isSuperUser(): bool
    {
        return $this === self::Admin;
    }

    /** الأدوار التي تُنشأ من التيرمينال فقط ولا يبلغها أحد بالتسجيل الذاتي. */
    public static function selfServiceDefault(): self
    {
        return self::Client;
    }
}
