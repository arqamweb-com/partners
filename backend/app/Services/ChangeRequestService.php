<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Enums\Side;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Notifications\ChangeRequestDecided;
use App\Notifications\ChangeRequestSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * طلبات التغيير.
 *
 * الثغرة التي أُغلقت هنا: كان العميل يكتب status مباشرة، وكان أثر التمديد
 * يُطبَّق كلما صارت الحالة approved والقديمة ليست approved. فالتنقّل
 * approved -> sent -> approved كان يمدّ تاريخ التسليم مرة بعد مرة بلا حد.
 *
 * الآن ثلاثة حواجز مستقلة:
 *   1. الأفعال منفصلة: price/send فعل، و decide فعل آخر — والسياسة تفصل بينهما
 *   2. الحالة النهائية لا يُعاد فتحها (ChangeRequestStatus::isFinal)
 *   3. delivery_extended_at يُختم مرة واحدة، فحتى لو انثقب الحاجزان
 *      السابقان لا يُمدّ التاريخ مرتين
 */
final readonly class ChangeRequestService
{
    public function __construct(
        private BusinessDays $businessDays,
        private AuditLogger $audit,
        private Notifier $notifier,
    ) {}

    /** تسعير الطلب وإرساله للطرف الآخر — لمن يملك قرار التسعير. */
    public function send(ChangeRequest $cr, User $actor, array $pricing): ChangeRequest
    {
        return DB::transaction(function () use ($cr, $actor, $pricing) {
            $cr->update([
                'price'                => $pricing['price'] ?? 0,
                'currency'             => $pricing['currency'] ?? 'SAR',
                'duration_days'        => $pricing['duration_days'] ?? 0,
                'delivery_impact_days' => $pricing['delivery_impact_days'] ?? 0,
                'quote_valid_until'    => $pricing['quote_valid_until'] ?? null,
                'decision_deadline'    => $pricing['decision_deadline'] ?? null,
                'status'               => ChangeRequestStatus::Sent,
                'sent_at'              => Carbon::now(),
            ]);

            $this->audit->log($cr->project, $actor, 'cr_sent',
                sprintf('إرسال طلب التغيير «%s» مسعَّرًا بـ %s %s وأثره %d يوم عمل على التسليم.',
                    $cr->title, $cr->price, $cr->currency, $cr->delivery_impact_days));

            $this->notifier->toSide($cr->project, Side::Them, new ChangeRequestSent($cr->fresh()));

            return $cr->fresh();
        });
    }

    /**
     * اعتماد الطلب أو رفضه.
     *
     * ChangeRequestPolicy::decide() تضمن أن الطلب مُرسَل فعلًا وأن المقرِّر
     * هو صاحب الاعتماد على جهة المستلِم.
     */
    public function decide(ChangeRequest $cr, User $actor, bool $approve, string $note = ''): ChangeRequest
    {
        if ($cr->isDecided()) {
            throw ValidationException::withMessages([
                'status' => 'قرار طلب التغيير نهائي ولا يُعاد فتحه.',
            ]);
        }

        return DB::transaction(function () use ($cr, $actor, $approve, $note) {
            $cr->update([
                'status'        => $approve ? ChangeRequestStatus::Approved : ChangeRequestStatus::Rejected,
                'decided_at'    => Carbon::now(),
                'decided_by'    => $actor->id,          // من الجلسة، لا من الطلب
                'decision_note' => $note,
            ]);

            if ($approve) {
                $this->extendDelivery($cr->fresh());
            }

            $this->audit->log($cr->project, $actor,
                $approve ? 'cr_approved' : 'cr_rejected',
                sprintf('%s طلب التغيير «%s».%s',
                    $approve ? 'اعتماد' : 'رفض', $cr->title, $note ? ' ملاحظة: '.$note : ''));

            // من سعّر وأرسل ينتظر القرار
            $this->notifier->toSide($cr->project, Side::Us,
                new ChangeRequestDecided($cr->fresh(), $actor, $approve));

            return $cr->fresh();
        });
    }

    /**
     * تمديد تاريخ التسليم — مرة واحدة لكل طلب، مهما تكرر النداء.
     *
     * delivery_extended_at هو الحارس: وجوده يعني أن الأثر طُبِّق بالفعل.
     */
    private function extendDelivery(ChangeRequest $cr): void
    {
        if ($cr->delivery_extended_at !== null || $cr->delivery_impact_days <= 0) {
            return;
        }

        $project = $cr->project;
        $base = $project->adjusted_delivery_date ?? $project->original_delivery_date;

        if ($base === null) {
            return;
        }

        $project->update([
            'adjusted_delivery_date' => $this->businessDays->add($base, $cr->delivery_impact_days),
        ]);

        $cr->update(['delivery_extended_at' => Carbon::now()]);
    }

    /** إعادة حساب التسليم المعدَّل من التأخير — يقابل sync_adjusted_delivery. */
    public function syncAdjustedDelivery(\App\Models\Project $project): void
    {
        if ($project->original_delivery_date === null) {
            return;
        }

        $project->update([
            'adjusted_delivery_date' => $this->businessDays->add(
                $project->original_delivery_date,
                $project->client_delay_days,
            ),
        ]);
    }
}
