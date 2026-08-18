import { useState } from "react";
import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  Archive,
  ChevronRight,
  Info,
  Lock,
  Paperclip,
  Rocket,
  Snowflake,
  Wallet,
} from "lucide-react";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { useHolidays } from "@/hooks/useSettings";
import {
  ballLabel,
  CR_LAUNCH_MESSAGE,
  LOCKED_MESSAGE,
  NON_DECISIVE_NOTE,
  PROJECT_STATUS_AR,
  isWarrantyStage,
  projectStatusView,
  STAGE_STATUS_AR,
  TRACK_AR,
  currentStage,
  isOpenCR,
  isBigGate,
  type AuditEntry,
  type ChangeRequest,
  type FeedbackItem,
  type Project,
  type Stage,
} from "@/lib/domain";
import { businessDaysUntil, formatDateAr } from "@/lib/business-days";
import {
  describeDetails,
  filledIntake,
  intakeProgress,
  projectTypeLabel,
  readDetails,
  readIntake,
} from "@/lib/project-types";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { StatusPill } from "@/components/StatusPill";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { toast } from "sonner";
import { GateApprovalDialog } from "@/components/GateApprovalDialog";
import { StageReviewPanel } from "@/components/StageReviewPanel";
import { StageEditor } from "@/components/StageEditor";
import { ProjectInvites } from "@/components/ProjectInvites";
import { AccessChecklist } from "@/components/AccessChecklist";
import { ContentChecklist } from "@/components/ContentChecklist";
import { FeedbackTab } from "@/components/FeedbackTab";
import { ChangeRequestsTab } from "@/components/ChangeRequestsTab";
import { ReactivateDialog } from "@/components/ReactivateDialog";
import { ArchiveProjectDialog } from "@/components/ArchiveProjectDialog";

export const Route = createFileRoute("/_authenticated/projects/$projectId")({
  head: () => ({
    meta: [
      { title: "تفاصيل المشروع | أرقام ويب" },
      {
        name: "description",
        content: "مراحل المشروع، بوابات الاعتماد المقفولة، عدّاد التأخير وسجل التدقيق.",
      },
      { property: "og:title", content: "تفاصيل المشروع | أرقام ويب" },
      { property: "og:description", content: "من عنده الكرة الآن، وكم كلّف التأخير." },
    ],
  }),
  component: ProjectDetail,
});

function ProjectDetail() {
  const { projectId } = Route.useParams();
  const { data: me } = useCurrentUser();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [gateStage, setGateStage] = useState<Stage | null>(null);
  const [reactivating, setReactivating] = useState(false);
  const [archiving, setArchiving] = useState(false);
  const [tab, setTab] = useState("content");
  const [crSeed, setCrSeed] = useState<FeedbackItem | null>(null);

  const { data: holidays = [] } = useHolidays();

  const { data, isLoading } = useQuery({
    queryKey: ["project", projectId],
    // نداءان بدل أربعة: المشروع يأتي بعلاقاته، والسجل مستقل لأنه قد يطول
    queryFn: async () => {
      const [project, log] = await Promise.all([
        api.projects.get(projectId),
        api.projects.auditLog(projectId),
      ]);

      return {
        project: project as Project,
        stages: project.stages,
        log,
        crs: project.change_requests,
      };
    },
  });

  const stopProject = useMutation({
    mutationFn: async (p: Project) => {
      const expiry = new Date();
      expiry.setFullYear(expiry.getFullYear() + 1);

      // الرصيد الدائن بند تعاقدي، والحالة فعل مستقل — لكل واحد صلاحيته
      await api.projects.updateCharter(p.id, {
        credit_amount: Number(p.credit_amount) > 0 ? Number(p.credit_amount) : 0,
        credit_expires_at: expiry.toISOString().slice(0, 10),
      });

      await api.projects.changeStatus(
        p.id,
        "stopped",
        "تجميد تجاوز 60 يومًا. المبالغ المدفوعة مسجَّلة كرصيد دائن صالح 12 شهرًا.",
      );
    },
    onSuccess: () => {
      toast.success("سُجِّل التوقف والرصيد الدائن.");
      qc.invalidateQueries();
    },
    onError: () => toast.error("تعذّر تسجيل التوقف."),
  });

  if (isLoading) return <p className="py-16 text-center text-muted-foreground">جارٍ التحميل…</p>;
  if (!data?.project)
    return <p className="py-16 text-center text-muted-foreground">المشروع غير موجود.</p>;

  const project = data.project;

  // طلب لم يُعتمد بعد: لا مراحل ولا قوائم، فكل شاشة المشروع بلا معنى.
  // نعرض ملخّصه فقط — وهذا يمنع أيضًا فتح جولات ملاحظات أو طلبات تغيير عليه.
  if (project.status === "draft") {
    return <DraftSummary project={project} isAdmin={!!me?.isAdmin} />;
  }

  const sequential = data.stages.filter((s) => !s.is_parallel);
  const parallel = data.stages.find((s) => s.is_parallel);
  const cur = currentStage(data.stages);
  const daysLeft = cur?.due_at ? businessDaysUntil(new Date(cur.due_at), holidays) : null;
  // الضمان ليس دورًا على أحد: لا تأخير يُحسب فيه ولا كرة في ملعب أحد
  const inWarranty = isWarrantyStage(cur);
  const overdue = !inWarranty && daysLeft !== null && daysLeft < 0;
  const statusView = projectStatusView(project, cur, sequential.length > 0);
  const openCRs = data.crs.filter(isOpenCR).length;
  const onLaunchStage = !!cur && (cur.name.includes("الإطلاق") || cur.gate_name === "Go Live");
  const frozenDays = project.frozen_at
    ? Math.floor((Date.now() - new Date(project.frozen_at).getTime()) / 86400000)
    : 0;

  return (
    <div className="space-y-8">
      <nav className="flex items-center gap-1 text-sm text-muted-foreground">
        <Link to="/dashboard" className="hover:text-foreground">
          المشاريع
        </Link>
        <ChevronRight className="size-4 rtl-flip" />
        <span className="text-foreground">{project.name}</span>
      </nav>

      {/* Header + dual clock */}
      <div className="surface-card p-6">
        <div className="flex flex-wrap items-start justify-between gap-6">
          <div>
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="font-display text-2xl font-semibold">{project.name}</h1>
              <StatusPill status={statusView.key} label={statusView.label} />
              <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-medium">
                {TRACK_AR[project.track]}
              </span>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">
              العميل النهائي: {project.end_client_name} · الوكالة الشريكة: {project.partner_agency}{" "}
              · مسؤول المشروع: {project.owner_name}
            </p>
          </div>

          <div className="w-full min-w-[300px] rounded-2xl border-2 border-primary/25 bg-surface-elevated p-5 shadow-[var(--shadow-card)] sm:w-auto">
            <div className="flex items-baseline justify-between gap-6 py-1.5">
              <span className="text-sm text-muted-foreground">تاريخ التسليم الأصلي</span>
              <span className="text-base font-medium text-muted-foreground line-through decoration-muted-foreground/40">
                {formatDateAr(project.original_delivery_date)}
              </span>
            </div>
            <div className="flex items-baseline justify-between gap-6 border-y border-border/70 py-2">
              <span className="text-sm text-muted-foreground">أيام تأخير العميل المتراكمة</span>
              <span
                className={cn(
                  "font-display text-xl font-semibold",
                  project.client_delay_days > 0 && "text-destructive",
                )}
              >
                <Num value={project.client_delay_days} />
                <span className="ms-1 text-sm font-medium">يوم عمل</span>
              </span>
            </div>
            <div className="flex items-baseline justify-between gap-6 pt-2.5">
              <span className="text-sm font-medium">تاريخ التسليم المعدّل</span>
              <span className="font-display text-2xl leading-tight font-bold text-primary">
                {formatDateAr(project.adjusted_delivery_date)}
              </span>
            </div>
          </div>
        </div>

        {project.client_delay_days >= 10 || project.status === "frozen" ? (
          <Banner tone="destructive" icon={Snowflake}>
            المشروع مجمّد بعد تجاوز <Num value={10} /> أيام عمل من تأخير العميل. إعادة التفعيل تتطلب
            إجراءً من فريق أرقام يسجّل رسوم إعادة التفعيل ويحدّد موعد دور جديد.
          </Banner>
        ) : project.client_delay_days >= 5 ? (
          <Banner tone="warn" icon={AlertTriangle}>
            تجاوز تأخير العميل <Num value={5} /> أيام عمل. عند <Num value={10} /> أيام يتم تجميد
            المشروع تلقائيًا ويخرج من قائمة الانتظار النشطة.
          </Banner>
        ) : null}

        {me?.isAdmin && (project.status === "frozen" || project.client_delay_days >= 10) && (
          <div className="mt-4 flex flex-wrap gap-2">
            <Button onClick={() => setReactivating(true)}>إعادة تنشيط بموعد دور جديد</Button>
            {frozenDays >= 60 && project.status !== "stopped" && (
              <Button variant="outline" onClick={() => stopProject.mutate(project)}>
                تسجيل التوقف ورصيد دائن 12 شهرًا
              </Button>
            )}
          </div>
        )}

        {Number(project.credit_amount) > 0 && (
          <div className="mt-4 flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm">
            <Wallet className="mt-0.5 size-4 shrink-0 text-primary" />
            <p className="leading-6">
              رصيد دائن محفوظ باسم العميل: <Num value={Number(project.credit_amount)} /> SAR، صالح
              للاستخدام حتى {formatDateAr(project.credit_expires_at)}. الرصيد قابل للاستخدام في أي
              مشروع جديد ولا يسقط قبل هذا التاريخ.
            </p>
          </div>
        )}

        {onLaunchStage && (
          <div className="mt-4 flex items-start gap-3 rounded-xl border border-border bg-surface-elevated p-4 text-sm">
            <Rocket className="mt-0.5 size-4 shrink-0 text-primary" />
            <p className="leading-6">
              {CR_LAUNCH_MESSAGE} يوجد <Num value={openCRs} /> طلب تغيير مفتوح، والإطلاق يمضي على
              النطاق المعتمد.
            </p>
          </div>
        )}
      </div>

      {/* Stepper */}
      <div className="surface-card p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="font-display text-lg font-semibold">مسار المراحل</h2>
          <div className="flex items-center gap-3">
            <span className="text-xs text-muted-foreground">
              اتجاه واحد — لا يمكن فتح مرحلة مقفولة
            </span>
            {me?.isAdmin && <StageEditor projectId={project.id} stages={data.stages} />}
          </div>
        </div>

        <ol className="mt-5 flex gap-3 overflow-x-auto pb-2">
          {sequential.map((s) => {
            const isCurrent = cur?.id === s.id;
            const locked = !!s.locked_at;
            return (
              <li key={s.id} className="min-w-[170px] flex-1">
                <button
                  type="button"
                  disabled={!locked && !isCurrent}
                  onClick={() => locked && toast.info(LOCKED_MESSAGE)}
                  className={cn(
                    "w-full rounded-xl border p-3 text-start transition-[background-color,border-color] duration-300",
                    locked && "border-border bg-surface-elevated hover:bg-accent/60",
                    isCurrent && "border-primary bg-primary/8 shadow-[var(--shadow-card)]",
                    !locked && !isCurrent && "border-dashed border-border/70 opacity-45",
                  )}
                >
                  <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Num value={s.stage_index + 1} />
                    {locked && <Lock className="size-3" />}
                    {isCurrent && <span className="text-primary">الحالية</span>}
                  </div>
                  <div className="mt-1 text-sm font-medium">{s.name}</div>
                  <div className="mt-1 text-xs text-muted-foreground">
                    {s.gate_name ? `بوابة: ${s.gate_name}` : "بدون بوابة"}
                  </div>
                  <div className="mt-2 text-xs">{STAGE_STATUS_AR[s.status]}</div>
                </button>
              </li>
            );
          })}
        </ol>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Current stage */}
        <div className="surface-card p-6 lg:col-span-2">
          <h2 className="font-display text-lg font-semibold">المرحلة الحالية</h2>
          {cur ? (
            <>
              <div className="mt-4 flex flex-wrap items-center gap-3">
                <span className="text-base font-medium">
                  <Num value={cur.stage_index + 1} />. {cur.name}
                </span>
                <span
                  className={cn(
                    "rounded-full px-2.5 py-1 text-xs font-medium",
                    overdue
                      ? "bg-destructive/12 text-destructive"
                      : inWarranty
                        ? "bg-success/15 text-success-foreground"
                        : cur.ball_in_court === "them"
                          ? "bg-warn/15 text-warn-foreground"
                          : "bg-primary/12 text-primary",
                  )}
                >
                  {inWarranty ? ballLabel(cur) : `الكرة ${ballLabel(cur)}`}
                </span>
                {cur.gate_name && (
                  <span className="rounded-full bg-secondary px-2.5 py-1 text-xs">
                    {isBigGate(cur) ? "بوابة كبرى" : "بوابة صغرى"} · {cur.gate_name}
                  </span>
                )}
              </div>

              <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <Metric label="مدة أرقام ويب للتنفيذ" value={`${cur.our_duration_days} يوم عمل`} />
                <Metric label="مدة العميل للمراجعة" value={`${cur.their_duration_days} يوم عمل`} />
                <Metric
                  label="المتبقي حتى الاستحقاق"
                  value={
                    daysLeft === null
                      ? "—"
                      : daysLeft < 0
                        ? `متأخر ${Math.abs(daysLeft)} يوم عمل`
                        : `${daysLeft} يوم عمل`
                  }
                  tone={
                    overdue
                      ? "destructive"
                      : daysLeft !== null && daysLeft <= 1
                        ? "warn"
                        : "default"
                  }
                />
              </div>

              <p className="mt-4 flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                <Info className="size-3.5 shrink-0" />
                {NON_DECISIVE_NOTE} الرد يُحتسب فقط إذا كان اعتمادًا أو نموذج ملاحظات مكتملًا.
                {isBigGate(cur)
                  ? " هذه بوابة كبرى: لا يوجد اعتماد صامت — عدم الرد ينقل المشروع إلى التجميد."
                  : " هذه بوابة صغرى: عدم الرد خلال المدة يؤدي إلى اعتماد صامت وإقفال تلقائي بعد مهلة يومين."}
              </p>

              {cur.locked_at ? (
                <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
                  <Lock className="size-4" /> {LOCKED_MESSAGE}
                </p>
              ) : (
                <StageReviewPanel stage={cur} isAdmin={!!me?.isAdmin} onApprove={setGateStage} />
              )}
            </>
          ) : (
            <p className="mt-4 text-sm text-muted-foreground">لا توجد مرحلة جارية.</p>
          )}
        </div>

        {/* Parallel access track */}
        <AccessChecklist projectId={project.id} stage={parallel} onLock={setGateStage} />
      </div>

      <Tabs value={tab} onValueChange={setTab} dir="rtl">
        <TabsList>
          <TabsTrigger value="content">المحتوى</TabsTrigger>
          <TabsTrigger value="feedback">الملاحظات</TabsTrigger>
          <TabsTrigger value="changes">
            طلبات التغيير
            {openCRs > 0 && (
              <span className="ms-1.5 rounded-full bg-warn/20 px-1.5 text-[11px] text-warn-foreground">
                <Num value={openCRs} />
              </span>
            )}
          </TabsTrigger>
          <TabsTrigger value="log">السجل</TabsTrigger>
        </TabsList>

        <TabsContent value="content">
          <div className="space-y-6">
            <ProjectSpec project={project} />
            <ClientIntake project={project} />
            <ContentChecklist projectId={project.id} />
            {me?.isAdmin && <ProjectInvites projectId={project.id} />}
          </div>
        </TabsContent>
        <TabsContent value="feedback">
          <FeedbackTab
            project={project}
            currentStage={cur}
            onConvertToCR={(item) => {
              setCrSeed(item);
              setTab("changes");
            }}
          />
        </TabsContent>
        <TabsContent value="changes">
          <ChangeRequestsTab
            project={project}
            seedFrom={crSeed}
            onSeedConsumed={() => setCrSeed(null)}
          />
        </TabsContent>
        <TabsContent value="log">
          <div className="surface-card mt-4 p-6">
            <h3 className="font-display text-base font-semibold">سجل التدقيق</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              سجل غير قابل للتعديل أو الحذف — هو مرجع الإثبات لكل إجراء.
            </p>
            <ol className="mt-5 space-y-5">
              {data.log.length === 0 && (
                <li className="text-sm text-muted-foreground">لا توجد أحداث مسجّلة بعد.</li>
              )}
              {data.log.map((e) => (
                <li key={e.id} className="relative ps-6">
                  <span className="absolute end-auto start-0 top-1.5 size-2 rounded-full bg-primary" />
                  <span className="absolute start-[3.5px] top-4 bottom-[-14px] w-px bg-border" />
                  <div className="text-sm">{e.description}</div>
                  <div className="mt-1 text-xs text-muted-foreground">
                    {e.actor_name} · {formatDateAr(e.created_at)}
                  </div>
                </li>
              ))}
            </ol>
          </div>
        </TabsContent>
      </Tabs>

      {gateStage && (
        <GateApprovalDialog
          stage={gateStage}
          approverName={me?.fullName ?? ""}
          open={!!gateStage}
          onOpenChange={(v) => !v && setGateStage(null)}
        />
      )}

      {/*
        الأرشفة آخر ما في الصفحة وخلف حاجز، لا زرارًا بين أزرار الإجراءات:
        فعل نادر ومكلف لا يجاور أفعالًا يومية.
      */}
      {me?.isSuperUser && (
        <div className="rounded-2xl border border-destructive/30 bg-destructive/5 p-6">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h3 className="flex items-center gap-2 font-display text-base font-semibold text-destructive">
                <Archive className="size-4" />
                أرشفة المشروع
              </h3>
              <p className="mt-1.5 max-w-xl text-sm leading-7 text-muted-foreground">
                المشروع هيختفي من اللوحة والتقارير ومن كل من له علاقة به، وإشعاراته هتترفع معه. مفيش
                حاجة بتتمسح: المراحل والاعتمادات وسجل التدقيق باقيين، وتقدر ترجّعه من{" "}
                <Link to="/projects/archived" className="text-primary hover:underline">
                  الأرشيف
                </Link>
                .
              </p>
            </div>

            <Button variant="outline" onClick={() => setArchiving(true)}>
              <Archive className="size-4" />
              أرشفة
            </Button>
          </div>
        </div>
      )}

      <ReactivateDialog project={project} open={reactivating} onOpenChange={setReactivating} />

      <ArchiveProjectDialog
        project={project}
        open={archiving}
        onOpenChange={setArchiving}
        onArchived={() => navigate({ to: "/dashboard" })}
      />
    </div>
  );
}

/** بيانات ومرفقات سجّلها العميل وقت الطلب — اللوجو والروابط والتواصل. */
function ClientIntake({ project }: { project: Project }) {
  const groups = filledIntake(
    project.project_type,
    readIntake(project.project_type, project.intake_data),
  );
  const done = intakeProgress(
    project.project_type,
    readIntake(project.project_type, project.intake_data),
  );

  return (
    <div className="surface-card p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
          <Paperclip className="size-4" />
          بيانات ومرفقات العميل
        </h2>
        <span className="rounded-full bg-secondary px-3 py-1 text-xs font-medium">
          <Num value={done.filled} /> من <Num value={done.total} /> حقل
        </span>
      </div>

      {groups.length === 0 ? (
        <p className="mt-3 text-sm text-muted-foreground">العميل لم يسجّل أي بيانات مع الطلب.</p>
      ) : (
        <div className="mt-4 grid gap-5">
          {groups.map((g) => (
            <div key={g.group}>
              <div className="text-xs font-semibold text-primary">{g.group}</div>
              <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                {g.items.map((item) => (
                  <div
                    key={item.label}
                    className="rounded-lg border border-border bg-surface-elevated p-3"
                  >
                    <dt className="text-xs text-muted-foreground">{item.label}</dt>
                    <dd className="mt-1 text-sm break-words">
                      {item.files.length > 0 ? (
                        <ul className="grid gap-1">
                          {item.files.map((f) => (
                            <li key={f.id}>
                              <a
                                href={api.files.url(f.id)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 text-primary underline underline-offset-4 hover:opacity-80"
                              >
                                <Paperclip className="size-3.5 shrink-0" />
                                <span className="break-all">{f.name}</span>
                              </a>
                            </li>
                          ))}
                        </ul>
                      ) : item.isLink ? (
                        <a
                          href={item.value}
                          target="_blank"
                          rel="noopener noreferrer"
                          dir="ltr"
                          className="text-primary underline underline-offset-4 hover:opacity-80"
                        >
                          {item.value}
                        </a>
                      ) : (
                        <span className="whitespace-pre-line">{item.value}</span>
                      )}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** مواصفات المشروع حسب نوعه — ما اتفق عليه بالظبط، مرجعًا عند أي خلاف. */
function ProjectSpec({ project }: { project: Project }) {
  const items = describeDetails(
    project.project_type,
    readDetails(project.project_type, project.type_details),
  );

  return (
    <div className="surface-card p-6">
      <h2 className="font-display text-lg font-semibold">
        المواصفات المتفق عليها — {projectTypeLabel(project.project_type)}
      </h2>

      {items.length > 0 ? (
        <ul className="mt-4 grid gap-2 sm:grid-cols-2">
          {items.map((line) => (
            <li
              key={line}
              className="rounded-lg border border-border bg-surface-elevated px-3 py-2 text-sm"
            >
              {line}
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-3 text-sm text-muted-foreground">لم تُسجَّل مواصفات لهذا النوع.</p>
      )}

      {project.scope && (
        <div className="mt-4">
          <div className="text-xs text-muted-foreground">تفاصيل إضافية</div>
          <p className="mt-1 text-sm leading-7 whitespace-pre-line">{project.scope}</p>
        </div>
      )}

      {project.out_of_scope && (
        <div className="mt-4">
          <div className="text-xs text-muted-foreground">خارج النطاق</div>
          <p className="mt-1 text-sm leading-7 whitespace-pre-line text-muted-foreground">
            {project.out_of_scope}
          </p>
        </div>
      )}
    </div>
  );
}

/** ملخّص طلب مشروع لم يُعتمد بعد. */
function DraftSummary({ project, isAdmin }: { project: Project; isAdmin: boolean }) {
  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <nav className="flex items-center gap-1 text-sm text-muted-foreground">
        <Link to="/dashboard" className="hover:text-foreground">
          المشاريع
        </Link>
        <ChevronRight className="size-4 rtl-flip" />
        <span className="text-foreground">{project.name}</span>
      </nav>

      <div className="surface-card p-6">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="font-display text-2xl font-semibold">{project.name}</h1>
          <StatusPill status={project.status} label={PROJECT_STATUS_AR[project.status] ?? ""} />
        </div>

        <p className="mt-3 flex items-start gap-2 rounded-xl border border-warn/40 bg-warn/10 p-3 text-sm leading-7 text-warn-foreground">
          <Info className="mt-1 size-4 shrink-0" />
          {isAdmin
            ? "طلب جديد من العميل. أكمل بنود الميثاق ثم اعتمده لتبدأ مراحل المشروع."
            : "طلبك مسجَّل وفريق أرقام بيراجعه. مراحل المشروع تظهر هنا فور اعتماده."}
        </p>

        <dl className="mt-5 grid gap-4 sm:grid-cols-2">
          <SummaryRow label="نوع المشروع" value={projectTypeLabel(project.project_type)} />
          <SummaryRow label="العميل النهائي" value={project.end_client_name || "—"} />
          <SummaryRow label="الوكالة الشريكة" value={project.partner_agency || "—"} />
          <SummaryRow label="مسؤول المشروع" value={project.owner_name || "—"} />
        </dl>

        {isAdmin && (
          <Button asChild className="mt-6">
            <Link to="/projects/new" search={{ project: project.id }}>
              مراجعة الطلب وإكمال الميثاق
            </Link>
          </Button>
        )}
      </div>

      <ClientIntake project={project} />
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface-elevated p-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm font-medium">{value}</dd>
    </div>
  );
}

function Metric({
  label,
  value,
  tone = "default",
}: {
  label: string;
  value: string;
  tone?: "default" | "warn" | "destructive";
}) {
  return (
    <div className="rounded-xl border border-border bg-surface-elevated p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div
        className={cn(
          "num mt-1 text-sm font-semibold",
          tone === "warn" && "text-warn-foreground",
          tone === "destructive" && "text-destructive",
        )}
      >
        {value}
      </div>
    </div>
  );
}

function Banner({
  tone,
  icon: Icon,
  children,
}: {
  tone: "warn" | "destructive";
  icon: typeof AlertTriangle;
  children: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        "mt-5 flex items-start gap-3 rounded-xl border p-4 text-sm",
        tone === "warn"
          ? "border-warn/40 bg-warn/10 text-warn-foreground"
          : "border-destructive/30 bg-destructive/5 text-destructive",
      )}
    >
      <Icon className="mt-0.5 size-4 shrink-0" />
      <p className="leading-6">{children}</p>
    </div>
  );
}
