import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Lock, Pencil, Plus, Save, X } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import type { Stage } from "@/lib/domain";
import { Num } from "@/components/Num";

type Row = {
  id: string | null; // null = مرحلة جديدة لم تُحفظ بعد
  name: string;
  gate_name: string;
  gate_size: string;
  our: number;
  their: number;
  locked: boolean;
};

/**
 * تعديل مراحل مشروع قائم — لفريق أرقام وحده.
 *
 * المرحلة المقفولة لا تُمس: قفلها اعتماد موثّق من العميل، وتغييرها بعد ذلك
 * يفرغ الاعتماد من معناه. تظهر هنا للقراءة فقط، والتعديل عليها يمر بطلب تغيير.
 */
export function StageEditor({ projectId, stages }: { projectId: string; stages: Stage[] }) {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [rows, setRows] = useState<Row[]>([]);

  const sequential = stages
    .filter((s) => !s.is_parallel)
    .sort((a, b) => a.stage_index - b.stage_index);

  function start() {
    setRows(
      sequential.map((s) => ({
        id: s.id,
        name: s.name,
        gate_name: s.gate_name ?? "",
        gate_size: s.gate_size,
        our: s.our_duration_days,
        their: s.their_duration_days,
        locked: !!s.locked_at,
      })),
    );
    setOpen(true);
  }

  const save = useMutation({
    mutationFn: async () => {
      const clean = rows.filter((r) => r.name.trim() !== "");
      if (clean.length === 0) throw new Error("المشروع لازم يكون له مرحلة واحدة على الأقل.");

      // المقفولة تحتفظ بترتيبها: تغييره يخلط سجل الاعتمادات
      const lockedMoved = clean.some(
        (r, i) => r.locked && sequential.findIndex((s) => s.id === r.id) !== i,
      );
      if (lockedMoved) throw new Error("لا يمكن تغيير ترتيب مرحلة مقفولة.");

      // الحذف والتعديل والإضافة وقواعد المقفولة كلها في السيرفر داخل
      // معاملة واحدة — كانت هنا حلقتين بلا معاملة وفحصًا يعيش في المتصفح
      await api.projects.saveStagePlan(
        projectId,
        clean.map((r) => ({
          id: r.id || null,
          name: r.name.trim().slice(0, 200),
          gate_name: r.gate_name.trim() ? r.gate_name.trim().slice(0, 200) : null,
          gate_size: r.gate_size,
          our_duration_days: Math.max(0, r.our),
          their_duration_days: Math.max(0, r.their),
        })),
      );
    },
    onSuccess: () => {
      toast.success("حُفظت خطة المراحل.");
      setOpen(false);
      qc.invalidateQueries();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر حفظ المراحل."),
  });

  if (!open) {
    return (
      <Button variant="outline" size="sm" onClick={start}>
        <Pencil className="size-4" />
        تعديل المراحل
      </Button>
    );
  }

  const update = (i: number, patch: Partial<Row>) =>
    setRows((l) => l.map((r, ri) => (ri === i ? { ...r, ...patch } : r)));

  return (
    <div className="surface-card p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-display text-lg font-semibold">تعديل خطة المراحل</h2>
        <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
          إلغاء
        </Button>
      </div>
      <p className="mt-1 text-xs leading-6 text-muted-foreground">
        المراحل المقفولة معتمدة من العميل فلا تُعدَّل ولا تُحذف — أي تغيير عليها يمر بطلب تغيير.
      </p>

      <div className="mt-4 grid gap-3">
        {rows.map((r, i) => (
          <div
            key={r.id ?? `new-${i}`}
            className={
              r.locked
                ? "rounded-xl border border-border bg-muted/40 p-3"
                : "rounded-xl border border-border p-3"
            }
          >
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <Num value={i + 1} />
              {r.locked && (
                <span className="inline-flex items-center gap-1">
                  <Lock className="size-3" /> مقفولة
                </span>
              )}
              {!r.locked && (
                <button
                  type="button"
                  aria-label={`حذف مرحلة ${r.name}`}
                  onClick={() => setRows((l) => l.filter((_, li) => li !== i))}
                  className="ms-auto text-muted-foreground hover:text-destructive"
                >
                  <X className="size-4" />
                </button>
              )}
            </div>

            <div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              <div className="grid gap-1.5 sm:col-span-2">
                <Label className="text-xs">اسم المرحلة</Label>
                <Input
                  dir="rtl"
                  maxLength={200}
                  disabled={r.locked}
                  value={r.name}
                  onChange={(e) => update(i, { name: e.target.value })}
                />
              </div>
              <div className="grid gap-1.5 sm:col-span-2">
                <Label className="text-xs">اسم البوابة</Label>
                <Input
                  maxLength={200}
                  disabled={r.locked}
                  value={r.gate_name}
                  placeholder="بدون بوابة"
                  onChange={(e) => update(i, { gate_name: e.target.value })}
                />
              </div>
              <div className="grid gap-1.5">
                <Label className="text-xs">مدة أرقام ويب للتنفيذ (يوم عمل)</Label>
                <Input
                  type="number"
                  min={0}
                  className="num"
                  disabled={r.locked}
                  value={r.our}
                  onChange={(e) => update(i, { our: Math.max(0, Number(e.target.value) || 0) })}
                />
              </div>
              <div className="grid gap-1.5">
                <Label className="text-xs">مدة العميل للمراجعة (يوم عمل)</Label>
                <Input
                  type="number"
                  min={0}
                  className="num"
                  disabled={r.locked}
                  value={r.their}
                  onChange={(e) => update(i, { their: Math.max(0, Number(e.target.value) || 0) })}
                />
              </div>
              <div className="grid gap-1.5 sm:col-span-2">
                <Label className="text-xs">حجم البوابة</Label>
                <select
                  disabled={r.locked}
                  className="h-9 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-50"
                  value={r.gate_size}
                  onChange={(e) => update(i, { gate_size: e.target.value })}
                >
                  <option value="small">صغرى — اعتماد صامت عند عدم الرد</option>
                  <option value="big">كبرى — عدم الرد يجمّد المشروع</option>
                </select>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2">
        <Button
          variant="secondary"
          onClick={() =>
            setRows((l) => [
              ...l,
              {
                id: null,
                name: "",
                gate_name: "",
                gate_size: "small",
                our: 1,
                their: 1,
                locked: false,
              },
            ])
          }
        >
          <Plus className="size-4" /> إضافة مرحلة
        </Button>
        <Button onClick={() => save.mutate()} disabled={save.isPending}>
          <Save className="size-4" /> حفظ الخطة
        </Button>
      </div>
    </div>
  );
}
