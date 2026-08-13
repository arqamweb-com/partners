<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

/**
 * سجّل عميل طلب مشروع جديد وينتظر المراجعة.
 *
 * يوجَّه بدور النظام لا بعضوية المشروع: الطلب الجديد لم يُسنَد لأحد بعد،
 * فلا يوجد فيه عضو من فريق أرقام أصلًا.
 */
class ProjectRequested extends ProjectNotification
{
    public function __construct(public Project $project, public User $requester) {}

    public function eventKey(): string
    {
        return 'project.requested';
    }

    public function title(): string
    {
        return "طلب مشروع جديد: «{$this->project->name}»";
    }

    public function body(): string
    {
        return sprintf(
            'سجّل %s طلب مشروع «%s» وينتظر المراجعة والتسعير.%s',
            $this->requester->full_name ?: $this->requester->email,
            $this->project->name,
            $this->project->scope ? ' النطاق: '.mb_substr($this->project->scope, 0, 160) : '',
        );
    }

    public function project(): \App\Models\Project
    {
        return $this->project;
    }
}
