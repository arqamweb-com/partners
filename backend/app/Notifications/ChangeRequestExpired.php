<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\ChangeRequest;

/**
 * انتهت مهلة القرار دون رد، فاعتُبر الطلب منتهيًا.
 */
class ChangeRequestExpired extends ProjectNotification
{
    public function __construct(public ChangeRequest $changeRequest) {}

    public function eventKey(): string
    {
        return 'cr.expired';
    }

    public function title(): string
    {
        return "انتهت مهلة طلب التغيير «{$this->changeRequest->title}»";
    }

    public function body(): string
    {
        return sprintf(
            'مضت مهلة القرار على طلب التغيير «%s» في مشروع «%s» دون رد، فاعتُبر منتهيًا. يلزم إعادة تسعيره.',
            $this->changeRequest->title,
            $this->changeRequest->project->name,
        );
    }

    public function project(): Project
    {
        return $this->changeRequest->project;
    }
}
