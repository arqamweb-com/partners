<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Enums\SystemRole;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessDays;
use App\Services\ChangeRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * تراكم أيام تأخير العميل.
 *
 * ═══ ما الذي كان ناقصًا ═══
 *
 * client_delay_days هو ما يحرّك تاريخ التسليم المعدّل — وكان يُكتب باليد
 * من بنود المشروع. أي أن الالتزام التعاقدي الوحيد الذي يتحرّك بالوقت كان
 * يتحرّك بأن يتذكّر موظف أن يزيده.
 *
 * ═══ ما يُحتسب ═══
 *
 * يوم عمل واحد عن كل يوم مضى والكرة عند العميل بعد موعد الاستحقاق.
 * ولاحظ: يوم واحد عن اليوم مهما تعدّدت المراحل المتأخرة عنده — التأخير
 * زمن لا قائمة مهام، ومشروع فيه مرحلتان متأخرتان لم يتأخر يومين في اليوم.
 *
 * ═══ لماذا لا يُعاد الحساب من الصفر ═══
 *
 * لأن «متى انتقلت الكرة» لا يُحفظ. فالعدّاد تراكمي، وعلامته
 * projects.delay_accrued_at حتى لا يحتسب تشغيلان في يوم واحد يومين.
 */
class AccrueClientDelay extends Command
{
    protected $signature = 'arqam:accrue-client-delay {--dry-run}';

    protected $description = 'زيادة أيام تأخير العميل عن كل يوم عمل تجاوز موعد الاستحقاق';

    public function handle(
        BusinessDays $businessDays,
        AuditLogger $audit,
        ChangeRequestService $changeRequests,
    ): int {
        $now = CarbonImmutable::now();

        // المجمّد والمتوقف خارج العدّ: عدّاده توقّف يوم تجمّد
        $projects = Project::query()
            ->whereIn('status', [ProjectStatus::Active, ProjectStatus::AwaitingClient])
            ->get();

        if ($projects->isEmpty()) {
            $this->info('لا توجد مشاريع نشطة.');

            return self::SUCCESS;
        }

        $system = User::where('system_role', SystemRole::Admin)->orderBy('created_at')->first();

        if (! $system) {
            $this->error('لا يوجد حساب أدمن لتسجيل الإجراء عليه.');

            return self::FAILURE;
        }

        $touched = 0;

        foreach ($projects as $project) {
            $overdue = $project->stages()
                ->whereIn('status', [StageStatus::Active, StageStatus::AwaitingApproval])
                ->where('ball_in_court', Side::Them)
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now)
                ->orderBy('due_at')
                ->first();

            if ($overdue === null) {
                // لا تأخير اليوم: تُحرَّك العلامة حتى لا يُحتسب ما مضى
                // حين يتأخر لاحقًا
                $this->option('dry-run') || $project->update(['delay_accrued_at' => $now]);

                continue;
            }

            // من موعد الاستحقاق، أو من آخر يوم احتُسب — أيّهما أحدث
            $from = $this->startOfCount($project->delay_accrued_at, $overdue);
            $days = $businessDays->between($from, $now);

            if ($days < 1) {
                continue;
            }

            $touched++;
            $this->line(sprintf(
                '• %s — «%s» متأخرة، +%d يوم (الإجمالي %d)',
                $project->name,
                $overdue->name,
                $days,
                $project->client_delay_days + $days,
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $project->increment('client_delay_days', $days);
            $project->update(['delay_accrued_at' => $now]);

            // التسليم المعدّل مشتقّ من العدّاد، فلا يُترك خلفه
            $changeRequests->syncAdjustedDelivery($project->fresh());

            $audit->log($project, $system, 'client_delay_accrued', sprintf(
                'تأخر العميل %d يوم عمل على مرحلة «%s» بعد موعد استحقاقها، والإجمالي صار %d يوم.',
                $days,
                $overdue->name,
                $project->fresh()->client_delay_days,
            ), actorNameOverride: 'النظام');
        }

        $this->info($touched === 0
            ? 'لا مشروع تأخر عليه العميل اليوم.'
            : "احتُسب التأخير على {$touched} مشروع.");

        return self::SUCCESS;
    }

    /** أحدث اللحظتين: موعد الاستحقاق، أو آخر يوم احتُسب فعلًا. */
    private function startOfCount(?\Illuminate\Support\Carbon $accruedAt, Stage $overdue): CarbonImmutable
    {
        $due = CarbonImmutable::parse($overdue->due_at);

        if ($accruedAt === null) {
            return $due;
        }

        $accrued = CarbonImmutable::parse($accruedAt);

        return $accrued->greaterThan($due) ? $accrued : $due;
    }
}
