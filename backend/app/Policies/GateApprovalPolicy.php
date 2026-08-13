<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GateApproval;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * سجل الاعتماد دليل تعاقدي: يقرأه العضو، ولا ينشئه إلا StageWorkflow،
 * ولا يعدّله أو يحذفه أحد إطلاقًا — ولا حتى الأدمن.
 */
class GateApprovalPolicy
{
    public function view(User $user, GateApproval $approval): bool
    {
        return ProjectParty::for($user, $approval->stage->project)->isMember();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, GateApproval $approval): bool
    {
        return false;
    }

    public function delete(User $user, GateApproval $approval): bool
    {
        return false;
    }
}
