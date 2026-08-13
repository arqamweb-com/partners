<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\FeedbackRound;

/**
 * أرسل العميل جولة ملاحظاته وتنتظر التصنيف.
 */
class FeedbackRoundSubmitted extends ProjectNotification
{
    public function __construct(public FeedbackRound $round, public User $actor) {}

    public function eventKey(): string
    {
        return 'feedback.submitted';
    }

    public function title(): string
    {
        return "جولة ملاحظات رقم {$this->round->round_number} في انتظار التصنيف";
    }

    public function body(): string
    {
        return sprintf(
            'أرسل %s جولة الملاحظات رقم %d بعدد %d ملاحظة على مشروع «%s».',
            $this->actorName($this->actor),
            $this->round->round_number,
            $this->round->items()->count(),
            $this->round->project->name,
        );
    }

    public function project(): Project
    {
        return $this->round->project;
    }
}
