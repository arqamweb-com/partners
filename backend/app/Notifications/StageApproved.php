<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Stage;
use App\Models\User;

class StageApproved extends ProjectNotification
{
    public function __construct(public Stage $stage, public User $actor) {}

    public function eventKey(): string
    {
        return 'stage.approved';
    }

    public function title(): string
    {
        return "اعتماد مرحلة «{$this->stage->name}»";
    }

    public function body(): string
    {
        return sprintf(
            'اعتمد %s مرحلة «%s» في مشروع «%s»، وأُقفلت نهائيًا. أي تعديل عليها يحتاج طلب تغيير.',
            $this->actor->full_name ?: $this->actor->email,
            $this->stage->name,
            $this->stage->project->name,
        );
    }

    public function project(): \App\Models\Project
    {
        return $this->stage->project;
    }
}
