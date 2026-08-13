<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Stage;
use App\Models\User;

class StageSubmitted extends ProjectNotification
{
    public function __construct(public Stage $stage, public User $actor) {}

    public function eventKey(): string
    {
        return 'stage.submitted';
    }

    public function title(): string
    {
        return "مرحلة «{$this->stage->name}» في انتظار مراجعتك";
    }

    public function body(): string
    {
        return sprintf(
            'قدّم %s مرحلة «%s» في مشروع «%s» للمراجعة.%s',
            $this->actor->full_name ?: $this->actor->email,
            $this->stage->name,
            $this->stage->project->name,
            $this->stage->submission_note ? ' ملاحظة: '.$this->stage->submission_note : '',
        );
    }

    public function project(): \App\Models\Project
    {
        return $this->stage->project;
    }
}
