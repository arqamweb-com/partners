import { useMemo, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { Printer } from "lucide-react";
import { supabase } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { EmptyState } from "@/components/EmptyState";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { ChangeRequest, Project, Stage } from "@/lib/domain";

export const Route = createFileRoute("/_authenticated/reports")({
  head: () => ({
    meta: [
      { title: "التقرير الشهري | أرقام فلو" },
      {
        name: "description",
        content: "تقرير أداء الوكالات الشريكة: الالتزام بالمواعيد، التأخير، وطلبات التغيير.",
      },
      { property: "og:title", content: "التقرير الشهري للوكالات | أرقام فلو" },
      { property: "og:description", content: "أرقام موضوعية بدل الانطباعات في نقاش الالتزام." },
    ],
  }),
  component: ReportsPage,
});

const MONTHS_AR = [
  "يناير",
  "فبراير",
  "مارس",
  "أبريل",
  "مايو",
  "يونيو",
  "يوليو",
  "أغسطس",
  "سبتمبر",
  "أكتوبر",
  "نوفمبر",
  "ديسمبر",
];

function ReportsPage() {
  const { data: me } = useCurrentUser();
  const now = new Date();
  const [month, setMonth] = useState(
    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`,
  );

  const { data, isLoading } = useQuery({
    queryKey: ["monthly-report", month],
    queryFn: async () => {
      const start = new Date(`${month}-01T00:00:00`);
      const end = new Date(start.getFullYear(), start.getMonth() + 1, 1);
      const [{ data: projects }, { data: stages }, { data: crs }, { data: rounds }] =
        await Promise.all([
          supabase.from("projects").select("*"),
          supabase.from("stages").select("*"),
          supabase
            .from("change_requests")
            .select("*")
            .gte("created_at", start.toISOString())
            .lt("created_at", end.toISOString()),
          supabase
            .from("feedback_rounds")
            .select("id, project_id, created_at")
            .gte("created_at", start.toISOString())
            .lt("created_at", end.toISOString()),
        ]);
      return {
        projects: (projects ?? []) as Project[],
        stages: (stages ?? []) as Stage[],
        crs: (crs ?? []) as ChangeRequest[],
        rounds: (rounds ?? []) as { id: string; project_id: string }[],
        label: `${MONTHS_AR[start.getMonth()]} ${start.getFullYear()}`,
      };
    },
  });

  const rows = useMemo(() => {
    if (!data) return [];
    const byAgency = new Map<
      string,
      {
        agency: string;
        projects: number;
        delayDays: number;
        frozen: number;
        locked: number;
        onTime: number;
        crs: number;
        rounds: number;
      }
    >();
    for (const p of data.projects) {
      // الطلبات غير المعتمدة ليست مشاريع بعد — إدخالها يضخّم العدد ويخفض
      // متوسط التأخير زورًا لأنها بلا مراحل ولا أيام تأخير
      if (p.status === "draft") continue;

      const key = p.partner_agency || "بدون وكالة";
      const row = byAgency.get(key) ?? {
        agency: key,
        projects: 0,
        delayDays: 0,
        frozen: 0,
        locked: 0,
        onTime: 0,
        crs: 0,
        rounds: 0,
      };
      row.projects += 1;
      row.delayDays += p.client_delay_days;
      if (p.status === "frozen" || p.status === "stopped") row.frozen += 1;
      for (const s of data.stages.filter((s) => s.project_id === p.id && s.locked_at)) {
        row.locked += 1;
        if (!s.due_at || new Date(s.locked_at as string) <= new Date(s.due_at)) row.onTime += 1;
      }
      row.crs += data.crs.filter((c) => c.project_id === p.id).length;
      row.rounds += data.rounds.filter((r) => r.project_id === p.id).length;
      byAgency.set(key, row);
    }
    return [...byAgency.values()].sort((a, b) => b.projects - a.projects);
  }, [data]);

  if (me && !me.isAdmin) {
    return (
      <EmptyState
        title="التقرير الشهري لفريق أرقام"
        hint="يقارن هذا التقرير أداء الوكالات الشريكة، ولذلك يقتصر على المدير."
      />
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4 print:hidden">
        <div>
          <h1 className="font-display text-2xl font-semibold">التقرير الشهري</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            أرقام موضوعية عن التزام كل وكالة — تُستخدم في مراجعة الشراكة بدل الانطباعات.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <div className="grid gap-1.5">
            <Label htmlFor="month">الشهر</Label>
            <Input
              id="month"
              type="month"
              className="num"
              value={month}
              onChange={(e) => setMonth(e.target.value)}
            />
          </div>
          <Button variant="secondary" onClick={() => window.print()}>
            <Printer className="size-4" /> طباعة
          </Button>
        </div>
      </div>

      <div className="surface-card p-6 print:border-0 print:shadow-none">
        <header className="mb-5 border-b border-border pb-4">
          <p className="font-display text-lg font-semibold">
            أرقام ويب — تقرير أداء الوكالات الشريكة
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            الفترة: {data?.label ?? "—"} · يُحتسب الالتزام بأيام العمل (الأحد–الخميس) مع استثناء
            العطل الرسمية.
          </p>
        </header>

        {isLoading ? (
          <p className="py-10 text-center text-muted-foreground">جارٍ التحميل…</p>
        ) : rows.length === 0 ? (
          <EmptyState
            title="لا توجد بيانات لهذا الشهر"
            hint="لم تُسجَّل مشاريع أو نشاط لوكالات شريكة ضمن الفترة المحددة."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-border text-xs text-muted-foreground">
                  <th className="px-3 py-2 text-start font-medium">الوكالة</th>
                  <th className="px-3 py-2 text-start font-medium">المشاريع</th>
                  <th className="px-3 py-2 text-start font-medium">بوابات مقفولة</th>
                  <th className="px-3 py-2 text-start font-medium">في الموعد</th>
                  <th className="px-3 py-2 text-start font-medium">متوسط تأخير العميل</th>
                  <th className="px-3 py-2 text-start font-medium">جولات ملاحظات</th>
                  <th className="px-3 py-2 text-start font-medium">طلبات تغيير</th>
                  <th className="px-3 py-2 text-start font-medium">مجمّد/متوقف</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => {
                  const pct = r.locked ? Math.round((r.onTime / r.locked) * 100) : 0;
                  return (
                    <tr key={r.agency} className="border-b border-border/60 last:border-0">
                      <td className="px-3 py-3 font-medium">{r.agency}</td>
                      <td className="px-3 py-3">
                        <Num value={r.projects} />
                      </td>
                      <td className="px-3 py-3">
                        <Num value={r.locked} />
                      </td>
                      <td className="px-3 py-3">
                        <Num value={pct} suffix="%" />
                      </td>
                      <td className="px-3 py-3">
                        <Num value={r.projects ? Math.round(r.delayDays / r.projects) : 0} /> يوم
                      </td>
                      <td className="px-3 py-3">
                        <Num value={r.rounds} />
                      </td>
                      <td className="px-3 py-3">
                        <Num value={r.crs} />
                      </td>
                      <td className="px-3 py-3">
                        <Num value={r.frozen} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <footer className="mt-6 border-t border-border pt-4 text-xs leading-6 text-muted-foreground">
          التأخير المحسوب هنا هو تأخير طرف العميل فقط؛ مدد فريق أرقام تُقاس داخل كل مرحلة على حدة.
          الردود غير الحاسمة لا توقف العدّاد، ولذلك لا تُحتسب ضمن أيام العميل.
        </footer>
      </div>
    </div>
  );
}
