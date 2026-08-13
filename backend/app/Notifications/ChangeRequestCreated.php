<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\ChangeRequest;

/**
 * سجّل العميل طلب تغيير وينتظر التسعير.
 */
class ChangeRequestCreated extends ProjectNotification
{
    public function __construct(public ChangeRequest $changeRequest, public User $actor) {}

    public function eventKey(): string
    {
        return 'cr.created';
    }

    public function title(): string
    {
        return "طلب تغيير ينتظر التسعير: «{$this->changeRequest->title}»";
    }

    public function body(): string
    {
        return sprintf(
            'سجّل %s طلب تغيير على مشروع «%s»: %s',
            $this->actorName($this->actor),
            $this->changeRequest->project->name,
            mb_substr((string) $this->changeRequest->description, 0, 200) ?: $this->changeRequest->title,
        );
    }

    public function project(): Project
    {
        return $this->changeRequest->project;
    }
}
