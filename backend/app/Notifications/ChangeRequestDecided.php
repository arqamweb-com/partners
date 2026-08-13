<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\ChangeRequest;

/**
 * اعتمد الطرف الآخر طلب التغيير أو رفضه — قرار نهائي.
 */
class ChangeRequestDecided extends ProjectNotification
{
    public function __construct(public ChangeRequest $changeRequest, public User $actor, public bool $approved) {}

    public function eventKey(): string
    {
        return 'cr.decided';
    }

    public function title(): string
    {
        return ($this->approved ? 'اعتماد' : 'رفض')." طلب التغيير «{$this->changeRequest->title}»";
    }

    public function body(): string
    {
        return $this->approved
            ? sprintf('اعتمد %s الطلب كتابيًا — يبدأ العمل عليه الآن، وأثر التسليم %d يوم عمل.',
                $this->actorName($this->actor), $this->changeRequest->delivery_impact_days)
            : sprintf('رفض %s الطلب.%s', $this->actorName($this->actor),
                $this->changeRequest->decision_note ? ' '.$this->changeRequest->decision_note : '');
    }

    public function project(): Project
    {
        return $this->changeRequest->project;
    }
}
