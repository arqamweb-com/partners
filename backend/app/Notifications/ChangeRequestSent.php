<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChangeRequest;

class ChangeRequestSent extends ProjectNotification
{
    public function __construct(public ChangeRequest $changeRequest) {}

    public function eventKey(): string
    {
        return 'cr.sent';
    }

    public function title(): string
    {
        return "طلب تغيير للاعتماد: «{$this->changeRequest->title}»";
    }

    public function body(): string
    {
        return sprintf(
            'وصلك طلب تغيير مسعَّر في مشروع «%s»: %s %s، وأثره على التسليم %d يوم عمل.%s',
            $this->changeRequest->project->name,
            $this->changeRequest->price,
            $this->changeRequest->currency,
            $this->changeRequest->delivery_impact_days,
            $this->changeRequest->decision_deadline
                ? ' مهلة القرار حتى '.$this->changeRequest->decision_deadline->format('Y-m-d').'.'
                : '',
        );
    }

    public function project(): \App\Models\Project
    {
        return $this->changeRequest->project;
    }
}
