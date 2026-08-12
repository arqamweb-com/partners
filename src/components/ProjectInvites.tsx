import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Mail, Trash2, UserPlus } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { supabase } from "@/lib/api";

/**
 * دعوة العميل لمشروعه بالبريد الإلكتروني (للأدمن فقط).
 *
 * لو العميل مسجَّل بالفعل يُربط بالمشروع فورًا، ولو لسه ما سجّلش تنتظر
 * الدعوة حتى ينشئ حسابه بنفس البريد فيتم الربط تلقائيًا.
 */
export function ProjectInvites({ projectId }: { projectId: string }) {
  const [email, setEmail] = useState("");
  const qc = useQueryClient();

  const { data: invites = [] } = useQuery({
    queryKey: ["project-invites", projectId],
    queryFn: async () => {
      const { data } = await supabase
        .from("project_invites")
        .select("*")
        .eq("project_id", projectId)
        .order("created_at");
      return data ?? [];
    },
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["project-invites", projectId] });

  const invite = useMutation({
    mutationFn: async () => {
      const { error } = await supabase
        .from("project_invites")
        .insert({ project_id: projectId, email: email.trim() });
      if (error) throw error;
    },
    onSuccess: () => {
      toast.success("تمت الدعوة. العميل سيجد المشروع فور دخوله.");
      setEmail("");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت الدعوة."),
  });

  const remove = useMutation({
    mutationFn: async (id: string) => {
      const { error } = await supabase.from("project_invites").delete().eq("id", id);
      if (error) throw error;
    },
    onSuccess: invalidate,
    onError: () => toast.error("تعذّر حذف الدعوة."),
  });

  return (
    <div className="surface-card p-6">
      <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
        <UserPlus className="size-4" />
        عملاء المشروع
      </h2>
      <p className="mt-1 text-xs text-muted-foreground">
        أضف بريد العميل ليرى هذا المشروع عند دخوله.
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
        <Button type="submit" disabled={invite.isPending || email.trim() === ""}>
          <Mail className="size-4" />
          دعوة
        </Button>
      </form>

      {invites.length > 0 && (
        <ul className="mt-4 space-y-2">
          {invites.map((i) => (
            <li
              key={i.id}
              className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
            >
              <div className="min-w-0">
                <div className="truncate text-sm" dir="ltr">
                  {i.email}
                </div>
                <div className="text-xs text-muted-foreground">
                  {i.claimed_at ? "مرتبط بالحساب" : "بانتظار تسجيل العميل"}
                </div>
              </div>
              <Button
                variant="ghost"
                size="icon"
                aria-label="حذف الدعوة"
                onClick={() => remove.mutate(i.id)}
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
