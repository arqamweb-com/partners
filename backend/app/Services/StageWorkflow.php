<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\GateApproval;
use App\Models\Stage;
use App\Models\User;
use App\Notifications\StageApproved;
use App\Notifications\StageRejected;
use App\Notifications\StageSubmitted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * دورة اعتماد المراحل — في الاتجاهين.
 *
 *   صاحب الدور يقدّم  ->  awaiting_approval والكرة تنتقل للطرف الآخر
 *   الطرف الآخر يعتمد ->  locked نهائيًا وتبدأ المرحلة التالية
 *   أو يرفض بملاحظات  ->  active والكرة ترجع لصاحبها مع السبب مسجَّلًا
 *
 * الصلاحية كلها في StagePolicy — هذه الطبقة تنفّذ وتسجّل وتُشعر فقط.
 * كل انتقال داخل معاملة واحدة مع سجل التدقيق، فلا يبقى نصف انتقال.
 */
final readonly class StageWorkflow
{
    public function __construct(
        private AuditLogger $audit,
        private Notifier $notifier,
    ) {}

    /** تقديم المرحلة للطرف الآخر لمراجعتها. */
    public function submit(Stage $stage, User $actor, string $note = ''): Stage
    {
        $party = ProjectParty::for($actor, $stage->project);
        $note = Str::limit(trim($note), 4000, '');

        return DB::transaction(function () use ($stage, $actor, $party, $note) {
            $stage->update([
                'status'           => StageStatus::AwaitingApproval,
                'ball_in_court'    => $party->side()->other(),
                'submitted_at'     => Carbon::now(),
                'submitted_by'     => $actor->id,
                'submission_note'  => $note,
                'rejection_reason' => null,
            ]);

            $this->audit->log(
                $stage->project,
                $actor,
                'stage_submitted',
                sprintf(
                    'تقديم مرحلة «%s» من %s لمراجعة الطرف الآخر.%s',
                    $stage->name,
                    $party->sideLabel(),
                    $note !== '' ? ' ملاحظة: '.Str::limit($note, 300) : '',
                ),
            );

            $this->notifier->toSide($stage->project, $party->side()->other(),
                new StageSubmitted($stage->fresh(), $actor));

            return $stage->fresh();
        });
    }

    /**
     * اعتماد المرحلة وإقفالها نهائيًا، وبدء التالية.
     *
     * StagePolicy::approve() تضمن أن الحالة awaiting_approval وأن المعتمِد
     * صاحب الاعتماد على جهته — الشرطان اللذان كانا ناقصين.
     */
    public function approve(Stage $stage, User $actor, string $approverName, string $acknowledgement): Stage
    {
        $party = ProjectParty::for($actor, $stage->project);

        if (mb_strlen(trim($approverName)) < 3) {
            throw ValidationException::withMessages([
                'approver_name' => 'اكتب اسمك كاملًا للاعتماد.',
            ]);
        }

        return DB::transaction(function () use ($stage, $actor, $party, $approverName, $acknowledgement) {
            $now = Carbon::now();

            $stage->update([
                'status'    => StageStatus::Locked,
                'locked_at' => $now,
                'locked_by' => $actor->id,
            ]);

            GateApproval::create([
                'project_id'           => $stage->project_id,
                'stage_id'             => $stage->id,
                'approved_by'          => $actor->id,
                'approver_name'        => Str::limit(trim($approverName), 250, ''),
                'approver_role'        => $party->role,
                'acknowledgement_text' => Str::limit($acknowledgement, 4000, ''),
                'approved_at'          => $now,
            ]);

            // المرحلة التالية تبدأ والكرة ترجع لفريق أرقام
            if (! $stage->is_parallel) {
                Stage::query()
                    ->where('project_id', $stage->project_id)
                    ->where('stage_index', $stage->stage_index + 1)
                    ->where('is_parallel', false)
                    ->whereNull('locked_at')
                    ->update([
                        'status'        => StageStatus::Active->value,
                        'started_at'    => $now,
                        'ball_in_court' => Side::Us->value,
                    ]);
            }

            $this->audit->log(
                $stage->project,
                $actor,
                'gate_approved',
                sprintf(
                    'اعتماد وإقفال مرحلة «%s»%s من %s (%s).',
                    $stage->name,
                    $stage->gate_name ? ' — بوابة '.$stage->gate_name : '',
                    $party->sideLabel(),
                    $party->roleLabel(),
                ),
            );

            $this->notifier->toSide($stage->project, $party->side()->other(),
                new StageApproved($stage->fresh(), $actor));

            return $stage->fresh();
        });
    }

    /** رفض المرحلة وإرجاعها لصاحبها مع سبب مكتوب. */
    public function reject(Stage $stage, User $actor, string $reason): Stage
    {
        $party = ProjectParty::for($actor, $stage->project);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'سبب الرفض مطلوب ومكتوب بوضوح (5 أحرف على الأقل).',
            ]);
        }

        return DB::transaction(function () use ($stage, $actor, $party, $reason) {
            $stage->update([
                'status'           => StageStatus::Active,
                'ball_in_court'    => $party->side()->other(),
                'rejection_reason' => Str::limit($reason, 4000, ''),
                'rejected_at'      => Carbon::now(),
                'rejected_by'      => $actor->id,
                'rejection_count'  => $stage->rejection_count + 1,
                'submitted_at'     => null,
            ]);

            $this->audit->log(
                $stage->project,
                $actor,
                'stage_rejected',
                sprintf('رفض مرحلة «%s» من %s — %s',
                    $stage->name, $party->sideLabel(), Str::limit($reason, 500)),
            );

            $this->notifier->toSide($stage->project, $party->side()->other(),
                new StageRejected($stage->fresh(), $actor, $reason));

            return $stage->fresh();
        });
    }
}
