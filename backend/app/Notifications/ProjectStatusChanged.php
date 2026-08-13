<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

/**
 * تغيّرت حالة المشروع (تجميد، إيقاف، إتمام) — قرار يخصّ الطرف الآخر.
 */
class ProjectStatusChanged extends ProjectNotification
{
    public function __construct(public Project $project, public User $actor, public string $label, public string $reason = '') {}

    public function eventKey(): string
    {
        return 'project.status_changed';
    }

    public function title(): string
    {
        return "مشروع «{$this->project->name}»: {$this->label}";
    }

    public function body(): string
    {
        return sprintf(
            'حوّل %s حالة المشروع إلى «%s».%s',
            $this->actorName($this->actor),
            $this->label,
            $this->reason !== '' ? ' السبب: '.$this->reason : '',
        );
    }

    public function project(): Project
    {
        return $this->project;
    }
}
