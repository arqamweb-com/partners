import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, KeyRound, Lock } from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import type { Tables } from "@/lib/db-types";
import { BALL_AR, LOCKED_MESSAGE, STAGE_STATUS_AR, type Stage } from "@/lib/domain";
import { formatDateAr } from "@/lib/business-days";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";

type AccessItem = Tables<"access_items">;

export function AccessChecklist({
  projectId,
  stage,
  onLock,
}: {
  projectId: string;
  stage: Stage | undefined;
  onLock: (stage: Stage) => void;
}) {
  const qc = useQueryClient();

  const { data: items = [] } = useQuery({
    queryKey: ["access-items", projectId],
    queryFn: () => api.access.list(projectId),
  });

  const locked = !!stage?.locked_at;
  // قائمة فارغة ليست قائمة مكتملة — بدون هذا الحارس يصبح الزر مفعّلًا
  // على قالب نوع بلا عناصر وصول

  const toggle = useMutation({
    // من علّم البند ووقت التسليم يكتبهما السيرفر من الجلسة
    mutationFn: (item: AccessItem) => api.access.toggle(item.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["access-items", projectId] }),
    onError: () => toast.error("تعذّر تحديث العنصر."),
  });

  const done = items.filter((i) => i.is_done).length;
  const pendingSlow = items.find((i) => i.is_slow && !i.is_done);

  return (
    <div className="surface-card p-6">
      <div className="flex items-center gap-2">
        <KeyRound className="size-4 text-primary" />
        <h2 className="font-display text-base font-semibold">الوصول والحسابات</h2>
        <span className="ms-auto text-xs text-muted-foreground">
          <Num value={done} /> / <Num value={items.length} />
        </span>
      </div>
      <p className="mt-2 text-xs leading-5 text-muted-foreground">
        مسار موازٍ يعمل مع كل المراحل من اليوم صفر، ويجب إقفاله قبل مرحلة الإطلاق والتسليم.
      </p>

      {stage && (
        <div className="mt-4 grid gap-2 rounded-xl border border-border bg-surface-elevated p-3 text-xs">
          <Row label="الحالة" value={STAGE_STATUS_AR[stage.status] ?? stage.status} />
          <Row label="الكرة" value={BALL_AR[stage.ball_in_court] ?? ""} />
          <Row label="موعد الاستحقاق" value={formatDateAr(stage.due_at)} />
        </div>
      )}

      {pendingSlow && (
        <p className="mt-4 flex items-start gap-2 rounded-xl border border-warn/40 bg-warn/10 p-3 text-xs leading-5 text-warn-foreground">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
          <span>
            {pendingSlow.name}: تستغرق عادة من <Num value={3} /> إلى <Num value={6} /> أسابيع. ابدأ
            فيها من اليوم الأول حتى لا تعطّل الإطلاق.
          </span>
        </p>
      )}

      <ul className="mt-4 space-y-2.5">
        {items.map((item) => (
          <li
            key={item.id}
            className={cn(
              "flex items-start gap-3 rounded-xl border border-border/70 p-3 transition-colors duration-300",
              item.is_done && "bg-surface-elevated",
              item.is_slow && !item.is_done && "border-warn/40",
            )}
          >
            <Checkbox
              className="mt-0.5"
              checked={item.is_done}
              disabled={locked || toggle.isPending}
              onCheckedChange={() => (locked ? toast.info(LOCKED_MESSAGE) : toggle.mutate(item))}
            />
            <div className="min-w-0">
              <div className="text-sm font-medium">{item.name}</div>
              <div className="mt-0.5 text-xs leading-5 text-muted-foreground">{item.note}</div>
              {item.is_done && item.provided_at && (
                <div className="mt-1 text-[11px] text-muted-foreground">
                  تم التسليم في {formatDateAr(item.provided_at)}
                </div>
              )}
            </div>
          </li>
        ))}
      </ul>

      {stage &&
        (locked ? (
          <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
            <Lock className="size-3.5" /> مقفول بتاريخ {formatDateAr(stage.locked_at)}
          </p>
        ) : (
          <Button
            variant="secondary"
            className="mt-4 w-full"
            disabled={items.length === 0 || done < items.length}
            onClick={() => onLock(stage)}
          >
            {done < items.length ? "أكمل كل العناصر أولًا" : "إقفال مسار الوصول"}
          </Button>
        ))}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}
