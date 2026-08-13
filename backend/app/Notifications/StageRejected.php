<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Stage;
use App\Models\User;

class StageRejected extends ProjectNotification
{
    public function __construct(public Stage $stage, public User $actor, public string $reason) {}

    public function eventKey(): string
    {
        return 'stage.rejected';
    }

    public function title(): string
    {
        return "مرحلة «{$this->stage->name}» رجعت لك بملاحظات";
    }

    public function body(): string
    {
        return sprintf(
            'رفض %s مرحلة «%s» في مشروع «%s». السبب: %s',
            $this->actor->full_name ?: $this->actor->email,
            $this->stage->name,
            $this->stage->project->name,
            $this->reason,
        );
    }

    public function project(): \App\Models\Project
    {
        return $this->stage->project;
    }
}
