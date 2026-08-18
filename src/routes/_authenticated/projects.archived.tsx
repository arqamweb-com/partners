import { useState } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Archive, ArrowRight, RotateCcw, Trash2 } from "lucide-react";
import { toast } from "sonner";

import { api, type ArchivedProject } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { formatDateAr } from "@/lib/business-days";
import { PROJECT_STATUS_AR } from "@/lib/domain";
import { projectTypeLabel } from "@/lib/project-types";
import { EmptyState } from "@/components/EmptyState";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
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
 * أرشيف المشاريع.
 *
 * كل ما هنا مؤرشف لا محذوف: الصف بكل ما تحته — المراحل والاعتمادات وسجل
 * التدقيق — ما زال في السيرفر، والإعادة ترجعه كما كان.
 *
 * الحذف النهائي فعل ثانٍ ومكانه هنا لا في صفحة المشروع، عمدًا: من يمحو
 * مشروعًا يمر أولًا بأرشفته، فلا يوجد زرار واحد في الطريق اليومي يمحو
 * تاريخ مشروع كامل.
 */

export const Route = createFileRoute("/_authenticated/projects/archived")({
  head: () => ({
    meta: [
      { title: "أرشيف المشاريع | أرقام ويب" },
      { name: "description", content: "المشاريع المؤرشفة: إعادتها أو حذفها نهائيًا." },
    ],
  }),
  component: ArchivedProjects,
});

function ArchivedProjects() {
  const { data: me } = useCurrentUser();
  const qc = useQueryClient();

  const [purging, setPurging] = useState<ArchivedProject | null>(null);
  const [confirmName, setConfirmName] = useState("");

  const { data, isLoading, error } = useQuery({
    queryKey: ["projects", "archived"],
    queryFn: () => api.projects.archived(),
    enabled: !!me?.isSuperUser,
  });

  const projects = data?.data ?? [];

  function refresh() {
    qc.invalidateQueries({ queryKey: ["projects"] });
    qc.invalidateQueries({ queryKey: ["projects-overview"] });
  }

  const restore = useMutation({
    mutationFn: (project: ArchivedProject) => api.projects.restore(project.id),
    onSuccess: (_result, project) => {
      refresh();
      toast.success(`رجع مشروع «${project.name}»`);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const purge = useMutation({
    mutationFn: (project: ArchivedProject) => api.projects.purge(project.id),
    onSuccess: (_result, project) => {
      refresh();
      closePurge();
      toast.success(`اتمسح مشروع «${project.name}» نهائيًا`);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  function closePurge() {
    setPurging(null);
    setConfirmName("");
  }

  if (me && !me.isSuperUser) {
    return (
      <div className="surface-card p-10">
        <EmptyState
          icon={Archive}
          title="الأرشيف للأدمن وحده"
          hint="أرشفة المشاريع وإعادتها وحذفها النهائي كلها أفعال أدمن."
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <Link
          to="/dashboard"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowRight className="size-4" />
          لوحة المشاريع
        </Link>

        <h1 className="mt-3 font-display text-2xl font-semibold">أرشيف المشاريع</h1>
        <p className="mt-1 text-sm leading-7 text-muted-foreground">
          المشاريع هنا مخفية من كل الشاشات ولم تُمسح: مراحلها واعتماداتها وسجل تدقيقها كما هي،
          والإعادة ترجعها بحالتها. الحذف النهائي وحده لا رجعة فيه.
        </p>
      </div>

      <div className="surface-card overflow-hidden">
        {isLoading && <p className="p-10 text-center text-muted-foreground">جارٍ التحميل…</p>}

        {error && <p className="p-10 text-center text-destructive">تعذّر تحميل الأرشيف.</p>}

        {!isLoading && !error && projects.length === 0 && (
          <div className="p-10">
            <EmptyState
              icon={Archive}
              title="الأرشيف فاضي"
              hint="أي مشروع تؤرشفه من صفحته هيظهر هنا."
            />
          </div>
        )}

        {projects.length > 0 && (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-start">المشروع</TableHead>
                  <TableHead className="text-start">النوع</TableHead>
                  <TableHead className="text-start">الحالة وقت الأرشفة</TableHead>
                  <TableHead className="text-start">أرشفها</TableHead>
                  <TableHead className="text-start">تاريخ الأرشفة</TableHead>
                  <TableHead className="text-start" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {projects.map((project) => (
                  <TableRow key={project.id}>
                    <TableCell className="font-medium">
                      {project.name}
                      {project.end_client_name && (
                        <span className="block text-xs text-muted-foreground">
                          {project.end_client_name}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground whitespace-nowrap">
                      {projectTypeLabel(project.project_type)}
                    </TableCell>
                    <TableCell className="text-muted-foreground whitespace-nowrap">
                      {PROJECT_STATUS_AR[project.status] ?? project.status}
                    </TableCell>
                    <TableCell className="text-muted-foreground whitespace-nowrap">
                      {project.archived_by?.full_name || project.archived_by?.email || "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground whitespace-nowrap">
                      {formatDateAr(project.deleted_at)}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => restore.mutate(project)}
                          disabled={restore.isPending}
                        >
                          <RotateCcw className="size-4" />
                          إعادة
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                          onClick={() => setPurging(project)}
                        >
                          <Trash2 className="size-4" />
                          حذف نهائي
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </div>

      <AlertDialog open={purging !== null} onOpenChange={(open) => !open && closePurge()}>
        <AlertDialogContent dir="rtl" className="text-start">
          <AlertDialogHeader className="text-start">
            <AlertDialogTitle className="font-display">حذف المشروع نهائيًا؟</AlertDialogTitle>
            <AlertDialogDescription className="leading-7">
              هيتمسح مشروع «{purging?.name}» ومعه مراحله واعتماداته وطلبات التغيير وسجل التدقيق
              والملفات المرفوعة. الفعل ده مالوش تراجع، ولا حتى من قاعدة البيانات.
            </AlertDialogDescription>
          </AlertDialogHeader>

          {/* اسم المشروع مكتوبًا باليد: الحاجز الوحيد الذي لا يُعبر بالسهو */}
          <div className="space-y-2">
            <Label htmlFor="purge-confirm">اكتب اسم المشروع للتأكيد</Label>
            <Input
              id="purge-confirm"
              value={confirmName}
              onChange={(e) => setConfirmName(e.target.value)}
              placeholder={purging?.name ?? ""}
              autoComplete="off"
            />
          </div>

          <AlertDialogFooter className="gap-2 sm:justify-start">
            <Button
              variant="destructive"
              disabled={confirmName.trim() !== purging?.name.trim() || purge.isPending}
              onClick={() => purging && purge.mutate(purging)}
            >
              {purge.isPending ? "جارٍ الحذف…" : "احذف نهائيًا"}
            </Button>
            <AlertDialogCancel>تراجع</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
