import { createFileRoute, Link } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { AlertTriangle, Archive, ChevronLeft, Inbox, Snowflake, Timer, Users } from "lucide-react";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { useHolidays } from "@/hooks/useSettings";
import {
  STAGE_STATUS_AR,
  ballLabel,
  currentStage,
  isWarrantyStage,
  projectStatusView,
  type Project,
  type Stage,
} from "@/lib/domain";
import { businessDaysUntil, formatDateAr } from "@/lib/business-days";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { StatusPill } from "@/components/StatusPill";

export const Route = createFileRoute("/_authenticated/dashboard")({
  head: () => ({
    meta: [
      { title: "لوحة المشاريع | أرقام ويب" },
      {
        name: "description",
        content: "نظرة واحدة على كل المشاريع: المرحلة الحالية، عند من الكرة، وأيام التأخير.",
      },
      { property: "og:title", content: "لوحة المشاريع | أرقام ويب" },
      {
        property: "og:description",
        content: "نظرة واحدة على كل المشاريع ومواعيد التسليم المعدّلة.",
      },
    ],
  }),
  component: Dashboard,
});

function Dashboard() {
  const { data: me } = useCurrentUser();
  const { data: holidays = [] } = useHolidays();

  const { data, isLoading, error } = useQuery({
    queryKey: ["projects-overview"],
    queryFn: () => api.overview.dashboard(),
  });

  const stagesByProject = new Map<string, Stage[]>();
  for (const s of data?.stages ?? []) {
    stagesByProject.set(s.project_id, [...(stagesByProject.get(s.project_id) ?? []), s]);
  }

  // Problems first: new requests, then frozen/stopped, then overdue,
  // then the longest client delay.
  function priority(p: Project): number {
    const stages = stagesByProject.get(p.id) ?? [];
    const cur = currentStage(stages);
    const daysLeft = cur?.due_at ? businessDaysUntil(new Date(cur.due_at), holidays) : null;
    // طلب مستنّي مراجعة يتصدّر: بلا مراحل ولا تأخير فكان هيغرق في القاع
    if (p.status === "draft") return 0;
    if (p.status === "frozen" || p.status === "stopped") return 1;
    if (daysLeft !== null && daysLeft < 0) return 2;
    if (p.client_delay_days > 0 || p.status === "awaiting_client") return 3;
    return 4;
  }
  const projects = [...(data?.projects ?? [])].sort(
    (a, b) => priority(a) - priority(b) || b.client_delay_days - a.client_delay_days,
  );

  const drafts = projects.filter((p) => p.status === "draft").length;

  const kpis = [
    ...(drafts > 0
      ? [
          {
            label: "طلبات جديدة",
            value: drafts,
            icon: Inbox,
            tone: "warn",
          },
        ]
      : []),
    {
      label: "نشط",
      value: projects.filter((p) => p.status === "active").length,
      icon: Timer,
      tone: "primary",
    },
    {
      label: "في انتظار العميل",
      value: projects.filter((p) => p.status === "awaiting_client").length,
      icon: Users,
      tone: "warn",
    },
    {
      label: "مجمّد",
      value: projects.filter((p) => p.status === "frozen").length,
      icon: Snowflake,
      tone: "muted",
    },
    {
      label: "إجمالي أيام تأخير العملاء",
      value: projects.reduce((a, p) => a + p.client_delay_days, 0),
      icon: AlertTriangle,
      tone: "destructive",
    },
  ] as const;

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="font-display text-2xl font-semibold">
            {me?.isAdmin ? "لوحة المشاريع" : "مشاريعك"}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {me?.isAdmin
              ? "كل مشروع، مرحلته الحالية، وعند من الكرة الآن."
              : "المشاريع المسندة إليك ومواعيدها الحالية."}
          </p>
        </div>

        {/* الأرشيف ليس بندًا في القائمة العلوية: يُزار نادرًا ومن اللوحة */}
        {me?.isSuperUser && (
          <Link
            to="/projects/archived"
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          >
            <Archive className="size-4" />
            الأرشيف
          </Link>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {kpis.map((k) => (
          <div key={k.label} className="surface-card p-5">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">{k.label}</span>
              <k.icon
                className={cn(
                  "size-4",
                  k.tone === "primary" && "text-primary",
                  k.tone === "warn" && "text-warn",
                  k.tone === "muted" && "text-muted-foreground",
                  k.tone === "destructive" && "text-destructive",
                )}
              />
            </div>
            <div className="mt-3 font-display text-3xl font-semibold">
              <Num value={k.value} />
            </div>
          </div>
        ))}
      </div>

      <div className="surface-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-surface-elevated text-xs text-muted-foreground">
                {[
                  "المشروع",
                  "العميل النهائي",
                  "المرحلة الحالية",
                  "الكرة عند مين",
                  "موعد الاستحقاق",
                  "أيام تأخير العميل",
                  "تاريخ التسليم المعدّل",
                  "الحالة",
                  "",
                ].map((h) => (
                  <th key={h} className="px-4 py-3 text-start font-medium whitespace-nowrap">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-muted-foreground">
                    جارٍ التحميل…
                  </td>
                </tr>
              )}
              {error && (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-destructive">
                    تعذّر تحميل المشاريع.
                  </td>
                </tr>
              )}
              {!isLoading && !error && projects.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                    لا توجد مشاريع مسندة إليك حتى الآن.
                  </td>
                </tr>
              )}
              {projects.map((p) => {
                const stages = stagesByProject.get(p.id) ?? [];
                const cur = currentStage(stages);
                const daysLeft = cur?.due_at
                  ? businessDaysUntil(new Date(cur.due_at), holidays)
                  : null;
                // الضمان ليس دورًا على أحد، فلا يُحسب تأخيرًا على أي طرف
                const inWarranty = isWarrantyStage(cur);
                const overdue = !inWarranty && daysLeft !== null && daysLeft < 0;
                // مشروع بمراحل انتهت كلها = مكتمل. بلا مراحل أصلًا = لا شيء يُقال.
                const settled = inWarranty || (stages.length > 0 && !cur);
                const statusView = projectStatusView(p, cur, stages.length > 0);
                return (
                  <tr
                    key={p.id}
                    className="border-b border-border/70 last:border-0 hover:bg-surface"
                  >
                    <td className="px-4 py-4 font-medium whitespace-nowrap">{p.name}</td>
                    <td className="px-4 py-4 text-muted-foreground whitespace-nowrap">
                      {p.end_client_name}
                    </td>
                    <td className="px-4 py-4 whitespace-nowrap">
                      {cur ? (
                        <span>
                          <Num value={cur.stage_index + 1} />. {cur.name}
                          <span className="ms-2 text-xs text-muted-foreground">
                            {STAGE_STATUS_AR[cur.status]}
                          </span>
                        </span>
                      ) : (
                        "—"
                      )}
                    </td>
                    <td className="px-4 py-4 whitespace-nowrap">
                      {p.status === "draft" ? (
                        <Badge className="border-0 bg-warn/15 font-medium text-warn-foreground">
                          بانتظار المراجعة
                        </Badge>
                      ) : (
                        <Badge
                          className={cn(
                            "border-0 font-medium",
                            overdue
                              ? "bg-destructive/12 text-destructive"
                              : settled
                                ? "bg-success/15 text-success-foreground"
                                : cur?.ball_in_court === "them"
                                  ? "bg-warn/15 text-warn-foreground"
                                  : "bg-primary/12 text-primary",
                          )}
                        >
                          {/*
                            الطرف وحده. كان التأخير يحلّ محلّ الاسم هنا، فيجيب
                            العمود عن سؤال غير سؤاله — و«متأخر» بجانب «٠ يوم
                            تأخير» في نفس الصف تقرأ تناقضًا وهي ليست كذلك:
                            العدّاد للعميل، والتأخير قد يكون علينا. اللون وحده
                            يكفي للتنبيه هنا، والمدة مكانها عمود الاستحقاق.
                          */}
                          {stages.length === 0 ? "—" : ballLabel(cur)}
                        </Badge>
                      )}
                    </td>
                    <td className="px-4 py-4 whitespace-nowrap">
                      {cur?.due_at ? (
                        <span
                          className={cn(overdue ? "text-destructive" : "text-muted-foreground")}
                        >
                          {formatDateAr(cur.due_at)}
                          {overdue && (
                            <span className="block text-xs font-medium">
                              متأخرة <Num value={Math.abs(daysLeft ?? 0)} /> يوم عمل
                            </span>
                          )}
                        </span>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="px-4 py-4">
                      <span
                        className={cn(p.client_delay_days > 0 && "font-semibold text-destructive")}
                      >
                        <Num value={p.client_delay_days} />
                      </span>
                    </td>
                    <td className="px-4 py-4 whitespace-nowrap">
                      {formatDateAr(p.adjusted_delivery_date)}
                    </td>
                    <td className="px-4 py-4">
                      <StatusPill status={statusView.key} label={statusView.label} />
                    </td>
                    <td className="px-4 py-4">
                      <Link
                        to="/projects/$projectId"
                        params={{ projectId: p.id }}
                        className="inline-flex items-center gap-1 text-primary hover:underline whitespace-nowrap"
                      >
                        التفاصيل
                        <ChevronLeft className="size-4" />
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
