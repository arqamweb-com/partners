import { useEffect, useState } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { Workflow } from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export const Route = createFileRoute("/auth")({
  head: () => ({
    meta: [
      { title: "تسجيل الدخول | أرقام ويب" },
      {
        name: "description",
        content: "الدخول إلى بوابة سير عمل مشاريع أرقام ويب للوكالات الشريكة.",
      },
      { property: "og:title", content: "تسجيل الدخول | أرقام ويب" },
      { property: "og:description", content: "بوابة داخلية لإدارة مراحل المشاريع واعتماداتها." },
    ],
  }),
  component: AuthPage,
});

function AuthPage() {
  const navigate = useNavigate();
  const [mode, setMode] = useState<"signin" | "signup" | "forgot">("signin");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [fullName, setFullName] = useState("");
  const [agency, setAgency] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.auth.me().then((user) => {
      if (user) navigate({ to: "/dashboard", replace: true });
    });
  }, [navigate]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      // النداءات ترمي ApiError برسالة عربية جاهزة للعرض
      if (mode === "forgot") {
        const { message } = await api.auth.forgotPassword(email);
        toast.success(message);
        setMode("signin");
        return;
      }

      if (mode === "signin") {
        await api.auth.login(email, password);
      } else {
        // التسجيل الذاتي ينشئ حساب «عميل» دائمًا ويدخّله فورًا
        await api.auth.register({
          email,
          password,
          full_name: fullName,
          agency_name: agency || null,
        });
      }
      navigate({ to: "/dashboard", replace: true });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "تعذّر إتمام العملية.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-6 flex items-center justify-center gap-2.5">
          <span className="gradient-primary flex size-10 items-center justify-center rounded-xl text-primary-foreground">
            <Workflow className="size-5" />
          </span>
          <span className="font-display text-xl font-semibold">أرقام ويب</span>
        </div>

        <form onSubmit={submit} className="surface-card space-y-4 p-7">
          <div>
            <h1 className="font-display text-lg font-semibold">
              {mode === "signin" ? "تسجيل الدخول" : "إنشاء حساب"}
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
              بوابة سير عمل مشاريع أرقام ويب للوكالات الشريكة.
            </p>
          </div>

          {mode === "signup" && (
            <>
              <div className="space-y-2">
                <Label htmlFor="name">الاسم الكامل</Label>
                <Input
                  id="name"
                  value={fullName}
                  onChange={(e) => setFullName(e.target.value)}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="agency">اسم الوكالة</Label>
                <Input id="agency" value={agency} onChange={(e) => setAgency(e.target.value)} />
              </div>
            </>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">البريد الإلكتروني</Label>
            <Input
              id="email"
              type="email"
              dir="ltr"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>
          {mode !== "forgot" && (
            <div className="space-y-2">
              <Label htmlFor="password">كلمة المرور</Label>
              <Input
                id="password"
                type="password"
                dir="ltr"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                // نفس حد السيرفر: ثمانية أحرف
                minLength={8}
              />
            </div>
          )}

          {mode === "forgot" && (
            <p className="text-xs text-muted-foreground">
              اكتب بريدك وهنبعتلك رابط تعيين كلمة مرور جديدة. الرابط صالح ساعة واحدة.
            </p>
          )}

          <Button type="submit" className="w-full" disabled={busy}>
            {mode === "signin" ? "دخول" : mode === "signup" ? "إنشاء الحساب" : "إرسال الرابط"}
          </Button>

          <div className="space-y-2">
            <button
              type="button"
              className="w-full text-sm text-muted-foreground hover:text-foreground"
              onClick={() => setMode(mode === "signin" ? "signup" : "signin")}
            >
              {mode === "signin" ? "ليس لديك حساب؟ إنشاء حساب جديد" : "لديك حساب؟ تسجيل الدخول"}
            </button>

            {mode === "signin" && (
              <button
                type="button"
                className="w-full text-sm text-muted-foreground hover:text-foreground"
                onClick={() => setMode("forgot")}
              >
                نسيت كلمة المرور؟
              </button>
            )}
          </div>
        </form>
      </div>
    </div>
  );
}
