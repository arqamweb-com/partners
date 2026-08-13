<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Enums\ProjectRole;

/**
 * أُضيف المستخدم لمشروع — يعرف أن له وصولًا وبأي صفة.
 */
class AddedToProject extends ProjectNotification
{
    public function __construct(public Project $project, public User $inviter, public ProjectRole $role) {}

    public function eventKey(): string
    {
        return 'project.member_added';
    }

    public function title(): string
    {
        return "اتضفت لمشروع «{$this->project->name}»";
    }

    public function body(): string
    {
        return sprintf(
            'أضافك %s لمشروع «%s» بصفة %s.',
            $this->actorName($this->inviter),
            $this->project->name,
            $this->role->label(),
        );
    }

    public function project(): Project
    {
        return $this->project;
    }
}
