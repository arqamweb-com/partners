<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ProjectParty;

/**
 * السجل إضافة فقط، والفاعل يُكتب من الجلسة في AuditLogger — لا من الطلب.
 * كان أي عضو يقدر يدسّ إدخالًا منسوبًا لفريق أرقام.
 */
class AuditLogPolicy
{
    public function view(User $user, AuditLog $log): bool
    {
        return ProjectParty::for($user, $log->project)->isMember();
    }

    public function create(User $user): bool
    {
        return false;   // AuditLogger وحده يكتب
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
