<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\FeedbackRound;

/**
 * فُتحت جولة ملاحظات: النافذة مفتوحة حتى تُرسل، وبعدها تُقفل نهائيًا.
 */
class FeedbackRoundOpened extends ProjectNotification
{
    public function __construct(public FeedbackRound $round, public User $actor) {}

    public function eventKey(): string
    {
        return 'feedback.opened';
    }

    public function title(): string
    {
        return "فتحت جولة ملاحظات رقم {$this->round->round_number}";
    }

    public function body(): string
    {
        return sprintf(
            'جولة الملاحظات رقم %d مفتوحة على مشروع «%s»%s. سجّل ملاحظاتك ثم أرسلها — النافذة تُقفل بعد الإرسال.',
            $this->round->round_number,
            $this->round->project->name,
            $this->round->is_paid ? ' (جولة مدفوعة خارج الجولات المشمولة)' : '',
        );
    }

    public function project(): Project
    {
        return $this->round->project;
    }
}
