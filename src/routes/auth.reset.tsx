import { useState } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { Workflow } from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * تعيين كلمة مرور جديدة.
 *
 * تفتحها رسالة الاستعادة برابط يحمل التوكن والبريد
 * (‏/auth/reset?token=…&email=…). التوكن نفسه هو الإثبات، والسيرفر يتحقق
 * منه ومن صلاحيته — الصفحة لا تفترض شيئًا.
 */
export const Route = createFileRoute("/auth/reset")({
  validateSearch: (search: Record<string, unknown>) => ({
    token: typeof search["token"] === "string" ? search["token"] : "",
    email: typeof search["email"] === "string" ? search["email"] : "",
  }),
  head: () => ({
    meta: [{ title: "تعيين كلمة مرور جديدة | أرقام ويب" }],
  }),
  component: ResetPasswordPage,
});

function ResetPasswordPage() {
  const { token, email } = Route.useSearch();
  const navigate = useNavigate();

  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);

  const linkBroken = !token || !email;

  async function submit(e: React.FormEvent) {
    e.preventDefault();

    setBusy(true);
    try {
      const { message } = await api.auth.resetPassword({
        token,
        email,
        password,
        password_confirmation: confirm,
      });
      toast.success(message);
      navigate({ to: "/auth", replace: true });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "تعذّر تعيين كلمة المرور.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <span className="gradient-primary flex size-12 items-center justify-center rounded-2xl text-primary-foreground">
            <Workflow className="size-6" />
          </span>
          <h1 className="font-display text-2xl font-semibold">تعيين كلمة مرور جديدة</h1>
          {!linkBroken && (
            <p className="text-sm text-muted-foreground" dir="ltr">
              {email}
            </p>
          )}
        </div>

        {linkBroken ? (
          <div className="surface-card space-y-4 p-6 text-center">
            <p className="text-sm text-muted-foreground">
              الرابط ناقص أو غير صالح. اطلب رابط استعادة جديدًا من صفحة الدخول.
            </p>
            <Button className="w-full" onClick={() => navigate({ to: "/auth" })}>
              الرجوع لصفحة الدخول
            </Button>
          </div>
        ) : (
          <form className="surface-card space-y-4 p-6" onSubmit={submit}>
            <div className="space-y-2">
              <Label htmlFor="password">كلمة المرور الجديدة</Label>
              <Input
                id="password"
                type="password"
                dir="ltr"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
                autoFocus
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="confirm">تأكيد كلمة المرور</Label>
              <Input
                id="confirm"
                type="password"
                dir="ltr"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
                minLength={8}
              />
            </div>

            <Button type="submit" className="w-full" disabled={busy}>
              حفظ كلمة المرور
            </Button>

            <button
              type="button"
              className="w-full text-sm text-muted-foreground hover:text-foreground"
              onClick={() => navigate({ to: "/auth" })}
            >
              الرجوع لصفحة الدخول
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
