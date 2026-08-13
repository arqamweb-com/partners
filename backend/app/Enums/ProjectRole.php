<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * دور المستخدم داخل مشروع بعينه — مخزَّن في project_members.role.
 *
 * نفس المستخدم قد يكون Lead في مشروع و Viewer في آخر. هذا ما يجعل إسناد
 * المشاريع للمشرفين ممكنًا بلا منحهم النظام كله.
 */
enum ProjectRole: string
{
    /** مسؤول التنفيذ من أرقام — يقدّم المراحل ويردّ على الملاحظات. */
    case Lead = 'lead';

    /** منفّذ من أرقام — يشارك في العمل بلا سلطة تقديم المراحل. */
    case Contributor = 'contributor';

    /** الوكالة الشريكة على هذا المشروع — تستلم وتعتمد نيابة عن عميلها. */
    case Partner = 'partner';

    /** العميل صاحب المشروع. */
    case Client = 'client';

    /** اطلاع فقط — لا يكتب شيئًا. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Lead        => 'مسؤول التنفيذ',
            self::Contributor => 'منفّذ',
            self::Partner     => 'شريك',
            self::Client      => 'عميل',
            self::Viewer      => 'مطّلع',
        };
    }

    /**
     * جهة هذا الدور في دورة الاعتماد.
     *
     * ball_in_court في جدول stages ما زال us/them — وهو الصحيح: الدورة
     * دائمًا بين جهة تُنفّذ وجهة تستلم. الجديد أن الجهة تُشتق من دور
     * المستخدم في هذا المشروع تحديدًا، لا من كونه أدمن أو لا.
     */
    public function side(): ?Side
    {
        return match ($this) {
            self::Lead, self::Contributor => Side::Us,
            self::Partner, self::Client   => Side::Them,
            self::Viewer                  => null,   // لا يتصرّف
        };
    }

    /** هل يملك تحريك المراحل (تقديم / اعتماد / رفض)؟ */
    public function canActOnStages(): bool
    {
        return in_array($this, [self::Lead, self::Partner, self::Client], true);
    }

    /**
     * أولوية الاعتماد على جهة المستلِم.
     *
     * ⚠️ قرار عمل قابل للتغيير: حين يكون على المشروع شريك وعميل معًا،
     * الشريك هو صاحب الاعتماد (هو المتعاقد مع أرقام، والعميل عميله هو).
     * لو أردت العكس — أن يعتمد العميل النهائي — اقلب الرقمين هنا وحدهما،
     * فلا شيء آخر في النظام يفترض هذا الترتيب.
     */
    public function approvalRank(): int
    {
        return match ($this) {
            self::Partner => 2,
            self::Client  => 1,
            default       => 0,
        };
    }
}
