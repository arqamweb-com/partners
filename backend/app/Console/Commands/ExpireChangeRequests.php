<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChangeRequestStatus;
use App\Enums\SystemRole;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Notifications\ChangeRequestExpired;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * طلب تغيير مُرسَل تجاوز مهلة القرار يُعتبر منتهيًا.
 *
 * كان في useEffect داخل ChangeRequestsTab بحارس me.isAdmin — أي أن المهلة
 * لا تنتهي إلا إن صادف أن موظفًا فتح تبويب طلبات التغيير. المهل التعاقدية
 * لا تصح كذلك.
 */
class ExpireChangeRequests extends Command
{
    protected $signature = 'arqam:expire-change-requests {--dry-run}';

    protected $description = 'إنهاء طلبات التغيير التي تجاوزت مهلة القرار';

    public function handle(AuditLogger $audit, Notifier $notifier): int
    {
        $stale = ChangeRequest::query()
            ->where('status', ChangeRequestStatus::Sent)
            ->whereNotNull('decision_deadline')
            ->whereDate('decision_deadline', '<', now()->toDateString())
            ->with('project')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('لا توجد طلبات تجاوزت المهلة.');

            return self::SUCCESS;
        }

        $system = User::where('system_role', SystemRole::Admin)->orderBy('created_at')->first();

        if (! $system) {
            $this->error('لا يوجد حساب أدمن لتسجيل الإجراء عليه.');

            return self::FAILURE;
        }

        foreach ($stale as $cr) {
            $this->line("• {$cr->project->name} — {$cr->title}");

            if ($this->option('dry-run')) {
                continue;
            }

            $cr->update(['status' => ChangeRequestStatus::Expired]);

            // الطرفان معنيّان: من سعّر ومن كان عليه القرار
            foreach ([\App\Enums\Side::Us, \App\Enums\Side::Them] as $side) {
                $notifier->toSide($cr->project, $side, new ChangeRequestExpired($cr->fresh()));
            }

            $audit->log($cr->project, $system, 'cr_expired', sprintf(
                'انتهت مهلة القرار لطلب التغيير «%s» دون رد، والطلب اعتُبر منتهيًا.',
                $cr->title,
            ), actorNameOverride: 'النظام');
        }

        $this->info('انتهى '.$stale->count().' طلبًا.');

        return self::SUCCESS;
    }
}
