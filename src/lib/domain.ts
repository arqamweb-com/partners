import type { Tables } from "@/lib/db-types";

export type Project = Tables<"projects">;
export type Stage = Tables<"stages">;
export type AuditEntry = Tables<"audit_log">;
export type ContentItem = Tables<"content_items">;
export type FeedbackRound = Tables<"feedback_rounds">;
export type FeedbackItem = Tables<"feedback_items">;
export type ChangeRequest = Tables<"change_requests">;
export type AppSettings = Tables<"app_settings">;
export type PriceItem = Tables<"cr_price_items">;
export type AccessItem = Tables<"access_items">;
export type ProjectMember = Tables<"project_members">;

export const PROJECT_STATUS_AR: Record<string, string> = {
  draft: "طلب قيد المراجعة",
  active: "نشط",
  awaiting_client: "في انتظار العميل",
  frozen: "مجمّد",
  completed: "مكتمل",
  stopped: "متوقف",
};

export const STAGE_STATUS_AR: Record<string, string> = {
  pending: "لم تبدأ",
  active: "جارية",
  awaiting_approval: "بانتظار الاعتماد",
  locked: "مقفولة",
  frozen: "مجمّدة",
};

/**
 * الطرف الذي عليه الدور.
 *
 * كانت «عندنا»، وهي صحيحة لمن يقرأها من فريق أرقام وخاطئة لمن يقرأها من
 * العميل — والشاشة واحدة للاثنين. الطرف يُسمّى باسمه.
 */
export const BALL_AR: Record<string, string> = {
  us: "عند فريق أرقام ويب",
  them: "عند العميل",
};

/** اسم مرحلة الضمان — تُعامَل معاملة خاصة في العرض. */
export const WARRANTY_STAGE = "الضمان";

export function isWarrantyStage(stage: Pick<Stage, "name"> | null | undefined): boolean {
  return stage?.name === WARRANTY_STAGE;
}

/**
 * نص «الكرة عند مين».
 *
 * الضمان ليس دورًا على أحد — لا فريق أرقام مطالَب بمخرَج ولا العميل مطالَب
 * باعتماد — فالعرض يقول «فترة الضمان». وبعد إقفالها لا تبقى مرحلة جارية
 * أصلًا، فالمشروع «مكتمل».
 */
export function ballLabel(stage: Pick<Stage, "name" | "ball_in_court"> | null | undefined): string {
  if (!stage) return "مكتمل";
  if (isWarrantyStage(stage)) return "فترة الضمان";
  return BALL_AR[stage.ball_in_court] ?? "";
}

/**
 * حالة المشروع كما تُعرض — مشتقّة من المراحل لا من عمود الحالة وحده.
 *
 * الحالات الإدارية (طلب، مجمّد، متوقف) تسبق كل شيء. غير ذلك: المشروع في
 * الضمان يعني أن التسليم تم فعلًا، وبعد انتهاء الضمان يكون مكتملًا.
 */
export function projectStatusView(
  project: Pick<Project, "status">,
  stage: Pick<Stage, "name"> | null | undefined,
  hasStages = true,
): { key: string; label: string } {
  const raw = { key: project.status, label: PROJECT_STATUS_AR[project.status] ?? project.status };
  if (project.status !== "active" && project.status !== "awaiting_client") return raw;
  if (isWarrantyStage(stage)) return { key: "completed", label: "تم التسليم" };
  if (!stage && hasStages) return { key: "completed", label: PROJECT_STATUS_AR["completed"]! };
  return raw;
}

export const TRACK_AR: Record<string, string> = {
  normal: "مسار عادي",
  fast_track: "مسار سريع",
};

export const LOCKED_MESSAGE = "هذه المرحلة مقفولة. أي تعديل عليها يتطلب طلب تغيير.";
export const NON_DECISIVE_NOTE = "الردود غير الحاسمة لا توقف العدّاد.";

export const CONTENT_STATUS_AR: Record<string, string> = {
  pending: "لم يُقدَّم",
  submitted: "قيد المراجعة",
  accepted: "مقبول",
  rejected: "مرفوض",
};

export const CONTENT_GROUP_AR: Record<string, string> = {
  blocking: "محتوى حاكم",
  non_blocking: "محتوى قابل للاستبدال",
};

export const ROUND_STATUS_AR: Record<string, string> = {
  open: "مفتوحة للإدخال",
  submitted: "مُرسلة — مقفولة",
  classified: "مُصنَّفة",
  closed: "مغلقة",
};

export const CLASSIFICATION_AR: Record<string, string> = {
  defect: "عيب (مجاني)",
  enhancement: "تحسين (مدفوع)",
  new_scope: "نطاق جديد (مدفوع)",
};

export const RESOLUTION_AR: Record<string, string> = {
  fixed: "تم الإصلاح",
  converted_to_cr: "حُوِّل إلى طلب تغيير",
  goodwill_fix: "إصلاح مجاملة",
};

export const CR_STATUS_AR: Record<string, string> = {
  draft: "مسودة",
  sent: "مُرسل — بانتظار القرار",
  approved: "معتمد",
  rejected: "مرفوض",
  expired: "منتهي الصلاحية",
  withdrawn: "مسحوب",
};

export const CONTENT_BLOCKER_MESSAGE = "لا يبدأ التصميم قبل اكتمال المحتوى الحاكم 100%.";
export const CR_LAUNCH_MESSAGE = "طلبات التغيير المفتوحة لا تعطّل الإطلاق.";
export const FEEDBACK_SUBMIT_WARNING = "بعد الإرسال لا يمكن إضافة ملاحظات لهذه الجولة.";
export const ROUNDS_EXHAUSTED_MESSAGE = "استُنفدت جولات التعديل المشمولة. الجولة القادمة مدفوعة.";

/** Default per-stage plan used by the new-project wizard. */
export const DEFAULT_STAGES = [
  { name: "اجتماع الاستلام", gate: "ميثاق موقَّع", gate_size: "small", our: 1, their: 2 },
  { name: "النطاق والمتطلبات", gate: "Scope Lock", gate_size: "big", our: 3, their: 3 },
  { name: "المحتوى والأصول", gate: "Content Lock", gate_size: "big", our: 0, their: 6 },
  { name: "التسعير", gate: "Pricing Lock", gate_size: "big", our: 2, their: 3 },
  { name: "التصميم", gate: "Design Lock", gate_size: "big", our: 6, their: 3 },
  { name: "البرمجة والتطوير", gate: "Build Complete", gate_size: "small", our: 12, their: 0 },
  { name: "الإطلاق والتسليم", gate: "Go Live", gate_size: "big", our: 2, their: 1 },
  { name: "الضمان", gate: null as string | null, gate_size: "small", our: 14, their: 0 },
] as const;

export const DEFAULT_ACCESS_ITEMS = [
  { name: "الدومين", note: "اسم النطاق مسجّل وبيانات الدخول متاحة.", slow: false },
  { name: "صلاحية DNS", note: "وصول كامل لإدارة سجلات DNS.", slow: false },
  { name: "الاستضافة", note: "حساب الاستضافة أو خطة السيرفر جاهزة.", slow: false },
  {
    name: "بوابة الدفع",
    note: "تستغرق عادة من 3 إلى 6 أسابيع — يجب البدء فيها من اليوم الأول.",
    slow: true,
  },
  { name: "شركة الشحن", note: "العقد ومفاتيح الربط مع شركة الشحن.", slow: false },
  { name: "SMTP", note: "بيانات إرسال البريد الرسمي للموقع.", slow: false },
  { name: "Google Analytics", note: "صلاحية محرر على الحساب.", slow: false },
  { name: "Meta Pixel", note: "صلاحية على مدير الأعمال.", slow: false },
];

export const DEFAULT_CONTENT_ITEMS = [
  { group: "blocking", name: "هيكل الصفحات", ac: "قائمة الصفحات النهائية مع ترتيبها" },
  {
    group: "blocking",
    name: "العناوين الرئيسية لكل صفحة",
    ac: "نصوص نهائية مُراجَعة، غير مترجمة آليًا",
  },
  { group: "blocking", name: "اللوجو", ac: "بصيغة AI أو SVG أو EPS. صيغة JPG بخلفية بيضاء مرفوضة" },
  { group: "blocking", name: "دليل الألوان والخطوط", ac: "أكواد الألوان وأسماء الخطوط" },
  {
    group: "blocking",
    name: "بيانات التواصل",
    ac: "عنوان، تليفونات، إيميل، حسابات التواصل، موقع الخريطة",
  },
  { group: "blocking", name: "الخدمات أو المنتجات الأساسية", ac: "الأسماء والأوصاف النهائية" },
  {
    group: "non_blocking",
    name: "الصور الفوتوغرافية",
    ac: "1500px عرض كحد أدنى، غير مأخوذة من واتساب",
  },
  {
    group: "non_blocking",
    name: "نصوص السياسات",
    ac: "الخصوصية والشروط، مخصصة للنشاط وغير منسوخة حرفيًا",
  },
  { group: "non_blocking", name: "تفاصيل ثانوية", ac: "أي محتوى إضافي غير حاكم" },
] as const;

export const DEFAULT_OUT_OF_SCOPE = [
  "SEO والتتبع",
  "كتابة المحتوى",
  "إدخال المنتجات",
  "الترجمة",
  "التصوير",
];

export const FAST_TRACK_TERMS = [
  "المدد تنضغط إلى ما يقارب 60% من المسار العادي.",
  "رسوم استعجال إضافية بنسبة 25% إلى 40% من قيمة المشروع.",
  "جولة تعديل واحدة بدل جولتين.",
  "المحتوى الحاكم مطلوب كاملًا قبل بدء العمل، بلا استثناء.",
  "تأخر الرد أكثر من 24 ساعة يُسقط المسار السريع دون استرداد الرسوم.",
];

/** Big gates never auto-approve — they freeze the project instead. */
export function isBigGate(stage: Pick<Stage, "gate_size">): boolean {
  return stage.gate_size === "big";
}

export function acknowledgementText(stageName: string, dateLabel: string): string {
  return (
    `أقر باعتماد مخرجات مرحلة «${stageName}» بتاريخ ${dateLabel}.\n` +
    "أعلم أن هذه المرحلة تُقفل، وأن أي تعديل لاحق عليها يُعامَل كطلب تغيير له تكلفة ومدة إضافية، " +
    "وأن هذا الاعتماد ملزم بغض النظر عن موقف العميل النهائي."
  );
}

export function currentStage(stages: Stage[]): Stage | undefined {
  const seq = stages.filter((s) => !s.is_parallel).sort((a, b) => a.stage_index - b.stage_index);
  return (
    seq.find(
      (s) => s.status === "active" || s.status === "awaiting_approval" || s.status === "frozen",
    ) ?? seq.find((s) => s.status === "pending")
  );
}

export function isOpenCR(cr: Pick<ChangeRequest, "status">): boolean {
  return cr.status === "draft" || cr.status === "sent";
}

/** Hours left in a 24h objection window; negative means the window closed. */
export function hoursLeft(fromIso: string | null, windowHours = 24): number {
  if (!fromIso) return 0;
  const end = new Date(fromIso).getTime() + windowHours * 3600_000;
  return (end - Date.now()) / 3600_000;
}
