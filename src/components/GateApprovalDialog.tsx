import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Lock } from "lucide-react";
import { toast } from "sonner";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { supabase } from "@/lib/api";
import { acknowledgementText, type Stage } from "@/lib/domain";
import { formatDateAr } from "@/lib/business-days";

export function GateApprovalDialog({
  stage,
  approverName,
  open,
  onOpenChange,
}: {
  stage: Stage;
  approverName: string;
  open: boolean;
  onOpenChange: (v: boolean) => void;
}) {
  const [typed, setTyped] = useState("");
  const queryClient = useQueryClient();
  const today = formatDateAr(new Date());
  const ack = acknowledgementText(stage.name, today);
  const matches = typed.trim().length > 2 && typed.trim() === approverName.trim();

  // الإقفال وتسجيل الإقرار وبدء المرحلة التالية تتم كلها في السيرفر داخل
  // معاملة واحدة، والسيرفر يتحقق أن الكرة في ملعب من يعتمد.
  const approve = useMutation({
    mutationFn: () => supabase.stages.approve(stage.id, typed.trim(), ack),
    onSuccess: () => {
      toast.success("تم اعتماد المرحلة وإقفالها.");
      setTyped("");
      onOpenChange(false);
      queryClient.invalidateQueries();
    },
    onError: (e: unknown) => {
      toast.error(e instanceof Error ? e.message : "تعذّر إتمام الاعتماد.");
    },
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg text-start" dir="rtl">
        <DialogHeader className="text-start">
          <DialogTitle className="font-display text-lg">اعتماد بوابة المرحلة</DialogTitle>
          <DialogDescription className="sr-only">
            إقرار اعتماد نهائي وغير قابل للتراجع
          </DialogDescription>
        </DialogHeader>

        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4">
          <div className="flex items-center gap-2 text-destructive">
            <AlertTriangle className="size-4" />
            <span className="text-sm font-semibold">إجراء نهائي لا يمكن التراجع عنه</span>
          </div>
          <p className="mt-2 text-sm leading-7 whitespace-pre-line text-foreground">{ack}</p>
        </div>

        <div className="space-y-2">
          {/* الاسم المطلوب مكتوب في العنوان نفسه — المطابقة مقصودة كإقرار،
              لا كاختبار ذاكرة على صيغة الاسم المسجّل. */}
          <Label htmlFor="ack-name">
            اكتب اسمك للتأكيد{" "}
            {approverName && <span className="text-primary">«{approverName}»</span>}
          </Label>
          <Input
            id="ack-name"
            value={typed}
            onChange={(e) => setTyped(e.target.value)}
            placeholder={approverName || "الاسم"}
            autoComplete="off"
          />
          {!matches && typed.length > 0 && (
            <p className="text-xs text-muted-foreground">
              الاسم يجب أن يطابق اسمك المسجّل: {approverName}
            </p>
          )}
        </div>

        <DialogFooter className="gap-2 sm:justify-start">
          <Button disabled={!matches || approve.isPending} onClick={() => approve.mutate()}>
            <Lock className="size-4" />
            اعتماد وإقفال المرحلة
          </Button>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            إلغاء
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
