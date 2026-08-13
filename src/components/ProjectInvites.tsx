import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Mail, Trash2, UserPlus } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import type { ProjectMemberRole } from "@/lib/db-types";

/**
 * أعضاء المشروع ودعواتهم.
 *
 * كان هذا «دعوة عميل» فقط، لأن النظام كان يعرف دورين. الآن للعضو دور داخل
 * المشروع مستقل عن دوره في النظام: مسؤول تنفيذ، منفّذ، شريك، عميل، مطّلع.
 *
 * لو المدعوّ مسجَّل بالفعل يُربط فورًا، ولو لسه ما سجّلش تنتظر الدعوة حتى
 * ينشئ حسابه بنفس البريد فيتم الربط تلقائيًا.
 */

const ROLE_LABELS: Record<ProjectMemberRole, string> = {
  lead: "مسؤول التنفيذ",
  contributor: "منفّذ",
  partner: "شريك",
  client: "عميل",
  viewer: "مطّلع",
};

/** أدوار التنفيذ لا يسندها إلا من يملك التسعير. */
const STAFF_ROLES: ProjectMemberRole[] = ["lead", "contributor"];

export function ProjectInvites({ projectId }: { projectId: string }) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<ProjectMemberRole>("client");
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();

  const { data: members = [] } = useQuery({
    queryKey: ["project-members", projectId],
    queryFn: () => api.projects.members.list(projectId),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["project-members", projectId] });
    qc.invalidateQueries({ queryKey: ["project", projectId] });
  };

  const invite = useMutation({
    mutationFn: () => api.projects.members.invite(projectId, email.trim(), role),
    onSuccess: () => {
      toast.success("تمت الدعوة. المدعوّ سيجد المشروع فور دخوله.");
      setEmail("");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت الدعوة."),
  });

  const remove = useMutation({
    mutationFn: (memberId: string) => api.projects.members.remove(projectId, memberId),
    onSuccess: invalidate,
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر حذف العضو."),
  });

  const availableRoles = (Object.keys(ROLE_LABELS) as ProjectMemberRole[]).filter(
    (r) => me?.canPrice || !STAFF_ROLES.includes(r),
  );

  return (
    <div className="surface-card p-6">
      <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
        <UserPlus className="size-4" />
        أعضاء المشروع
      </h2>
      <p className="mt-1 text-xs text-muted-foreground">
        أضف بريد العضو وحدّد صفته في هذا المشروع.
      </p>

      <form
        className="mt-4 flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          invite.mutate();
        }}
      >
        <div className="grid min-w-[220px] flex-1 gap-1.5">
          <Label htmlFor="invite-email">البريد الإلكتروني</Label>
          <Input
            id="invite-email"
            type="email"
            required
            dir="ltr"
            placeholder="client@company.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </div>

        <div className="grid min-w-[150px] gap-1.5">
          <Label htmlFor="invite-role">الصفة</Label>
          <Select value={role} onValueChange={(v) => setRole(v as ProjectMemberRole)}>
            <SelectTrigger id="invite-role">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {availableRoles.map((r) => (
                <SelectItem key={r} value={r}>
                  {ROLE_LABELS[r]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <Button type="submit" disabled={invite.isPending || email.trim() === ""}>
          <Mail className="size-4" />
          دعوة
        </Button>
      </form>

      {members.length > 0 && (
        <ul className="mt-4 space-y-2">
          {members.map((m) => (
            <li
              key={m.id}
              className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
            >
              <div className="min-w-0">
                <div className="truncate text-sm" dir="ltr">
                  {m.user?.email ?? m.invited_email}
                </div>
                <div className="text-xs text-muted-foreground">
                  {ROLE_LABELS[m.role]}
                  {" · "}
                  {m.user ? m.user.full_name || "مرتبط بالحساب" : "بانتظار التسجيل"}
                </div>
              </div>
              <Button
                variant="ghost"
                size="icon"
                aria-label="إخراج العضو"
                onClick={() => remove.mutate(m.id)}
              >
                <Trash2 className="size-4" />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
