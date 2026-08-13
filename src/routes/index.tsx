import { useEffect } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { Workflow } from "lucide-react";
import { api } from "@/lib/api";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "أرقام فلو | بوابة سير عمل مشاريع أرقام ويب" },
      {
        name: "description",
        content:
          "بوابة داخلية تفرض سير عمل مقفول باتجاه واحد على مشاريع الوكالات الشريكة مع عدّاد تأخير واضح.",
      },
      { property: "og:title", content: "أرقام فلو | بوابة سير عمل مشاريع أرقام ويب" },
      { property: "og:description", content: "مراحل مقفولة، عدّاد مزدوج، وسجل تدقيق كامل." },
    ],
  }),
  component: Index,
});

function Index() {
  const navigate = useNavigate();

  useEffect(() => {
    api.auth.me().then((user) => {
      navigate({ to: user ? "/dashboard" : "/auth", replace: true });
    });
  }, [navigate]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-surface">
      <div className="flex items-center gap-2.5 text-muted-foreground">
        <span className="gradient-primary flex size-10 items-center justify-center rounded-xl text-primary-foreground">
          <Workflow className="size-5" />
        </span>
        <span className="font-display text-xl font-semibold text-foreground">أرقام ويب</span>
      </div>
    </div>
  );
}
