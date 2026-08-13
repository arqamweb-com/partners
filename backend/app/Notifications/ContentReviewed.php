<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;
use App\Models\ContentItem;

/**
 * راجع فريق أرقام عنصر المحتوى: قبله أو رفضه بسبب.
 */
class ContentReviewed extends ProjectNotification
{
    public function __construct(public ContentItem $item, public ?User $actor, public bool $accepted) {}

    public function eventKey(): string
    {
        return 'content.reviewed';
    }

    public function title(): string
    {
        return ($this->accepted ? 'قبول' : 'رفض')." عنصر المحتوى «{$this->item->name}»";
    }

    public function body(): string
    {
        return $this->accepted
            ? sprintf('قُبل «%s» في مشروع «%s».', $this->item->name, $this->item->project->name)
            : sprintf('رُفض «%s» — %s. التأخير يُحتسب من تاريخ التقديم الأصلي.',
                $this->item->name, $this->item->rejection_reason ?: 'بلا سبب مكتوب');
    }

    public function project(): Project
    {
        return $this->item->project;
    }
}
