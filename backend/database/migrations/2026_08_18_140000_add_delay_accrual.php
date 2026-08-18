<?php

/**
 * عدّاد تأخير العميل، ومواعيد المراحل القائمة.
 *
 * ═══ delay_accrued_at ═══
 *
 * client_delay_days عدّاد تراكمي: يُزاد ولا يُعاد حسابه، لأن تاريخ من كان
 * بيده الكرة ومتى لا يُحفظ في أي مكان. وعدّاد تراكمي بلا علامة على «إلى أين
 * وصلنا» يضاعف نفسه في كل تشغيل. هذا العمود هو تلك العلامة.
 *
 * ═══ ملء due_at ═══
 *
 * المراحل التي أُنشئت قبل وجود StageClock مواعيدها فارغة رغم أن مدّتها
 * مسجّلة. تُملأ هنا مرة واحدة بنفس قاعدة الخدمة — الجارية وحدها، لأن
 * موعد مرحلة لم تبدأ يُحسب حين تبدأ لا قبلها.
 */

declare(strict_types=1);

use App\Enums\StageStatus;
use App\Models\Stage;
use App\Services\StageClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('delay_accrued_at')->nullable()->after('client_delay_days');
        });

        $clock = app(StageClock::class);

        Stage::query()
            ->whereNull('due_at')
            ->whereIn('status', [StageStatus::Active, StageStatus::AwaitingApproval])
            ->each(function (Stage $stage) use ($clock) {
                $due = $clock->recompute($stage);

                if ($due !== null) {
                    $stage->updateQuietly(['due_at' => $due]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('delay_accrued_at');
        });
    }
};
