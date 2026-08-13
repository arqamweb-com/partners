<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * كتابة سجل التدقيق.
 *
 * نقطة الكتابة الوحيدة، والفاعل يؤخذ من الجلسة دائمًا. في النسخة السابقة
 * كان المتصفح يرسل actor_id و actor_name مع كل إدخال، فأمكن نسب أي حدث
 * لفريق أرقام. هنا لا يوجد مسار يقبل فاعلًا من الطلب أصلًا.
 */
final class AuditLogger
{
    public function log(
        Project $project,
        User $actor,
        string $eventType,
        string $description,
        ?string $actorNameOverride = null,
    ): AuditLog {
        $party = ProjectParty::for($actor, $project);

        return AuditLog::create([
            'id'          => (string) Str::uuid(),
            'project_id'  => $project->id,
            'actor_id'    => $actor->id,
            // الاسم البديل («النظام») للإجراءات التلقائية، ومن الموظفين فقط
            'actor_name'  => ($actorNameOverride && $actor->isStaff())
                ? Str::limit($actorNameOverride, 250, '')
                : ($actor->full_name ?: $actor->email),
            'actor_role'  => $party->role,
            'event_type'  => Str::limit(trim($eventType), 64, ''),
            'description' => $description,
            'created_at'  => Carbon::now(),
        ]);
    }
}
