import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Flame, Snowflake } from "lucide-react";
import { toast } from "sonner";
import { supabase } from "@/lib/api";
import { logAudit } from "@/lib/audit";
import { useCurrentUser } from "@/hooks/useAuth";
import { useSettings } from "@/hooks/useSettings";
import type { Project } from "@/lib/domain";
import { formatDateAr } from "@/lib/business-days";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

export function ReactivateDialog({
  project,
  open,
  onOpenChange,
}: {
  project: Project;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();
  const { data: settings } = useSettings();
  const [fee, setFee] = useState<number | null>(null);
  const [slot, setSlot] = useState("");
  const [note, setNote] = useState("");

  const feeValue = fee ?? Number(settings?.reactivation_fee ?? 0);

  const run = useMutation({
    mutationFn: async () => {
      if (!slot) throw new Error("حدّد موعد الدور الجديد.");
      const { error } = await supabase
        .from("projects")
        .update({
          status: "active",
          frozen_at: null,
          queue_slot_date: slot,
          reactivation_fee: feeValue,
          reactivated_at: new Date().toISOString(),
          original_delivery_date: slot,
          adjusted_delivery_date: slot,
        })
        .eq("id", project.id);
      if (error) throw error;
      await logAudit(
        project.id,
        "project_reactivated",
        `إعادة تنشيط المشروع برسوم ${feeValue} SAR، وموعد دور جديد ${formatDateAr(slot)}.${note.trim() ? ` ملاحظة: ${note.trim()}` : ""}`,
        me?.fullName,
      );
    },
    onSuccess: () => {
      toast.success("أُعيد تنشيط المشروع بموعد دور جديد.");
      onOpenChange(false);
      qc.invalidateQueries();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت إعادة التنشيط."),
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-lg text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="flex items-center gap-2 font-display">
            <Flame className="size-4 text-primary" /> إعادة تنشيط المشروع
          </DialogTitle>
          <DialogDescription className="leading-7">
            المشروع المجمّد يخرج من قائمة الانتظار. إعادة التنشيط تسجّل رسومًا وتمنح موعد دور جديد —
            لا يعود المشروع إلى موقعه القديم في الجدول.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-3">
          <div className="grid gap-1.5">
            <Label htmlFor="fee">رسوم إعادة التنشيط</Label>
            <Input
              id="fee"
              type="number"
              min={0}
              className="num"
              value={feeValue}
              onChange={(e) => setFee(Math.max(0, Number(e.target.value) || 0))}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="slot">موعد الدور الجديد</Label>
            <Input
              id="slot"
              type="date"
              className="num"
              value={slot}
              onChange={(e) => setSlot(e.target.value)}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="note">ملاحظة</Label>
            <Textarea
              id="note"
              dir="rtl"
              rows={2}
              maxLength={1000}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="سبب التجميد وما تم الاتفاق عليه…"
            />
          </div>
        </div>

        <p className="flex items-start gap-2 rounded-xl border border-warn/40 bg-warn/10 p-3 text-xs leading-6 text-warn-foreground">
          <Snowflake className="mt-0.5 size-3.5 shrink-0" />
          مشروع مجمّد <Num value={60} /> يومًا أو أكثر يتحوّل إلى «متوقف»، وتُسجَّل المبالغ المدفوعة
          كرصيد دائن صالح <Num value={12} /> شهرًا — لا تسقط ولا تُصادَر.
        </p>

        <DialogFooter className="gap-2 sm:justify-start">
          <Button onClick={() => run.mutate()} disabled={run.isPending}>
            تأكيد إعادة التنشيط
          </Button>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            إلغاء
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
