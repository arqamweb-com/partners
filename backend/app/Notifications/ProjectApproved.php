<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

/**
 * اعتُمد طلب العميل وصار مشروعًا: بدأت المراحل وبدأ العدّاد.
 */
class ProjectApproved extends ProjectNotification
{
    public function __construct(public Project $project, public User $reviewer) {}

    public function eventKey(): string
    {
        return 'project.approved';
    }

    public function title(): string
    {
        return "اتعمد مشروعك «{$this->project->name}»";
    }

    public function body(): string
    {
        return sprintf(
            'راجع %s طلبك واعتمده، وبدأ التنفيذ.%s',
            $this->actorName($this->reviewer),
            $this->project->adjusted_delivery_date
                ? ' تاريخ التسليم المبدئي '.$this->project->adjusted_delivery_date->format('Y-m-d').'.'
                : '',
        );
    }

    public function project(): Project
    {
        return $this->project;
    }
}
