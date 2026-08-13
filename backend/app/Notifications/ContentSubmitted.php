<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\ContentItem;

/**
 * قدّم العميل عنصر محتوى وينتظر المراجعة (مهلتها يوم عمل).
 */
class ContentSubmitted extends ProjectNotification
{
    public function __construct(public ContentItem $item, public User $actor) {}

    public function eventKey(): string
    {
        return 'content.submitted';
    }

    public function title(): string
    {
        return "محتوى في انتظار مراجعتك: «{$this->item->name}»";
    }

    public function body(): string
    {
        return sprintf(
            'قدّم %s «%s» في مشروع «%s». مهلة المراجعة يوم عمل واحد، وبعدها يُقبل تلقائيًا.',
            $this->actorName($this->actor),
            $this->item->name,
            $this->item->project->name,
        );
    }

    public function project(): Project
    {
        return $this->item->project;
    }
}
