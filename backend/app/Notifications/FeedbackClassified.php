<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\FeedbackRound;

/**
 * صُنّفت الملاحظات: لكل ملاحظة مهلة اعتراض 24 ساعة.
 */
class FeedbackClassified extends ProjectNotification
{
    public function __construct(public FeedbackRound $round, public User $actor) {}

    public function eventKey(): string
    {
        return 'feedback.classified';
    }

    public function title(): string
    {
        return "تصنيف ملاحظات الجولة رقم {$this->round->round_number}";
    }

    public function body(): string
    {
        return sprintf(
            'صنّف %s ملاحظات الجولة رقم %d في مشروع «%s». راجع التصنيف — مهلة الاعتراض 24 ساعة.',
            $this->actorName($this->actor),
            $this->round->round_number,
            $this->round->project->name,
        );
    }

    public function project(): Project
    {
        return $this->round->project;
    }
}
