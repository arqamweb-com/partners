<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Side;
use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تعديل خطة المراحل دفعة واحدة.
 *
 * كان هذا في المتصفح: حلقة حذف ثم حلقة تعديل/إضافة، بلا معاملة، وقواعد
 * «المقفولة لا تُحذف ولا تتحرّك» مفحوصة في العميل وحده — أي أن طلبًا مباشرًا
 * كان يتجاوزها. الآن القواعد في السيرفر والحفظ ذرّي.
 */
final readonly class StagePlanEditor
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<array<string,mixed>>  $plan  المراحل بالترتيب المطلوب
     */
    public function save(Project $project, User $actor, array $plan): Project
    {
        $existing = $project->stages()->where('is_parallel', false)->get()->keyBy('id');

        return DB::transaction(function () use ($project, $actor, $plan, $existing) {
            $keptIds = collect($plan)->pluck('id')->filter()->all();

            // مرحلة مقفولة لا تُحذف
            $removed = $existing->reject(fn (Stage $s) => in_array($s->id, $keptIds, true));

            if ($removed->contains(fn (Stage $s) => $s->isLocked())) {
                throw ValidationException::withMessages([
                    'stages' => 'لا يمكن حذف مرحلة مقفولة.',
                ]);
            }

            // ولا تتغيّر رتبتها
            foreach ($plan as $index => $row) {
                $current = isset($row['id']) ? $existing->get($row['id']) : null;

                if ($current?->isLocked() && $current->stage_index !== $index) {
                    throw ValidationException::withMessages([
                        'stages' => 'لا يمكن تغيير ترتيب مرحلة مقفولة.',
                    ]);
                }
            }

            $removed->each->delete();

            foreach ($plan as $index => $row) {
                $current = isset($row['id']) ? $existing->get($row['id']) : null;

                if ($current?->isLocked()) {
                    continue;   // المقفولة مرجع لا يُعدَّل
                }

                $values = [
                    'stage_index'         => $index,
                    'name'                => mb_substr(trim($row['name']), 0, 200),
                    'gate_name'           => trim((string) ($row['gate_name'] ?? '')) ?: null,
                    'gate_size'           => $row['gate_size'] ?? 'small',
                    'our_duration_days'   => max(0, (int) ($row['our_duration_days'] ?? 0)),
                    'their_duration_days' => max(0, (int) ($row['their_duration_days'] ?? 0)),
                ];

                if ($current) {
                    $current->update($values);
                } else {
                    $project->stages()->create([
                        ...$values,
                        'status'        => StageStatus::Pending,
                        'ball_in_court' => Side::Us,
                    ]);
                }
            }

            $this->audit->log($project, $actor, 'stages_edited', sprintf(
                'تعديل خطة المراحل: %d مرحلة%s.',
                count($plan),
                $removed->isNotEmpty() ? " (حُذفت {$removed->count()})" : '',
            ));

            return $project->fresh('stages');
        });
    }
}
