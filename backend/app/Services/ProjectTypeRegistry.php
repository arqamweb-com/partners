<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * سجل أنواع المشاريع وقوالبها.
 *
 * المصدر ملف JSON واحد يقرأه الطرفان (shared/project-types.json)، فلا
 * تتفرّع نسخة في الفرونت وأخرى في الباك. في النسخة السابقة كان القالب في
 * TypeScript وحده، والمتصفح هو من يحسب المراحل ويرسلها — أي أن العميل
 * كان يقرّر بيانات تعاقدية. هنا الحساب في السيرفر.
 *
 * أثر حقول التفاصيل على المشروع (نفس منطق project-types.ts):
 *   content : عناصر تُضاف لقائمة المحتوى عند تفعيل الحقل
 *   access  : عناصر تُضاف لقائمة الوصول
 *   days    : أيام تُضاف لمرحلة بعينها (ثابتة أو محسوبة بالكمية)
 */
final class ProjectTypeRegistry
{
    private const CACHE_KEY = 'project-types';

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $path = resource_path('project-types.json');

            if (! is_file($path)) {
                throw new RuntimeException("قوالب أنواع المشاريع غير موجودة: {$path}");
            }

            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            return collect($data['types'])->keyBy('id')->all();
        });
    }

    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function exists(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->all()[$id]
            ?? throw new RuntimeException("نوع مشروع غير معروف: {$id}");
    }

    public function label(string $id): string
    {
        return $this->all()[$id]['label'] ?? $id;
    }

    // -----------------------------------------------------------------------
    // احتساب القوالب بعد تطبيق تفاصيل النوع
    // -----------------------------------------------------------------------

    /**
     * المراحل بعد تعديل المدد حسب التفاصيل.
     *
     * @param  array<string, mixed>  $details
     * @return list<array<string, mixed>>
     */
    public function stagesFor(string $typeId, array $details = [], bool $fastTrack = false): array
    {
        $type = $this->get($typeId);
        $extraDays = $this->extraDaysByStage($type, $details);
        $factor = $fastTrack ? 0.6 : 1.0;

        return collect($type['stages'])
            ->map(function (array $stage) use ($extraDays, $factor): array {
                $extra = $extraDays[$stage['name']] ?? 0;

                return [
                    'name'                => $stage['name'],
                    'gate_name'           => $stage['gate'] ?? null,
                    'gate_size'           => $stage['gate_size'] ?? 'small',
                    'our_duration_days'   => (int) ceil(($stage['our'] + $extra) * $factor),
                    'their_duration_days' => (int) ceil($stage['their'] * $factor),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function accessFor(string $typeId, array $details = []): array
    {
        $type = $this->get($typeId);
        $items = collect($type['accessItems']);

        foreach ($this->activeFields($type, $details) as $field) {
            $items = $items->concat($field['access'] ?? []);
        }

        return $items->unique('name')->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function contentFor(string $typeId, array $details = []): array
    {
        $type = $this->get($typeId);
        $items = collect($type['contentItems']);

        foreach ($this->activeFields($type, $details) as $field) {
            $items = $items->concat($field['content'] ?? []);
        }

        return $items->unique('name')->values()->all();
    }

    /** القيم الافتراضية لحقول النوع. */
    public function defaultDetails(string $typeId): array
    {
        return collect($this->get($typeId)['detailFields'])
            ->mapWithKeys(fn (array $f) => [$f['key'] => $f['value'] ?? $this->blankFor($f)])
            ->all();
    }

    // -----------------------------------------------------------------------

    /**
     * الحقول «المفعّلة» — نقل حرفي لـ isActive() في project-types.ts
     *
     * مهم: القيمة تُقرأ من التفاصيل المحفوظة وحدها ولا تعود لقيمة الحقل
     * الافتراضية. الرجوع للافتراضي كان يجعل حقولًا تُحسب مفعّلة على مشروع
     * لم تُضبط تفاصيله بعد، فتُضاف عناصر محتوى ووصول لم يطلبها أحد.
     *
     * @return list<array<string, mixed>>
     */
    private function activeFields(array $type, array $details): array
    {
        return collect($type['detailFields'])
            ->filter(fn (array $field) => $this->isActive($field, $details[$field['key']] ?? null))
            ->values()
            ->all();
    }

    private function isActive(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'boolean' => $value === true,
            'number'  => (float) $value > (float) ($field['activeAbove'] ?? 0),
            default   => is_string($value) && trim($value) !== '',
        };
    }

    /**
     * الأيام الإضافية لكل مرحلة — نقل حرفي لحلقة stagesForType().
     *
     * @return array<string, float>
     */
    private function extraDaysByStage(array $type, array $details): array
    {
        $extra = [];

        foreach ($type['detailFields'] as $field) {
            $days = $field['days'] ?? null;
            $value = $details[$field['key']] ?? null;

            if (! $days || ! $this->isActive($field, $value)) {
                continue;
            }

            // onlyIf: أثر القائمة لا يُطبَّق إلا عند قيمة بعينها
            if (isset($days['onlyIf']) && $value !== $days['onlyIf']) {
                continue;
            }

            $stage = $days['stage'];
            $extra[$stage] ??= 0;

            if (! empty($days['fixed'])) {
                $extra[$stage] += (float) $days['fixed'];
            }

            if (! empty($days['per']) && ! empty($days['unit'])) {
                // countFrom: الكمية من حقل مجاور — يسمح لاختيار أن يُسعَّر برقم
                $count = (float) ($details[$days['countFrom'] ?? $field['key']] ?? 0);

                // الوحدة الأولى داخلة في التقدير الأساسي، فنحسب ما زاد عنها
                $units = max(0, (int) ceil($count / (float) $days['unit']) - 1);
                $extra[$stage] += $units * (float) $days['per'];
            }
        }

        return $extra;
    }

    private function blankFor(array $field): mixed
    {
        return match ($field['type']) {
            'boolean' => false,
            'number'  => 0,
            'select'  => $field['options'][0] ?? '',
            default   => '',
        };
    }
}
