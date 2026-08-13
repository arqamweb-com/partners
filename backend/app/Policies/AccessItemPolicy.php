<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessItem;
use App\Models\User;
use App\Services\ProjectParty;

class AccessItemPolicy
{
    public function view(User $user, AccessItem $item): bool
    {
        return ProjectParty::for($user, $item->project)->isMember();
    }

    /**
     * التعليم بالتسليم متاح لأي عضو فاعل — لكن provided_by و provided_at
     * يكتبهما السيرفر من الجلسة، فلا يُنسب التسليم لغير من قام به.
     */
    public function toggleDone(User $user, AccessItem $item): bool
    {
        return ProjectParty::for($user, $item->project)->canActOnStages();
    }

    public function manage(User $user, AccessItem $item): bool
    {
        return $user->isStaff() && ProjectParty::for($user, $item->project)->isMember();
    }
}
