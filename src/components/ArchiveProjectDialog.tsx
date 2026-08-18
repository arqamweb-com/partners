import { useEffect, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { api } from "@/lib/api";
import type { Project } from "@/lib/domain";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

/**
 * تأكيد أرشفة مشروع.
 *
 * التأكيد بكتابة الاسم لا بزرار «متأكد؟»: الثاني يُضغط بالعادة بعد ثلاث
 * مرات، والأول يفرض على من يؤرشف أن يقرأ أي مشروع بين يديه. وهي أرشفة
 * قابلة للتراجع أصلًا — الحاجز هنا ضد السهو لا ضد الندم.
 */
export function ArchiveProjectDialog({
  project,
  open,
  onOpenChange,
  onArchived,
}: {
  project: Project;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onArchived?: () => void;
}) {
  const qc = useQueryClient();
  const [confirmName, setConfirmName] = useState("");

  // النافذة تُعاد استعمالها لمشاريع مختلفة، فلا يبقى فيها اسم سابق
  useEffect(() => {
    if (!open) setConfirmName("");
  }, [open]);

  const archive = useMutation({
    mutationFn: () => api.projects.archive(project.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["projects"] });
      qc.invalidateQueries({ queryKey: ["projects-overview"] });
      qc.invalidateQueries({ queryKey: ["notifications"] });
      onOpenChange(false);
      toast.success(`اتأرشف مشروع «${project.name}»`);
      onArchived?.();
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const matches = confirmName.trim() === project.name.trim();

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent dir="rtl" className="text-start">
        <AlertDialogHeader className="text-start">
          <AlertDialogTitle className="font-display">أرشفة المشروع؟</AlertDialogTitle>
          <AlertDialogDescription className="leading-7">
            مشروع «{project.name}» هيختفي من اللوحة والتقارير ومن كل الأعضاء، وإشعاراته هتترفع معه.
            المراحل والاعتمادات وسجل التدقيق هيفضلوا كما هم، وتقدر ترجّعه من الأرشيف في أي وقت.
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-2">
          <Label htmlFor="archive-confirm">اكتب اسم المشروع للتأكيد</Label>
          <Input
            id="archive-confirm"
            value={confirmName}
            onChange={(e) => setConfirmName(e.target.value)}
            placeholder={project.name}
            autoComplete="off"
          />
        </div>

        <AlertDialogFooter className="gap-2 sm:justify-start">
          <Button
            variant="destructive"
            disabled={!matches || archive.isPending}
            onClick={() => archive.mutate()}
          >
            {archive.isPending ? "جارٍ الأرشفة…" : "أرشف المشروع"}
          </Button>
          <AlertDialogCancel>تراجع</AlertDialogCancel>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
