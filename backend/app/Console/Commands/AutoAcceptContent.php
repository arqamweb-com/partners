<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\User;
use App\Notifications\ContentReviewed;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\BusinessDays;
use Illuminate\Console\Command;

/**
 * الالتزام المتبادل: عنصر محتوى مضى على تقديمه أكثر من يوم عمل بلا مراجعة
 * يُقبل تلقائيًا لصالح العميل ويُسجَّل.
 *
 * كان هذا في المتصفح داخل useEffect في ContentChecklist — أي أنه لا يعمل
 * إلا إذا صادف أن أدمن فاتح صفحة المشروع. الالتزام التعاقدي لا يصح أن
 * يتوقف على من فتح متصفحه.
 *
 * يُجدوَل في routes/console.php ليعمل كل ساعة.
 */
class AutoAcceptContent extends Command
{
    protected $signature = 'arqam:auto-accept {--dry-run : اعرض بلا تنفيذ}';

    protected $description = 'قبول عناصر المحتوى التي تجاوزت مهلة المراجعة';

    public function handle(BusinessDays $businessDays, AuditLogger $audit, Notifier $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $overdue = ContentItem::query()
            ->where('status', ContentStatus::Submitted)
            ->whereNotNull('submitted_at')
            // بنود مشروع مؤرشف خارج الحساب: النطاق العام على المشروع
            // يجعل علاقته null، ولا معنى لقبول تلقائي على مشروع مخفي
            ->whereHas('project')
            ->with('project')
            ->get()
            ->filter(fn (ContentItem $item) => $businessDays
                ->add($item->submitted_at, 1)
                ->isPast());

        if ($overdue->isEmpty()) {
            $this->info('لا توجد عناصر تجاوزت المهلة.');

            return self::SUCCESS;
        }

        // الفاعل حساب النظام — الفعل ليس لأحد بعينه
        $system = User::where('system_role', \App\Enums\SystemRole::Admin)
            ->orderBy('created_at')
            ->first();

        if (! $system) {
            $this->error('لا يوجد حساب أدمن لتسجيل الإجراء عليه.');

            return self::FAILURE;
        }

        foreach ($overdue as $item) {
            $this->line("• {$item->project->name} — {$item->name}");

            if ($dryRun) {
                continue;
            }

            $item->update([
                'status'        => ContentStatus::Accepted,
                'auto_accepted' => true,
                'reviewed_at'   => now(),
            ]);

            // الطرفان يعرفان: العميل أن محتواه قُبل، والفريق أن المهلة فاتت
            foreach ([\App\Enums\Side::Us, \App\Enums\Side::Them] as $side) {
                $notifier->toSide($item->project, $side,
                    new ContentReviewed($item->fresh(), null, true));
            }

            $audit->log($item->project, $system, 'content_auto_accepted',
                sprintf('قبول تلقائي لعنصر المحتوى «%s» بعد تجاوز فريق أرقام مهلة المراجعة (يوم عمل واحد).',
                    $item->name),
                actorNameOverride: 'النظام');
        }

        $this->info(($dryRun ? 'كان سيُقبل ' : 'تم قبول ').$overdue->count().' عنصرًا.');

        return self::SUCCESS;
    }
}
