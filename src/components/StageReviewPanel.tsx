import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Check, Lock, Send, X } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { supabase } from "@/lib/api";
import type { Stage } from "@/lib/domain";

/**
 * لوحة إجراءات المرحلة الحالية.
 *
 * الكرة في ملعبك  ->  تقدّم المرحلة للطرف الآخر
 * الكرة في ملعبك وهي مقدَّمة إليك  ->  تعتمد أو ترفض بملاحظات
 *
 * الطرفان يستخدمان نفس اللوحة: فريق أرقام يرسل التصميم فيراجعه العميل،
 * والعميل يجهّز مرحلته فيراجعها فريق أرقام.
 */
export function StageReviewPanel({
  stage,
  isAdmin,
  onApprove,
}: {
  stage: Stage;
  isAdmin: boolean;
  onApprove: (stage: Stage) => void;
}) {
  const [note, setNote] = useState("");
  const [reason, setReason] = useState("");
  const [rejecting, setRejecting] = useState(false);
  const queryClient = useQueryClient();

  const mySide = isAdmin ? "us" : "them";
  const theirLabel = isAdmin ? "العميل" : "فريق أرقام";
  const myTurn = stage.ball_in_court === mySide;
  const awaitingMyReview = myTurn && stage.status === "awaiting_approval";
  const canSubmit = myTurn && stage.status !== "awaiting_approval" && !stage.locked_at;

  const submit = useMutation({
    mutationFn: () => supabase.stages.submit(stage.id, note),
    onSuccess: () => {
      toast.success(`تم تقديم المرحلة لمراجعة ${theirLabel}.`);
      setNote("");
      queryClient.invalidateQueries();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر التقديم."),
  });

  const reject = useMutation({
    mutationFn: () => supabase.stages.reject(stage.id, reason),
    onSuccess: () => {
      toast.success("تم تسجيل الرفض وإرجاع المرحلة مع ملاحظاتك.");
      setReason("");
      setRejecting(false);
      queryClient.invalidateQueries();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر تسجيل الرفض."),
  });

  if (stage.locked_at) {
    return null;
  }

  return (
    <div className="mt-5 space-y-4">
      {/* سبب رفض سابق — يظهر لصاحب المرحلة ليعرف المطلوب تعديله */}
      {stage.rejection_reason && stage.status === "active" && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <div className="flex items-center gap-2 text-destructive">
            <X className="size-4" />
            <span className="text-sm font-semibold">
              مرفوضة — التعديل رقم {stage.rejection_count}
            </span>
          </div>
          <p className="mt-2 text-sm leading-7 whitespace-pre-line">{stage.rejection_reason}</p>
        </div>
      )}

      {/* ملاحظة المُقدِّم — يراها المراجع */}
      {awaitingMyReview && stage.submission_note && (
        <div className="rounded-xl border border-border bg-surface-elevated p-4">
          <div className="text-xs text-muted-foreground">ملاحظة من {theirLabel}</div>
          <p className="mt-1 text-sm leading-7 whitespace-pre-line">{stage.submission_note}</p>
        </div>
      )}

      {/* تقديم المرحلة */}
      {canSubmit && (
        <div className="space-y-2">
          <Label htmlFor="submit-note">
            {isAdmin ? "أرسل المخرجات للعميل" : "قدّم المرحلة لمراجعة فريق أرقام"}
          </Label>
          <Textarea
            id="submit-note"
            rows={3}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={
              isAdmin
                ? "رابط التصميم أو المخرجات، وأي ملاحظة للعميل…"
                : "وصف ما جهّزته، أو رابط الملفات…"
            }
          />
          <Button onClick={() => submit.mutate()} disabled={submit.isPending}>
            <Send className="size-4" />
            تقديم لمراجعة {theirLabel}
          </Button>
        </div>
      )}

      {/* مراجعة: اعتماد أو رفض */}
      {awaitingMyReview && !rejecting && (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => onApprove(stage)}>
            <Lock className="size-4" />
            اعتماد وإقفال المرحلة
          </Button>
          <Button variant="outline" onClick={() => setRejecting(true)}>
            <X className="size-4" />
            رفض مع ملاحظات
          </Button>
        </div>
      )}

      {awaitingMyReview && rejecting && (
        <div className="space-y-2">
          <Label htmlFor="reject-reason">سبب الرفض والتعديلات المطلوبة</Label>
          <Textarea
            id="reject-reason"
            rows={4}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="اكتب بوضوح ما المطلوب تعديله — هذا النص يُسجَّل في سجل المشروع."
          />
          <div className="flex gap-2">
            <Button
              variant="destructive"
              onClick={() => reject.mutate()}
              disabled={reject.isPending || reason.trim().length < 5}
            >
              <Check className="size-4" />
              تأكيد الرفض
            </Button>
            <Button
              variant="ghost"
              onClick={() => {
                setRejecting(false);
                setReason("");
              }}
            >
              إلغاء
            </Button>
          </div>
        </div>
      )}

      {/* في انتظار الطرف الآخر */}
      {!myTurn && (
        <p className="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
          {stage.status === "awaiting_approval"
            ? `المرحلة مقدَّمة وفي انتظار مراجعة ${theirLabel}.`
            : `الدور الآن على ${theirLabel}.`}
        </p>
      )}
    </div>
  );
}
