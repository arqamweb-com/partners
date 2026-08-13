import { useMemo, useState } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  Check,
  ChevronLeft,
  ChevronRight,
  Info,
  Lock,
  Paperclip,
  Plus,
  Upload,
  Zap,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { api, ACCEPTED_UPLOADS, MAX_UPLOAD_BYTES, type UploadedFile } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { useHolidays, useSettings } from "@/hooks/useSettings";
import { DEFAULT_OUT_OF_SCOPE, FAST_TRACK_TERMS, type Project } from "@/lib/domain";
import {
  DEFAULT_PROJECT_TYPE,
  PROJECT_TYPES,
  defaultDetails,
  detailEffectSummary,
  projectType,
  collectFileIds,
  fileList,
  intakeGroups,
  intakeProgress,
  isFileField,
  readDetails,
  readIntake,
  stagesForType,
  type DetailField,
  type IntakeData,
  type IntakeField,
  type TypeDetails,
} from "@/lib/project-types";
import { EmptyState } from "@/components/EmptyState";
import { addBusinessDays, businessDaysBetween, formatDateAr } from "@/lib/business-days";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";

/**
 * معالج المشروع — يعمل في وضعين:
 *
 *   إنشاء       /projects/new              الأدمن: الخطوات الأربعة.
 *                                          العميل: الأساسيات فقط والباقي مقفول،
 *                                          والإرسال يسجّل طلبًا ينتظر المراجعة.
 *   إكمال       /projects/new?project=<id> الأدمن يفتح طلب عميل فيجيله المعالج
 *                                          مليانًا، يكمّل الميثاق ثم يعتمده.
 */
export const Route = createFileRoute("/_authenticated/projects/new")({
  validateSearch: (search: Record<string, unknown>) => ({
    project: typeof search["project"] === "string" ? search["project"] : undefined,
  }),
  head: () => ({
    meta: [
      { title: "مشروع جديد | أرقام فلو" },
      {
        name: "description",
        content: "معالج إنشاء ميثاق المشروع: الأساسيات، النطاق، المدد، والمالية.",
      },
      { property: "og:title", content: "معالج مشروع جديد | أرقام فلو" },
      { property: "og:description", content: "ميثاق مشروع مكتوب يمنع الجدل لاحقًا." },
    ],
  }),
  component: NewProject,
});

const STEPS = ["الأساسيات", "بيانات المشروع", "النطاق", "المدد والجدول", "المالية"];

/**
 * الخطوات المتاحة للعميل: الأساسيات، بيانات مشروعه، ومواصفات النطاق.
 * المدد والمالية بنود تعاقدية يكتبها فريق أرقام وحده.
 */
const CLIENT_LAST_STEP = 2;

type StageRow = {
  name: string;
  gate: string | null;
  gate_size: string;
  our: number;
  their: number;
};
type Milestone = { label: string; percent: number };

/** حجم الملف بصيغة مقروءة. */
function fileSize(bytes: number): string {
  return bytes >= 1048576
    ? `${(bytes / 1048576).toFixed(1)} م.ب`
    : `${Math.max(1, Math.round(bytes / 1024))} ك.ب`;
}

/** تاريخ بصيغة YYYY-MM-DD المحلية — تُقارَن نصيًا بأمان. */
function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

const DEFAULT_MILESTONES: Milestone[] = [
  { label: "عند توقيع الميثاق", percent: 40 },
  { label: "عند Content Lock", percent: 30 },
  { label: "قبل الإطلاق", percent: 30 },
];

/** مراحل نوع المشروع بعد أثر تفاصيله، في الشكل الذي يعدّله المعالج. */
function stagesOfType(typeId: string, details: TypeDetails): StageRow[] {
  return stagesForType(typeId, details).map((s) => ({
    name: s.name,
    gate: s.gate,
    gate_size: s.gate_size,
    our: s.our,
    their: s.their,
  }));
}

/**
 * غلاف الراوت: يقرر الوضع ويحمّل الطلب قبل تركيب المعالج.
 *
 * التحميل هنا وليس داخل المعالج عشان الحالة تُهيَّأ مرة واحدة من البيانات
 * الجاهزة — بدون useEffect يعيد الكتابة فوق ما يكتبه الأدمن.
 */
function NewProject() {
  const { project: draftId } = Route.useSearch();
  const { data: me } = useCurrentUser();
  const isAdmin = !!me?.isAdmin;

  const { data: draft, isLoading } = useQuery({
    queryKey: ["project", draftId],
    queryFn: async () => {
      const data = await api.projects.get(draftId!);
      return data;
    },
    enabled: !!draftId && isAdmin,
  });

  if (!me) return <p className="py-16 text-center text-muted-foreground">جارٍ التحميل…</p>;

  if (draftId && !isAdmin) {
    return (
      <EmptyState
        title="مراجعة الطلبات لفريق أرقام"
        hint="طلبك مسجَّل وفريق أرقام بيراجعه. هيتواصلوا معك فور اعتماده."
      />
    );
  }

  if (draftId && isLoading) {
    return <p className="py-16 text-center text-muted-foreground">جارٍ تحميل الطلب…</p>;
  }

  if (draftId && !draft) {
    return <EmptyState title="الطلب غير موجود" hint="ربما حُذف أو أن الرابط غير صحيح." />;
  }

  return <ProjectWizard key={draft?.id ?? "new"} initial={draft ?? null} isAdmin={isAdmin} />;
}

function ProjectWizard({ initial, isAdmin }: { initial: Project | null; isAdmin: boolean }) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();
  const { data: settings } = useSettings();
  const { data: holidays = [] } = useHolidays();

  /** وضع الإكمال: الأدمن بيراجع طلب عميل قائم بدل ما ينشئ مشروعًا جديدًا. */
  const completing = !!initial;
  /** العميل يملأ الأساسيات فقط، وباقي الميثاق يكتبه فريق أرقام. */
  const basicsOnly = !isAdmin;
  const lastStep = basicsOnly ? CLIENT_LAST_STEP : STEPS.length - 1;

  // الأدمن يبدأ من النطاق لأن العميل ملأ الأساسيات وبيانات مشروعه بالفعل
  const [step, setStep] = useState(completing ? 2 : 0);

  /**
   * الانتقال بين الخطوات يرجّع الصفحة لأولها — الخطوات طويلة، ومن غير ده
   * تفتح الخطوة الجديدة من منتصفها أو من آخرها فيبان الزرار وحده.
   */
  function goToStep(next: number) {
    setStep(next);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const [basics, setBasics] = useState({
    name: initial?.name ?? "",
    end_client_name: initial?.end_client_name ?? "",
    partner_agency: initial?.partner_agency ?? "",
    owner_name: initial?.owner_name ?? "",
    project_type: initial?.project_type ?? DEFAULT_PROJECT_TYPE,
    fast_track: initial?.track === "fast_track",
  });

  const [scope, setScope] = useState(initial?.scope ?? "");
  /**
   * قائمة الاستثناءات: المحفوظ يحمل البنود المستثناة فقط، فالبند القياسي
   * الغائب عنه يعني أن العميل اعتبره داخل النطاق. نعرضه مطفأً لا نحذفه —
   * وإلا اختفى قراره من أمام فريق أرقام وقت المراجعة.
   */
  const [outOfScope, setOutOfScope] = useState(() => {
    if (!initial) return DEFAULT_OUT_OF_SCOPE.map((label) => ({ label, excluded: true }));
    const saved = (initial.out_of_scope ?? "").split("\n").filter(Boolean);
    return [
      ...DEFAULT_OUT_OF_SCOPE.map((label) => ({ label, excluded: saved.includes(label) })),
      ...saved
        .filter((label) => !DEFAULT_OUT_OF_SCOPE.includes(label))
        .map((label) => ({ label, excluded: true })),
    ];
  });
  const [extraOut, setExtraOut] = useState("");

  // بيانات ومرفقات العميل
  const [intake, setIntake] = useState<IntakeData>(() =>
    readIntake(initial?.project_type ?? DEFAULT_PROJECT_TYPE, initial?.intake_data),
  );

  // تفاصيل النوع: تُقرأ من المشروع وتُكمَّل بالقيم الافتراضية
  const [details, setDetails] = useState<TypeDetails>(() =>
    readDetails(initial?.project_type ?? DEFAULT_PROJECT_TYPE, initial?.type_details),
  );

  // المراحل مصدرها قالب النوع + أثر التفاصيل — طلب العميل ما بيحملش مراحل.
  // الأدمن يقدر يعدّل المدد يدويًا بعد كده في خطوة المدد.
  const [stages, setStages] = useState<StageRow[]>(() =>
    stagesOfType(initial?.project_type ?? DEFAULT_PROJECT_TYPE, details),
  );
  const [schedule, setSchedule] = useState({
    delivery: initial?.original_delivery_date ?? "",
    rounds: initial?.revision_rounds_allowed ?? 2,
    warranty: initial?.warranty_days ?? 14,
  });

  // payment_milestones عمود JSON — ممكن يرجع null أو قيمة غير متوقعة
  const [milestones, setMilestones] = useState<Milestone[]>(() =>
    Array.isArray(initial?.payment_milestones) && initial.payment_milestones.length > 0
      ? (initial.payment_milestones as unknown as Milestone[])
      : DEFAULT_MILESTONES,
  );

  const type = projectType(basics.project_type);
  const detailEffects = useMemo(
    () => detailEffectSummary(basics.project_type, details, { includeDays: isAdmin }),
    [basics.project_type, details, isAdmin],
  );
  const intakeDone = useMemo(
    () => intakeProgress(basics.project_type, intake),
    [basics.project_type, intake],
  );

  /** تغيير النوع يعيد بناء تفاصيله ومراحله من قالبه. */
  function changeType(id: string) {
    const fresh = defaultDetails(id);
    setBasics((b) => ({ ...b, project_type: id }));
    setDetails(fresh);
    setStages(stagesOfType(id, fresh));
    setIntake((prev) => readIntake(id, prev));
  }

  /** تغيير تفصيلة يعيد حساب المدد — والأدمن يقدر يعدّلها يدويًا بعدها. */
  function changeDetail(key: string, value: number | boolean | string) {
    const next = { ...details, [key]: value };
    setDetails(next);
    setStages(stagesOfType(basics.project_type, next));
  }

  const factor = basics.fast_track ? 0.6 : 1;
  const totalDays = useMemo(
    () => stages.reduce((a, s) => a + Math.ceil((s.our + s.their) * factor), 0),
    [stages, factor],
  );
  const computedDelivery = useMemo(
    () => addBusinessDays(new Date(), totalDays, holidays),
    [totalDays, holidays],
  );
  /**
   * تاريخ التسليم يُحسب تلقائيًا من مجموع مدد المراحل بأيام العمل، وهو الحد
   * الأدنى — فريق أرقام يقدر يأخّره فقط، لأن أي تاريخ أبكر من المدد المتفق
   * عليها التزام لا يمكن الوفاء به.
   *
   * التقييد هنا اشتقاق لا حالة منفصلة: لو الأدمن اختار تاريخًا ثم زادت المدد
   * حتى تجاوزته، يتحرك التاريخ للأمام تلقائيًا بدل ما يفضل قديمًا وغلط.
   */
  const minDelivery = isoDate(computedDelivery);
  const deliveryDate =
    schedule.delivery && schedule.delivery > minDelivery ? schedule.delivery : minDelivery;
  const deliveryDeferred = deliveryDate > minDelivery;
  const percentTotal = milestones.reduce((a, m) => a + m.percent, 0);

  /**
   * يربط الملفات المرفوعة بالمشروع بعد حفظه.
   * الفشل هنا لا يُسقط الحفظ — الملفات تبقى مرفوعة ويمكن ربطها بإعادة الحفظ.
   */
  async function claimFiles(projectId: string) {
    const ids = collectFileIds(basics.project_type, intake);
    if (ids.length === 0) return;
    await api.files.claim(projectId, ids).catch(() => undefined);
  }

  /** البنود المستثناة كما تُحفظ: سطر لكل بند مُفعّل. */
  function excludedList() {
    return outOfScope
      .filter((o) => o.excluded)
      .map((o) => o.label)
      .join("\n");
  }

  /** بنود الميثاق التي يكتبها فريق أرقام (الخطوات 1-3). */
  /** ما يملكه أي صاحب طلب: البيانات والمواصفات. */
  function basicFields() {
    return {
      name: basics.name.trim().slice(0, 200),
      end_client_name: basics.end_client_name.trim().slice(0, 200),
      partner_agency: basics.partner_agency.trim().slice(0, 200),
      owner_name: basics.owner_name.trim().slice(0, 200) || (me?.fullName ?? ""),
      scope: scope.trim().slice(0, 8000),
      out_of_scope: excludedList(),
      type_details: details,
      intake_data: intake,
    };
  }

  /** البنود التعاقدية — مسار مستقل لأن صلاحيته للتسعير وحده. */
  function charterFields() {
    return {
      track: basics.fast_track ? ("fast_track" as const) : ("normal" as const),
      original_delivery_date: deliveryDate,
      revision_rounds_allowed: basics.fast_track ? 1 : schedule.rounds,
      warranty_days: schedule.warranty,
      payment_milestones: milestones,
    };
  }

  /** المراحل بعد تعديل المعالج، بأسماء أعمدة السيرفر. */
  function stagePlan() {
    return stages.map((s) => ({
      name: s.name,
      gate_name: s.gate,
      gate_size: s.gate_size,
      our_duration_days: s.our,
      their_duration_days: s.their,
    }));
  }

  const create = useMutation({
    mutationFn: async () => {
      if (basics.name.trim().length < 3) throw new Error("اسم المشروع مطلوب.");

      // ---- مسار العميل: تسجيل طلب بالأساسيات فقط -------------------------
      // السيرفر يجعله مسودة ولا يقبل منه مدة ولا تسعيرًا أصلًا
      if (basicsOnly) {
        const project = await api.projects.create({
          ...basicFields(),
          project_type: basics.project_type,
        });

        await claimFiles(project.id);
        return { id: project.id, submitted: true };
      }

      // ---- مسار فريق أرقام ------------------------------------------------
      let projectId: string;

      if (completing) {
        projectId = initial.id;
        await api.projects.update(projectId, basicFields());
      } else {
        const project = await api.projects.create({
          ...basicFields(),
          project_type: basics.project_type,
        });
        projectId = project.id;
      }

      // الميثاق ثم الاعتماد: الاعتماد يبذر المراحل والقوائم في معاملة
      // واحدة بالسيرفر، فلا يبقى مشروع نصف مبذور لو انقطع الاتصال
      await api.projects.updateCharter(projectId, charterFields());
      await claimFiles(projectId);
      await api.projects.approve(projectId, stagePlan());

      return { id: projectId, submitted: false };
    },
    onSuccess: ({ id, submitted }) => {
      qc.invalidateQueries();
      toast.success(
        submitted
          ? "تم إرسال طلبك. فريق أرقام هيراجعه ويتواصل معك."
          : completing
            ? "اعتُمد الطلب وبُذرت المراحل والقوائم."
            : "أُنشئ المشروع وبُذرت المراحل والقوائم.",
      );
      navigate({ to: "/projects/$projectId", params: { projectId: id } });
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر حفظ المشروع."),
  });

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="font-display text-2xl font-semibold">
          {completing ? "مراجعة طلب مشروع" : basicsOnly ? "طلب مشروع جديد" : "معالج مشروع جديد"}
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          {completing
            ? "أكمل بنود الميثاق ثم اعتمد الطلب لتبدأ مراحل المشروع."
            : basicsOnly
              ? "سجّل بيانات مشروعك الأساسية ونوعه. باقي بنود الميثاق يكتبها فريق أرقام بعد المراجعة."
              : "كل خطوة هنا تكتب بندًا في ميثاق المشروع — وهو المرجع عند أي خلاف لاحق."}
        </p>
      </div>

      <ol className="flex gap-2">
        {STEPS.map((s, i) => {
          // الخطوات التعاقدية مقفولة على العميل — يشوفها ليعرف المسار كاملًا
          const locked = basicsOnly && i > CLIENT_LAST_STEP;
          return (
            <li key={s} className="flex-1">
              <div
                aria-disabled={locked}
                title={locked ? "يكملها فريق أرقام بعد مراجعة الطلب" : undefined}
                className={cn(
                  "flex items-center justify-center gap-1.5 rounded-xl border p-3 text-center text-xs",
                  locked && "border-dashed border-border/70 bg-muted/40 text-muted-foreground",
                  !locked && i === step && "border-primary bg-primary/8 font-medium text-primary",
                  !locked && i < step && "border-border bg-surface-elevated text-muted-foreground",
                  !locked && i > step && "border-dashed border-border/70 text-muted-foreground",
                )}
              >
                {locked && <Lock className="size-3 shrink-0" />}
                <span>
                  <span className="num">{i + 1}</span>. {s}
                </span>
              </div>
            </li>
          );
        })}
      </ol>

      <div className="surface-card p-6">
        {step === 0 && (
          <div className="grid gap-4">
            <Field
              label="اسم المشروع"
              value={basics.name}
              onChange={(v) => setBasics({ ...basics, name: v })}
            />
            <Field
              label="العميل النهائي"
              value={basics.end_client_name}
              onChange={(v) => setBasics({ ...basics, end_client_name: v })}
            />
            <Field
              label="الوكالة الشريكة"
              value={basics.partner_agency}
              onChange={(v) => setBasics({ ...basics, partner_agency: v })}
            />
            <Field
              label="مسؤول المشروع"
              value={basics.owner_name}
              onChange={(v) => setBasics({ ...basics, owner_name: v })}
            />

            {/* نوع المشروع يحدد مراحله وقوائم الوصول والمحتوى المطلوبة */}
            <div className="grid gap-2">
              <Label>نوع المشروع</Label>
              <div className="grid gap-2 sm:grid-cols-2">
                {PROJECT_TYPES.map((t) => {
                  const selected = basics.project_type === t.id;
                  return (
                    <button
                      key={t.id}
                      type="button"
                      onClick={() => changeType(t.id)}
                      aria-pressed={selected}
                      className={cn(
                        "rounded-xl border p-3 text-start transition-colors",
                        selected
                          ? "border-primary bg-primary/8"
                          : "border-border hover:bg-accent/60",
                      )}
                    >
                      <div className="flex items-center gap-2 text-sm font-medium">
                        {selected && <Check className="size-3.5 text-primary" />}
                        {t.label}
                      </div>
                      <p className="mt-1 text-xs leading-6 text-muted-foreground">
                        {t.description}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        <Num value={t.stages.length} /> مراحل
                      </p>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* المسار السريع قرار تجاري — يحدده فريق أرقام وحده */}
            {me?.isAdmin && (
              <div className="flex items-center justify-between rounded-xl border border-border p-4">
                <div>
                  <div className="flex items-center gap-2 text-sm font-medium">
                    <Zap className="size-4 text-warn" /> المسار السريع (Fast-Track)
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    مسار مضغوط بشروط ملزمة قبل الاختيار.
                  </p>
                </div>
                <Switch
                  checked={basics.fast_track}
                  onCheckedChange={(v) => setBasics({ ...basics, fast_track: v })}
                />
              </div>
            )}

            {basics.fast_track && (
              <ul className="space-y-2 rounded-xl border border-warn/40 bg-warn/10 p-4 text-xs leading-6 text-warn-foreground">
                {FAST_TRACK_TERMS.map((t) => (
                  <li key={t} className="flex gap-2">
                    <AlertTriangle className="mt-1 size-3 shrink-0" />
                    {t}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {/* بيانات ومرفقات العميل — الحقول المشتركة + الخاصة بالنوع */}
        {step === 1 && (
          <div className="grid gap-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <h2 className="font-display text-lg font-semibold">بيانات {type.label} ومرفقاته</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  سجّل المتاح عندك دلوقتي — كل الحقول اختيارية، وأي ناقص هيتابعه فريق أرقام معك في
                  قائمة المحتوى.
                </p>
              </div>
              <span className="rounded-full bg-secondary px-3 py-1 text-xs font-medium">
                <Num value={intakeDone.filled} /> من <Num value={intakeDone.total} />
              </span>
            </div>

            {intakeGroups(basics.project_type).map((g) => (
              <div key={g.group} className="grid gap-3">
                <Label className="text-sm font-semibold text-primary">{g.group}</Label>
                <div className="grid gap-3 sm:grid-cols-2">
                  {g.fields.map((f) => (
                    <IntakeFieldInput
                      key={f.key}
                      field={f}
                      value={intake[f.key]}
                      onChange={(v) => setIntake((d) => ({ ...d, [f.key]: v }))}
                    />
                  ))}
                </div>
              </div>
            ))}

            <p className="flex items-start gap-2 rounded-xl border border-border bg-surface-elevated p-3 text-xs leading-6 text-muted-foreground">
              <Info className="mt-0.5 size-3.5 shrink-0" />
              روابط الدرايف: تأكد أن صلاحية الاطلاع مفتوحة لأي شخص لديه الرابط، وإلا لن يتمكن فريق
              أرقام من فتحها.
            </p>
          </div>
        )}

        {step === 2 && (
          <div className="grid gap-5">
            {/* حقول النطاق تتغير حسب نوع المشروع — تعريفها في project-types.ts */}
            <div className="grid gap-3">
              <div>
                <Label>{type.label} — مواصفات المشروع</Label>
                <p className="mt-1 text-xs text-muted-foreground">
                  {isAdmin
                    ? "إجاباتك هنا تحدّد قائمتي المحتوى والوصول ومدد المراحل."
                    : "حدّد المطلوب بدقة — ده أساس التسعير وخطة التنفيذ، وأي إضافة بعد الاعتماد تبقى طلب تغيير."}
                </p>
              </div>

              <div className="grid gap-3 sm:grid-cols-2">
                {type.detailFields.map((f) => (
                  <DetailFieldInput
                    key={f.key}
                    field={f}
                    value={details[f.key]}
                    onChange={(v) => changeDetail(f.key, v)}
                  />
                ))}
              </div>

              {detailEffects.length > 0 && (
                <ul className="grid gap-1 rounded-xl border border-primary/30 bg-primary/5 p-3 text-xs leading-6">
                  <li className="font-medium text-primary">
                    {isAdmin ? "أثر اختياراتك" : "المطلوب منك بناءً على اختياراتك"}
                  </li>
                  {detailEffects.map((e) => (
                    <li key={e} className="text-muted-foreground">
                      • {e}
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="grid gap-1.5">
              <Label htmlFor="scope">تفاصيل إضافية على النطاق</Label>
              <Textarea
                id="scope"
                dir="rtl"
                rows={4}
                maxLength={8000}
                value={scope}
                onChange={(e) => setScope(e.target.value)}
                placeholder="أي بند متفق عليه غير مغطّى بالحقول أعلاه…"
              />
            </div>

            {/* الاستثناءات مفتوحة للطرفين: العميل يطلبها في طلبه وفريق أرقام
                يثبّتها عند المراجعة — الميثاق يُكتب على ما استقرّ عليه الاثنان */}
            <div>
              <Label>خارج النطاق</Label>
              <p className="mt-1 text-xs text-muted-foreground">
                {isAdmin
                  ? "كل بند مُفعّل هنا مستثنى صراحة من السعر والمدة. أطفئه إذا كان داخل النطاق فعلًا."
                  : "كل بند مُفعّل هنا مستثنى من السعر والمدة. أطفئ ما تريده داخل النطاق أو أضف بندًا — وفريق أرقام يراجعه ويسعّره قبل الاعتماد."}
              </p>
              <ul className="mt-3 space-y-2">
                {outOfScope.map((o, i) => (
                  <li
                    key={`${o.label}-${i}`}
                    className="flex items-center justify-between gap-3 rounded-xl border border-border/70 p-3 text-sm"
                  >
                    <span>{o.label}</span>
                    <div className="flex items-center gap-3">
                      <span className="text-xs text-muted-foreground">
                        {o.excluded ? "خارج النطاق" : "داخل النطاق"}
                      </span>
                      <Switch
                        checked={o.excluded}
                        onCheckedChange={(v) =>
                          setOutOfScope((list) =>
                            list.map((x, xi) => (xi === i ? { ...x, excluded: v } : x)),
                          )
                        }
                      />
                      <button
                        type="button"
                        aria-label="حذف البند"
                        onClick={() => setOutOfScope((l) => l.filter((_, xi) => xi !== i))}
                        className="text-muted-foreground hover:text-destructive"
                      >
                        <X className="size-4" />
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
              <div className="mt-3 flex gap-2">
                <Input
                  dir="rtl"
                  maxLength={200}
                  placeholder="بند إضافي خارج النطاق…"
                  value={extraOut}
                  onChange={(e) => setExtraOut(e.target.value)}
                />
                <Button
                  variant="secondary"
                  onClick={() => {
                    if (!extraOut.trim()) return;
                    setOutOfScope((l) => [...l, { label: extraOut.trim(), excluded: true }]);
                    setExtraOut("");
                  }}
                >
                  <Plus className="size-4" /> إضافة
                </Button>
              </div>
            </div>
          </div>
        )}

        {step === 3 && (
          <div className="grid gap-5">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-xs text-muted-foreground">
                    <th className="px-2 py-2 text-start font-medium">المرحلة</th>
                    <th className="px-2 py-2 text-start font-medium">البوابة</th>
                    <th className="px-2 py-2 text-start font-medium">مدة أرقام ويب للتنفيذ</th>
                    <th className="px-2 py-2 text-start font-medium">مدة العميل للمراجعة</th>
                  </tr>
                </thead>
                <tbody>
                  {stages.map((s, i) => (
                    <tr key={s.name} className="border-b border-border/60 last:border-0">
                      <td className="px-2 py-2">{s.name}</td>
                      <td className="px-2 py-2 text-xs text-muted-foreground">{s.gate ?? "—"}</td>
                      <td className="px-2 py-2">
                        <Input
                          type="number"
                          min={0}
                          className="num h-8 w-20"
                          value={s.our}
                          onChange={(e) =>
                            setStages((l) =>
                              l.map((x, xi) =>
                                xi === i
                                  ? { ...x, our: Math.max(0, Number(e.target.value) || 0) }
                                  : x,
                              ),
                            )
                          }
                        />
                      </td>
                      <td className="px-2 py-2">
                        <Input
                          type="number"
                          min={0}
                          className="num h-8 w-20"
                          value={s.their}
                          onChange={(e) =>
                            setStages((l) =>
                              l.map((x, xi) =>
                                xi === i
                                  ? { ...x, their: Math.max(0, Number(e.target.value) || 0) }
                                  : x,
                              ),
                            )
                          }
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="rounded-xl border border-border bg-surface-elevated p-4 text-sm">
              الإجمالي المحسوب: <Num value={totalDays} /> يوم عمل
              {basics.fast_track && (
                <span className="text-muted-foreground"> (بعد ضغط المسار السريع)</span>
              )}{" "}
              — أقرب تسليم واقعي {formatDateAr(computedDelivery)}.
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <div className="grid gap-1.5">
                <Label htmlFor="delivery">تاريخ التسليم الأصلي</Label>
                <Input
                  id="delivery"
                  type="date"
                  className="num"
                  min={minDelivery}
                  value={deliveryDate}
                  onChange={(e) => setSchedule({ ...schedule, delivery: e.target.value })}
                />
                <p className="text-xs leading-6 text-muted-foreground">
                  {deliveryDeferred
                    ? `مؤخَّر ${businessDaysBetween(computedDelivery, new Date(`${deliveryDate}T00:00:00`), holidays)} يوم عمل عن أقرب تسليم واقعي.`
                    : "محسوب تلقائيًا من مدد المراحل. يمكن تأخيره فقط."}
                </p>
              </div>
              {/* جولات التعديل والضمان بنود تعاقدية — يضبطها فريق أرقام */}
              {me?.isAdmin && (
                <>
                  <div className="grid gap-1.5">
                    <Label htmlFor="rounds">جولات التعديل</Label>
                    <Input
                      id="rounds"
                      type="number"
                      min={1}
                      className="num"
                      value={basics.fast_track ? 1 : schedule.rounds}
                      disabled={basics.fast_track}
                      onChange={(e) =>
                        setSchedule({
                          ...schedule,
                          rounds: Math.max(1, Number(e.target.value) || 1),
                        })
                      }
                    />
                  </div>
                  <div className="grid gap-1.5">
                    <Label htmlFor="warranty">أيام الضمان</Label>
                    <Input
                      id="warranty"
                      type="number"
                      min={0}
                      className="num"
                      value={schedule.warranty}
                      onChange={(e) =>
                        setSchedule({
                          ...schedule,
                          warranty: Math.max(0, Number(e.target.value) || 0),
                        })
                      }
                    />
                  </div>
                </>
              )}
            </div>

            <p className="flex items-start gap-2 rounded-xl border border-border bg-surface-elevated p-3 text-xs leading-6 text-muted-foreground">
              <Info className="mt-0.5 size-3.5 shrink-0" />
              أقرب تسليم واقعي حسب المدد أعلاه هو {formatDateAr(computedDelivery)}. لتقديمه أكثر
              يلزم تقليل مدد المراحل نفسها — الالتزام بتاريخ أبكر من المدد وعد غير قابل للتحقق.
            </p>

            <p className="text-xs text-muted-foreground">
              الافتراضيات مأخوذة من الإعدادات العامة (الضمان{" "}
              <Num value={settings?.warranty_days ?? 14} /> يومًا) ويمكن تعديلها هنا لكل مشروع.
            </p>
          </div>
        )}

        {step === 4 && (
          <div className="grid gap-4">
            <Label>دفعات السداد</Label>
            <ul className="space-y-2">
              {milestones.map((m, i) => (
                <li key={i} className="flex items-center gap-2">
                  <Input
                    dir="rtl"
                    maxLength={120}
                    value={m.label}
                    onChange={(e) =>
                      setMilestones((l) =>
                        l.map((x, xi) => (xi === i ? { ...x, label: e.target.value } : x)),
                      )
                    }
                  />
                  <Input
                    type="number"
                    min={0}
                    max={100}
                    className="num w-24"
                    value={m.percent}
                    onChange={(e) =>
                      setMilestones((l) =>
                        l.map((x, xi) =>
                          xi === i
                            ? { ...x, percent: Math.max(0, Number(e.target.value) || 0) }
                            : x,
                        ),
                      )
                    }
                  />
                  <button
                    type="button"
                    aria-label="حذف الدفعة"
                    onClick={() => setMilestones((l) => l.filter((_, xi) => xi !== i))}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <X className="size-4" />
                  </button>
                </li>
              ))}
            </ul>
            <Button
              variant="secondary"
              className="justify-self-start"
              onClick={() => setMilestones((l) => [...l, { label: "دفعة إضافية", percent: 0 }])}
            >
              <Plus className="size-4" /> إضافة دفعة
            </Button>

            <div
              className={cn(
                "rounded-xl border p-3 text-xs",
                percentTotal === 100
                  ? "border-border bg-surface-elevated"
                  : "border-warn/40 bg-warn/10",
              )}
            >
              مجموع النسب: <Num value={percentTotal} suffix="%" />
              {percentTotal !== 100 && " — يجب أن يساوي 100%."}
            </div>

            <p className="rounded-xl border border-border bg-surface-elevated p-4 text-xs leading-6 text-muted-foreground">
              لماذا الدفعة الثانية مرتبطة بـ Content Lock وليس Design Lock؟ لأن ربطها بالتصميم يكافئ
              العميل على تأخير الاعتماد: كلما أجّل اعتماد التصميم، أجّل الدفع. ربطها باكتمال المحتوى
              يجعل الدفعة مستحقة عند التزامٍ يقع في يد العميل نفسه.
            </p>
          </div>
        )}
      </div>

      {basicsOnly && (
        <p className="flex items-start gap-2 rounded-xl border border-border bg-surface-elevated p-3 text-xs leading-6 text-muted-foreground">
          <Lock className="mt-0.5 size-3.5 shrink-0" />
          المدد والمالية بنود تعاقدية يكتبها فريق أرقام. النطاق اللي بتكتبه هنا هو أساس المراجعة —
          بعد إرسال طلبك هيراجعوه ويكملوا الميثاق ويتواصلوا معك.
        </p>
      )}

      <div className="flex items-center justify-between">
        <Button variant="ghost" disabled={step === 0} onClick={() => goToStep(step - 1)}>
          <ChevronRight className="size-4" /> السابق
        </Button>
        {step < lastStep ? (
          <Button onClick={() => goToStep(step + 1)}>
            التالي <ChevronLeft className="size-4" />
          </Button>
        ) : (
          <Button onClick={() => create.mutate()} disabled={create.isPending}>
            <Check className="size-4" />
            {basicsOnly
              ? "إرسال الطلب"
              : completing
                ? "اعتماد الطلب وبدء المشروع"
                : "إنشاء المشروع وبذر المراحل"}
          </Button>
        )}
      </div>
    </div>
  );
}

/**
 * حقل رفع ملفات — الرفع فوري على الاستضافة، والملف يبقى معلّقًا باسم من
 * رفعه حتى يُحفظ الطلب فيُربط بالمشروع.
 */
function FileField({
  field,
  files,
  onChange,
}: {
  field: IntakeField;
  files: UploadedFile[];
  onChange: (files: UploadedFile[]) => void;
}) {
  const [busy, setBusy] = useState(false);
  const multiple = field.type === "files";
  const id = `intake-${field.key}`;

  async function handlePick(list: FileList | null) {
    if (!list || list.length === 0) return;
    setBusy(true);
    const uploaded: UploadedFile[] = [];

    for (const file of Array.from(list)) {
      try {
        uploaded.push(await api.files.upload(file));
      } catch (e) {
        toast.error(e instanceof Error ? e.message : `تعذّر رفع «${file.name}».`);
      }
    }

    if (uploaded.length > 0) {
      onChange(multiple ? [...files, ...uploaded] : uploaded.slice(-1));
      toast.success(`رُفع ${uploaded.length} ملف.`);
    }
    setBusy(false);
  }

  async function remove(file: UploadedFile) {
    onChange(files.filter((f) => f.id !== file.id));
    // الحذف من السيرفر لا يوقف العمل لو فشل — الملف يبقى غير مرتبط بأي مشروع
    await api.files.remove(file.id).catch(() => undefined);
  }

  return (
    <div className="grid gap-2 rounded-xl border border-border p-3 sm:col-span-2">
      <Label htmlFor={id} className="text-sm font-medium">
        {field.label}
      </Label>
      {field.hint && <p className="text-xs leading-6 text-muted-foreground">{field.hint}</p>}

      {files.length > 0 && (
        <ul className="grid gap-1.5">
          {files.map((f) => (
            <li
              key={f.id}
              className="flex items-center justify-between gap-3 rounded-lg bg-surface-elevated px-3 py-2"
            >
              <span className="flex min-w-0 items-center gap-2 text-sm">
                <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
                <span className="truncate">{f.name}</span>
                <span className="shrink-0 text-xs text-muted-foreground">{fileSize(f.size)}</span>
              </span>
              <button
                type="button"
                aria-label={`حذف ${f.name}`}
                onClick={() => remove(f)}
                className="text-muted-foreground hover:text-destructive"
              >
                <X className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <input
          id={id}
          type="file"
          multiple={multiple}
          accept={ACCEPTED_UPLOADS}
          disabled={busy}
          className="hidden"
          onChange={(e) => {
            void handlePick(e.target.files);
            e.target.value = "";
          }}
        />
        <Button type="button" variant="secondary" size="sm" disabled={busy} asChild={!busy}>
          {busy ? (
            <span>جارٍ الرفع…</span>
          ) : (
            <label htmlFor={id} className="cursor-pointer">
              <Upload className="size-4" />
              {files.length > 0 && !multiple ? "استبدال الملف" : "اختر ملفًا"}
            </label>
          )}
        </Button>
        <span className="text-xs text-muted-foreground">
          حتى 8 ميجابايت — PDF، وورد، إكسل، صور، ZIP، RAR
        </span>
      </div>
    </div>
  );
}

/** حقل واحد من نموذج بيانات العميل. */
function IntakeFieldInput({
  field,
  value,
  onChange,
}: {
  field: IntakeField;
  value: string | boolean | UploadedFile[] | undefined;
  onChange: (v: string | boolean | UploadedFile[]) => void;
}) {
  const id = `intake-${field.key}`;

  if (isFileField(field)) {
    return (
      <FileField field={field} files={fileList(value)} onChange={(files) => onChange(files)} />
    );
  }

  if (field.type === "boolean") {
    return (
      <div className="flex items-start justify-between gap-3 rounded-xl border border-border p-3">
        <div className="min-w-0">
          <Label htmlFor={id} className="text-sm font-medium">
            {field.label}
          </Label>
          {field.hint && (
            <p className="mt-1 text-xs leading-6 text-muted-foreground">{field.hint}</p>
          )}
        </div>
        <Switch id={id} checked={value === true} onCheckedChange={onChange} />
      </div>
    );
  }

  const isLtr = field.type === "url" || field.type === "email" || field.type === "tel";

  return (
    <div
      className={cn(
        "grid gap-1.5 rounded-xl border border-border p-3",
        field.type === "textarea" && "sm:col-span-2",
      )}
    >
      <Label htmlFor={id} className="text-sm font-medium">
        {field.label}
      </Label>

      {field.type === "textarea" ? (
        <Textarea
          id={id}
          dir="rtl"
          rows={3}
          maxLength={4000}
          value={String(value ?? "")}
          placeholder={field.placeholder ?? ""}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <Input
          id={id}
          type={field.type === "url" ? "url" : field.type === "email" ? "email" : "text"}
          dir={isLtr ? "ltr" : "rtl"}
          maxLength={500}
          value={String(value ?? "")}
          placeholder={field.placeholder ?? ""}
          onChange={(e) => onChange(e.target.value)}
        />
      )}

      {field.hint && <p className="text-xs leading-6 text-muted-foreground">{field.hint}</p>}
    </div>
  );
}

/** حقل واحد من حقول تفاصيل النوع — شكله حسب نوع الحقل. */
function DetailFieldInput({
  field,
  value,
  onChange,
}: {
  field: DetailField;
  value: number | boolean | string | undefined;
  onChange: (v: number | boolean | string) => void;
}) {
  const id = `detail-${field.key}`;

  if (field.type === "boolean") {
    return (
      <div className="flex items-start justify-between gap-3 rounded-xl border border-border p-3">
        <div className="min-w-0">
          <Label htmlFor={id} className="text-sm font-medium">
            {field.label}
          </Label>
          {field.hint && (
            <p className="mt-1 text-xs leading-6 text-muted-foreground">{field.hint}</p>
          )}
        </div>
        <Switch id={id} checked={value === true} onCheckedChange={onChange} />
      </div>
    );
  }

  return (
    <div className="grid gap-1.5 rounded-xl border border-border p-3">
      <Label htmlFor={id} className="text-sm font-medium">
        {field.label}
      </Label>

      {field.type === "number" && (
        <Input
          id={id}
          type="number"
          min={0}
          className="num"
          value={Number(value ?? 0)}
          onChange={(e) => onChange(Math.max(0, Number(e.target.value) || 0))}
        />
      )}

      {field.type === "select" && (
        <select
          id={id}
          className="h-9 rounded-md border border-input bg-background px-3 text-sm"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        >
          {field.options?.map((o) => (
            <option key={o} value={o}>
              {o}
            </option>
          ))}
        </select>
      )}

      {field.type === "text" && (
        <Input
          id={id}
          dir="rtl"
          maxLength={300}
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
        />
      )}

      {field.hint && <p className="text-xs leading-6 text-muted-foreground">{field.hint}</p>}
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="grid gap-1.5">
      <Label>{label}</Label>
      <Input dir="rtl" maxLength={500} value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}
