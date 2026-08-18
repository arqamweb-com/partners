<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * بذر المشروع من قالب نوعه.
 *
 * كان هذا في المتصفح (src/lib/seed-project.ts): أربع عمليات إدراج متتالية
 * بلا معاملة. لو أُغلق المتصفح في النص بقي المشروع نصف مبذور — والأسوأ أن
 * المدد والقوالب كانت تُحسب في العميل وتُرسل جاهزة.
 *
 * هنا كل شيء في معاملة واحدة، والقوالب تُقرأ في السيرفر من
 * shared/project-types.json — فلا يملك العميل التأثير على المدد.
 */
final readonly class ProjectSeeder
{
    /** المسار المتوازي يأخذ رقمًا بعيدًا حتى لا يزاحم ترتيب المراحل. */
    private const PARALLEL_INDEX = 100;

    public function __construct(
        private ProjectTypeRegistry $types,
        private BusinessDays $businessDays,
        private AuditLogger $audit,
        private Notifier $notifier,
        private StageClock $clock,
    ) {}

    /**
     * يبذر المراحل وقوائم الوصول والمحتوى، ويحوّل الطلب لمشروع معتمد.
     *
     * @param  list<array<string,mixed>>|null  $stageOverrides  مراحل عدّلها فريق أرقام في المعالج
     */
    public function seed(Project $project, User $actor, ?array $stageOverrides = null): Project
    {
        // البذر مرة واحدة فقط — إعادة المحاولة بعد فشل لا تُضاعف المراحل
        if ($project->stages()->exists()) {
            throw ValidationException::withMessages([
                'project' => 'المشروع مبذور بالفعل.',
            ]);
        }

        if (! $this->types->exists($project->project_type)) {
            throw ValidationException::withMessages([
                'project_type' => "نوع المشروع غير معروف: {$project->project_type}",
            ]);
        }

        return DB::transaction(function () use ($project, $actor, $stageOverrides) {
            $details = $project->type_details ?? [];
            $fastTrack = $project->track === 'fast_track';

            $stages = $stageOverrides ?? $this->types->stagesFor(
                $project->project_type, $details, $fastTrack,
            );

            $this->assignReviewer($project, $actor);
            $this->seedStages($project, $stages);
            $this->seedParallelAccessTrack($project);
            $this->seedAccessItems($project, $details);
            $this->seedContentItems($project, $details);
            $this->computeDeliveryDate($project, $stages);

            $project->update(['status' => ProjectStatus::Active]);

            $this->audit->log($project, $actor, 'project_seeded', sprintf(
                'اعتماد الطلب وبذر المشروع من قالب «%s»: %d مرحلة.',
                $this->types->label($project->project_type),
                count($stages),
            ));

            // صاحب الطلب ينتظر ردًا — الاعتماد هو الرد
            $this->notifier->toSide($project, Side::Them, new ProjectApproved($project->fresh(), $actor));

            return $project->fresh(['stages', 'accessItems', 'contentItems']);
        });
    }

    /**
     * من اعتمد الطلب يصير مسؤول تنفيذه.
     *
     * بدون هذا يبقى المشروع بلا عضو من فريق أرقام بعد الاعتماد، فتُصبح
     * جهة «us» فارغة ولا يصل أحدًا إشعار حين يقدّم العميل مرحلته.
     */
    private function assignReviewer(Project $project, User $actor): void
    {
        if (! $actor->isStaff()) {
            return;
        }

        $project->members()->updateOrCreate(
            ['user_id' => $actor->id],
            ['role' => ProjectRole::Lead, 'claimed_at' => now(), 'invited_email' => $actor->email],
        );
    }

    private function seedStages(Project $project, array $stages): void
    {
        $now = now();

        foreach ($stages as $i => $stage) {
            $created = $project->stages()->create([
                'stage_index'         => $i,
                'is_parallel'         => false,
                'name'                => $stage['name'],
                'gate_name'           => $stage['gate_name'] ?? $stage['gate'] ?? null,
                'gate_size'           => $stage['gate_size'] ?? 'small',
                'our_duration_days'   => (int) ($stage['our_duration_days'] ?? $stage['our'] ?? 0),
                'their_duration_days' => (int) ($stage['their_duration_days'] ?? $stage['their'] ?? 0),
                // الأولى تبدأ فورًا والكرة عند فريق أرقام
                'status'              => $i === 0 ? StageStatus::Active : StageStatus::Pending,
                'started_at'          => $i === 0 ? $now : null,
                'ball_in_court'       => Side::Us,
            ]);

            // الجارية وحدها لها موعد. البقية تأخذه حين يأتي دورها،
            // فموعد مرحلة لم تبدأ تخمين يُنقض بأول تأخير قبلها.
            if ($i === 0) {
                $created->update(['due_at' => $this->clock->dueFrom($created, Side::Us, $now)]);
            }
        }
    }

    /** الوصول والحسابات يمشي بالتوازي مع باقي المراحل، والكرة عند العميل. */
    private function seedParallelAccessTrack(Project $project): void
    {
        $now = now();

        $stage = $project->stages()->create([
            'stage_index'         => self::PARALLEL_INDEX,
            'is_parallel'         => true,
            'name'                => 'الوصول والحسابات',
            'gate_name'           => 'Access Ready',
            'gate_size'           => 'small',
            'our_duration_days'   => 0,
            'their_duration_days' => 10,
            'status'              => StageStatus::Active,
            'ball_in_court'       => Side::Them,
            'started_at'          => $now,
        ]);

        $stage->update(['due_at' => $this->clock->dueFrom($stage, Side::Them, $now)]);
    }

    private function seedAccessItems(Project $project, array $details): void
    {
        foreach ($this->types->accessFor($project->project_type, $details) as $i => $item) {
            $project->accessItems()->create([
                'item_order' => $i + 1,
                'name'       => $item['name'],
                'note'       => $item['note'] ?? null,
                'is_slow'    => (bool) ($item['slow'] ?? false),
            ]);
        }
    }

    private function seedContentItems(Project $project, array $details): void
    {
        foreach ($this->types->contentFor($project->project_type, $details) as $i => $item) {
            $project->contentItems()->create([
                'item_group'          => $item['group'],
                'item_order'          => $i + 1,
                'name'                => $item['name'],
                'acceptance_criteria' => $item['ac'] ?? null,
            ]);
        }
    }

    /**
     * تاريخ التسليم المبدئي = مجموع مدد الطرفين بأيام العمل.
     * يكتبه فريق أرقام يدويًا لاحقًا إن أراد — هذا تقدير أولي فقط.
     */
    private function computeDeliveryDate(Project $project, array $stages): void
    {
        if ($project->original_delivery_date !== null) {
            return;
        }

        $total = collect($stages)->sum(
            fn (array $s) => (int) ($s['our_duration_days'] ?? $s['our'] ?? 0)
                + (int) ($s['their_duration_days'] ?? $s['their'] ?? 0),
        );

        $delivery = $this->businessDays->add(now(), (int) $total);

        $project->update([
            'original_delivery_date' => $delivery,
            'adjusted_delivery_date' => $delivery,
        ]);
    }
}
