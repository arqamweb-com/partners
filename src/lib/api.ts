/**
 * عميل الـ API — نداءات موارد مقابلة لمسارات لارافيل.
 *
 * الفارق عن النسخة السابقة ليس في الشكل بل في المسؤولية. كان العميل
 * يسمّي الجدول والأعمدة:
 *
 *     api.from("content_items").update({ status: "accepted" }).eq("id", id)
 *
 * والسيرفر ينقّي ما يُسمح بكتابته بقائمة بيضاء. أي ثغرة في القائمة كانت
 * ثغرة في النظام. الآن الفعل هو الوحدة:
 *
 *     api.content.review(id, { accept: true })
 *
 * فالسيرفر يعرف ماذا يجري ويفحص الصلاحية عليه، والمتصفح لا يملك أن يطلب
 * ما ليس فعلًا معرَّفًا أصلًا.
 */

import type {
  AccessItem,
  AppSettings,
  AuditEntry,
  ChangeRequest,
  ContentItem,
  FeedbackItem,
  FeedbackRound,
  PriceItem,
  Project,
  ProjectMember,
  Stage,
} from "@/lib/domain";

/** نفس الدومين، فلا CORS ولا كوكيز طرف ثالث. */
const API_BASE = import.meta.env["VITE_API_BASE"] || "/api";

// ---------------------------------------------------------------------------
// نقل الطلبات
// ---------------------------------------------------------------------------

/**
 * لارافيل يضع كوكي XSRF-TOKEN ويطلب إعادته في ترويسة على كل طلب كاتب.
 * هذا هو التغيير الوحيد المطلوب في طبقة النقل مقارنة بالنسخة السابقة.
 */
function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** أخطاء التحقق من لارافيل: { field: [messages] } */
    readonly errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

export { ApiError };

/** رسائل بديلة حين لا يكون الرد JSON صالحًا (إعداد سيرفر أو خطأ شبكة). */
function statusMessage(status: number): string {
  if (status === 401) return "انتهت الجلسة. سجّل الدخول من جديد.";
  if (status === 403) return "ليس لديك صلاحية لهذا الإجراء.";
  if (status === 413) return "الملف أكبر من الحد المسموح.";
  if (status === 419) return "انتهت صلاحية الجلسة. حدّث الصفحة وحاول تاني.";
  if (status === 429) return "محاولات كتيرة. استنى شوية وحاول تاني.";
  return `فشل الطلب (${status})`;
}

async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json" };

  if (method !== "GET") {
    const token = xsrfToken();
    if (token) headers["X-XSRF-TOKEN"] = token;
  }

  const isForm = body instanceof FormData;
  if (body !== undefined && !isForm) headers["Content-Type"] = "application/json";

  const init: RequestInit = { method, credentials: "same-origin", headers };
  if (body !== undefined) init.body = isForm ? body : JSON.stringify(body);

  const res = await fetch(`${API_BASE}${path}`, init);

  if (res.status === 204) return undefined as T;

  const payload = await res.json().catch(() => null);

  if (!res.ok) {
    // لارافيل يضع أول رسالة تحقق في message، وهي المعدّة للعرض
    throw new ApiError(payload?.message || statusMessage(res.status), res.status, payload?.errors);
  }

  return payload as T;
}

const get = <T>(path: string) => request<T>("GET", path);
const post = <T>(path: string, body?: unknown) => request<T>("POST", path, body ?? {});
const patch = <T>(path: string, body: unknown) => request<T>("PATCH", path, body);
const del = <T>(path: string) => request<T>("DELETE", path);

/** سلسلة الاستعلام من كائن، بإسقاط ما لم يُحدَّد. */
function qs(params: Record<string, string | number | boolean | undefined | null>): string {
  const query = new URLSearchParams(
    Object.entries(params)
      .filter(([, v]) => v != null && v !== "")
      .map(([k, v]) => [k, String(v)]),
  ).toString();

  return query ? `?${query}` : "";
}

/** أغلب المسارات تردّ { data: ... } */
type Wrapped<T> = { data: T };
const unwrap = <T>(p: Promise<Wrapped<T>>) => p.then((r) => r.data);

// ---------------------------------------------------------------------------
// الهوية
// ---------------------------------------------------------------------------

/**
 * المستخدم الحالي.
 *
 * لاحظ ما اختفى: مصفوفة roles و isAdmin المشتقة منها. الدور الآن واحد
 * صريح، ومعه صلاحياته محسوبة في السيرفر — فلا يعيد المتصفح استنتاجها.
 */
export type SystemRole = "admin" | "manager" | "supervisor" | "partner" | "client";

export type CurrentUser = {
  id: string;
  email: string;
  full_name: string;
  agency_name: string | null;
  system_role: SystemRole;
  role_label: string;
  /** من فريق أرقام (أدمن أو مدير أو مشرف). */
  is_staff: boolean;
  /** يملك التسعير والبنود التعاقدية (أدمن أو مدير). */
  can_price: boolean;
  partner_agency: string | null;
};

type AuthEvent = "SIGNED_IN" | "SIGNED_OUT" | "INITIAL_SESSION";
type AuthListener = (event: AuthEvent, user: CurrentUser | null) => void;

const listeners = new Set<AuthListener>();
let cached: { user: CurrentUser | null } | null = null;
let inFlight: Promise<CurrentUser | null> | null = null;

function emit(event: AuthEvent, user: CurrentUser | null) {
  listeners.forEach((cb) => cb(event, user));
}

function setUser(user: CurrentUser | null, event: AuthEvent) {
  cached = { user };
  emit(event, user);
}

/** يقرأ الجلسة من السيرفر مرة واحدة ويحتفظ بها. */
async function loadUser(force = false): Promise<CurrentUser | null> {
  if (!force && cached) return cached.user;
  if (!force && inFlight) return inFlight;

  inFlight = (async () => {
    try {
      const { user } = await get<{ user: CurrentUser | null }>("/auth/me");
      cached = { user };
      return user;
    } catch {
      cached = { user: null };
      return null;
    } finally {
      inFlight = null;
    }
  })();

  return inFlight;
}

export const auth = {
  me: () => loadUser(),
  refresh: () => loadUser(true),

  async login(email: string, password: string): Promise<CurrentUser> {
    const { user } = await post<{ user: CurrentUser }>("/auth/login", { email, password });
    setUser(user, "SIGNED_IN");
    return user;
  },

  async register(input: {
    email: string;
    password: string;
    full_name: string;
    agency_name?: string | null;
  }): Promise<CurrentUser> {
    const { user } = await post<{ user: CurrentUser }>("/auth/register", input);
    setUser(user, "SIGNED_IN");
    return user;
  },

  async logout(): Promise<void> {
    try {
      await post("/auth/logout");
    } finally {
      setUser(null, "SIGNED_OUT");
    }
  },

  /**
   * طلب رابط الاستعادة.
   * الرد واحد سواء كان البريد مسجَّلًا أم لا — لا يكشف من له حساب.
   */
  forgotPassword: (email: string) => post<{ message: string }>("/auth/forgot-password", { email }),

  resetPassword: (input: {
    token: string;
    email: string;
    password: string;
    /** التأكيد يُفحص في السيرفر أيضًا: خطأ مطبعي هنا يعني حسابًا مقفولًا */
    password_confirmation: string;
  }) => post<{ message: string }>("/auth/reset-password", input),

  onChange(callback: AuthListener) {
    listeners.add(callback);
    loadUser().then((user) => callback("INITIAL_SESSION", user));
    return () => listeners.delete(callback);
  },
};

// ---------------------------------------------------------------------------
// الموارد
// ---------------------------------------------------------------------------

export type ProjectDetail = Project & {
  stages: Stage[];
  access_items: AccessItem[];
  content_items: ContentItem[];
  feedback_rounds: (FeedbackRound & { items: FeedbackItem[] })[];
  change_requests: ChangeRequest[];
  members: (ProjectMember & { user: { id: string; full_name: string; email: string } | null })[];
};

type Paginated<T> = { data: T[]; total: number; current_page: number; last_page: number };

/** مشروع في الأرشيف: تاريخ الأرشفة ومن قام بها محمّلان معه. */
export type ArchivedProject = Project & {
  deleted_at: string;
  archived_by: { id: string; full_name: string; email: string } | null;
};

export const projects = {
  list: (params: { status?: string; per_page?: number; archived?: boolean } = {}) =>
    get<Paginated<Project>>(`/projects${qs(params)}`),

  /** المؤرشفة — للأدمن وحده، والسيرفر هو من يفرض ذلك (ProjectPolicy). */
  archived: () =>
    get<Paginated<ArchivedProject>>(`/projects${qs({ archived: true, per_page: 200 })}`),

  /** كل المشاريع المرئية — للوحة والتقارير. */
  all: async (): Promise<Project[]> => {
    const page = await get<Paginated<Project>>("/projects?per_page=200");
    return page.data;
  },

  get: (id: string) => unwrap(get<Wrapped<ProjectDetail>>(`/projects/${id}`)),

  create: (input: {
    name: string;
    project_type: string;
    end_client_name?: string;
    partner_agency?: string;
    owner_name?: string;
    type_details?: Record<string, unknown>;
    intake_data?: Record<string, unknown>;
    scope?: string;
    out_of_scope?: string;
  }) => unwrap(post<Wrapped<Project>>("/projects", input)),

  /** البيانات الأساسية والمواصفات. */
  update: (id: string, input: Partial<Project>) =>
    unwrap(patch<Wrapped<Project>>(`/projects/${id}`, input)),

  /** البنود التعاقدية — مسار منفصل لأن صلاحيته مختلفة. */
  updateCharter: (id: string, input: Partial<Project>) =>
    unwrap(patch<Wrapped<Project>>(`/projects/${id}/charter`, input)),

  /** اعتماد الطلب: يبذر المراحل والقوائم في السيرفر داخل معاملة واحدة. */
  approve: (id: string, stages?: Partial<Stage>[]) =>
    unwrap(post<Wrapped<ProjectDetail>>(`/projects/${id}/approve`, { stages })),

  changeStatus: (id: string, status: string, reason?: string) =>
    unwrap(post<Wrapped<Project>>(`/projects/${id}/status`, { status, reason })),

  auditLog: (id: string) => unwrap(get<Wrapped<AuditEntry[]>>(`/projects/${id}/audit-log`)),

  /**
   * حفظ خطة المراحل دفعة واحدة.
   * قواعد المقفولة (لا تُحذف ولا تتحرّك) صارت في السيرفر — كانت في
   * المتصفح وحده، فطلب مباشر كان يتجاوزها.
   */
  saveStagePlan: (
    id: string,
    plan: {
      id?: string | null;
      name: string;
      gate_name?: string | null;
      gate_size?: string;
      our_duration_days: number;
      their_duration_days: number;
    }[],
  ) => unwrap(request<Wrapped<Project>>("PUT", `/projects/${id}/stage-plan`, { stages: plan })),

  /** إعادة تنشيط مشروع مجمَّد بموعد دور جديد ورسوم. */
  reactivate: (
    id: string,
    input: {
      queue_slot_date: string;
      reactivation_fee: number;
      note?: string;
    },
  ) => unwrap(post<Wrapped<Project>>(`/projects/${id}/reactivate`, input)),

  types: () => unwrap(get<Wrapped<unknown[]>>("/project-types")),

  /**
   * الأرشفة — إخفاء المشروع من كل الشاشات مع بقائه كاملًا في السيرفر.
   * فعل قابل للتراجع، ولذلك اسمه archive لا delete.
   */
  archive: (id: string) => del(`/projects/${id}`),

  restore: (id: string) => unwrap(post<Wrapped<Project>>(`/projects/${id}/restore`)),

  /** الحذف النهائي — من الأرشيف وحده، ولا رجعة فيه. */
  purge: (id: string) => del(`/projects/${id}/purge`),

  members: {
    list: (projectId: string) =>
      unwrap(get<Wrapped<ProjectDetail["members"]>>(`/projects/${projectId}/members`)),
    invite: (projectId: string, email: string, role: string) =>
      unwrap(post<Wrapped<ProjectMember>>(`/projects/${projectId}/members`, { email, role })),
    setRole: (projectId: string, memberId: string, role: string) =>
      unwrap(patch<Wrapped<ProjectMember>>(`/projects/${projectId}/members/${memberId}`, { role })),
    remove: (projectId: string, memberId: string) =>
      del(`/projects/${projectId}/members/${memberId}`),
  },
};

// ---------------------------------------------------------------------------
// الحسابات — للأدمن وحده، والسيرفر هو من يفرض ذلك (UserPolicy)
// ---------------------------------------------------------------------------

/**
 * حساب كما تراه شاشة الإدارة.
 *
 * ليس CurrentUser: ذاك ما يعرفه المتصفح عن نفسه (وفيه صلاحياته محسوبة)،
 * وهذا ما يعرفه الأدمن عن غيره — الدور خام كما هو مخزَّن، ومعه أثر الحساب
 * في المشاريع حتى لا يُعطَّل أحد أو يُحذف على العمياني.
 */
export type ManagedUser = {
  id: string;
  email: string;
  full_name: string;
  agency_name: string | null;
  partner_agency: string | null;
  system_role: SystemRole;
  role_label: string;
  is_active: boolean;
  created_at: string | null;
  /** عدد المشاريع التي هو عضو فيها. */
  memberships_count: number;
  /** عدد المشاريع التي يملكها — الحذف ممنوع ما دام أكبر من صفر. */
  owned_projects_count: number;
};

export type UserInput = {
  email: string;
  full_name: string;
  system_role: SystemRole;
  agency_name?: string | null;
  partner_agency?: string | null;
  is_active?: boolean;
};

export const users = {
  list: (params: { q?: string; role?: string; status?: string; per_page?: number } = {}) => {
    const query = new URLSearchParams(
      Object.entries(params)
        .filter(([, v]) => v != null && v !== "")
        .map(([k, v]) => [k, String(v)]),
    ).toString();
    return get<Paginated<ManagedUser>>(`/users${query ? `?${query}` : ""}`);
  },

  create: (input: UserInput & { password: string }) =>
    unwrap(post<Wrapped<ManagedUser>>("/users", input)),

  /**
   * تعديل جزئي.
   * الدور والتفعيل يمرّان في السيرفر بصلاحية أوسع من بقية الحقول، ولذلك
   * تُرسل وحدها حين يكون المقصود تغيير الصلاحية لا البيانات.
   */
  update: (id: string, input: Partial<UserInput>) =>
    unwrap(patch<Wrapped<ManagedUser>>(`/users/${id}`, input)),

  /** تعيين كلمة مرور نيابة عن صاحب الحساب — ينهي جلساته المفتوحة. */
  setPassword: (id: string, password: string) =>
    post<Wrapped<{ ok: true }>>(`/users/${id}/password`, { password }),

  remove: (id: string) => del(`/users/${id}`),
};

/**
 * دورة اعتماد المراحل.
 * السيرفر هو من يقرر من يملك كل انتقال حسب دوره في هذا المشروع.
 */
export const stages = {
  submit: (stageId: string, note = "") =>
    unwrap(post<Wrapped<Stage>>(`/stages/${stageId}/submit`, { note })),

  approve: (stageId: string, approverName: string, acknowledgement: string) =>
    unwrap(
      post<Wrapped<Stage>>(`/stages/${stageId}/approve`, {
        approver_name: approverName,
        acknowledgement,
      }),
    ),

  reject: (stageId: string, reason: string) =>
    unwrap(post<Wrapped<Stage>>(`/stages/${stageId}/reject`, { reason })),
};

/** التقديم والمراجعة فعلان بصلاحيتين — لا عمود status واحد يكتبه الطرفان. */
export const content = {
  list: (projectId: string) =>
    unwrap(get<Wrapped<ContentItem[]>>(`/projects/${projectId}/content-items`)),

  submit: (itemId: string, value: string) =>
    unwrap(post<Wrapped<ContentItem>>(`/content-items/${itemId}/submit`, { value })),

  review: (itemId: string, accept: boolean, reason?: string) =>
    unwrap(post<Wrapped<ContentItem>>(`/content-items/${itemId}/review`, { accept, reason })),

  create: (
    projectId: string,
    input: { item_group: string; name: string; acceptance_criteria?: string },
  ) => unwrap(post<Wrapped<ContentItem>>(`/projects/${projectId}/content-items`, input)),
};

export const access = {
  list: (projectId: string) =>
    unwrap(get<Wrapped<AccessItem[]>>(`/projects/${projectId}/access-items`)),

  /** provided_by و provided_at يكتبهما السيرفر من الجلسة. */
  toggle: (itemId: string) => unwrap(post<Wrapped<AccessItem>>(`/access-items/${itemId}/toggle`)),

  update: (itemId: string, input: { name?: string; note?: string; is_slow?: boolean }) =>
    unwrap(patch<Wrapped<AccessItem>>(`/access-items/${itemId}`, input)),

  create: (projectId: string, input: { name: string; note?: string; is_slow?: boolean }) =>
    unwrap(post<Wrapped<AccessItem>>(`/projects/${projectId}/access-items`, input)),
};

export const feedback = {
  list: (projectId: string) =>
    unwrap(
      get<Wrapped<(FeedbackRound & { items: FeedbackItem[] })[]>>(
        `/projects/${projectId}/feedback`,
      ),
    ),

  openRound: (projectId: string, input: { stage_id?: string | null; is_paid?: boolean } = {}) =>
    unwrap(post<Wrapped<FeedbackRound>>(`/projects/${projectId}/feedback`, input)),

  submitRound: (roundId: string) =>
    unwrap(post<Wrapped<FeedbackRound>>(`/feedback-rounds/${roundId}/submit`)),

  classifyRound: (roundId: string, status: "classified" | "closed") =>
    unwrap(post<Wrapped<FeedbackRound>>(`/feedback-rounds/${roundId}/classify`, { status })),

  addItem: (roundId: string, input: { description: string; page_or_section?: string }) =>
    unwrap(post<Wrapped<FeedbackItem>>(`/feedback-rounds/${roundId}/items`, input)),

  classifyItem: (itemId: string, classification: string, resolution?: string) =>
    unwrap(
      post<Wrapped<FeedbackItem>>(`/feedback-items/${itemId}/classify`, {
        classification,
        resolution,
      }),
    ),

  object: (itemId: string, objectionText: string) =>
    unwrap(
      post<Wrapped<FeedbackItem>>(`/feedback-items/${itemId}/object`, {
        objection_text: objectionText,
      }),
    ),
};

/** التسعير والقرار فعلان بصلاحيتين — العميل لا يسعّر ولا يعتمد ما لم يُرسل له. */
export const changeRequests = {
  list: (projectId: string) =>
    unwrap(get<Wrapped<ChangeRequest[]>>(`/projects/${projectId}/change-requests`)),

  create: (
    projectId: string,
    input: {
      title: string;
      description?: string;
      source_feedback_item_id?: string | null;
      /** إعادة تقديم طلب مرفوض — مرة واحدة فقط، يفرضها السيرفر. */
      resubmitted_from?: string | null;
    },
  ) => unwrap(post<Wrapped<ChangeRequest>>(`/projects/${projectId}/change-requests`, input)),

  send: (
    id: string,
    pricing: {
      price: number;
      currency?: string;
      duration_days?: number;
      delivery_impact_days?: number;
      quote_valid_until?: string | null;
      decision_deadline?: string | null;
    },
  ) => unwrap(post<Wrapped<ChangeRequest>>(`/change-requests/${id}/send`, pricing)),

  decide: (id: string, approve: boolean, note = "") =>
    unwrap(post<Wrapped<ChangeRequest>>(`/change-requests/${id}/decide`, { approve, note })),
};

// ---------------------------------------------------------------------------
// الملفات
// ---------------------------------------------------------------------------

export type UploadedFile = { id: string; name: string; size: number };

export const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

export const ACCEPTED_UPLOADS =
  ".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.zip,.rar";

export const files = {
  async upload(file: File): Promise<UploadedFile> {
    if (file.size > MAX_UPLOAD_BYTES) {
      throw new ApiError(`«${file.name}» أكبر من الحد المسموح (8 ميجابايت).`, 413);
    }

    const body = new FormData();
    body.append("file", file);

    return unwrap(post<Wrapped<UploadedFile>>("/files", body));
  },

  /** يربط الملفات بالمشروع بعد حفظه — قبلها تكون معلّقة باسم من رفعها. */
  claim: (projectId: string, fileIds: string[]) =>
    unwrap(
      post<Wrapped<{ claimed: number }>>(`/projects/${projectId}/files/claim`, {
        file_ids: fileIds,
      }),
    ),

  remove: (id: string) => del(`/files/${id}`),

  /** رابط التنزيل — يفتح فقط لمن له صلاحية. */
  url: (id: string) => `${API_BASE}/files/${id}`,
};

// ---------------------------------------------------------------------------
// الإعدادات والإشعارات
// ---------------------------------------------------------------------------

export type SettingsPayload = {
  settings: AppSettings;
  holidays: { id: string; holiday_date: string; label: string }[];
  price_items: PriceItem[];
};

/** البيانات العابرة للمشاريع — نطاقها محسوب في السيرفر لا مصفّى في المتصفح. */
export const overview = {
  dashboard: () => get<{ projects: Project[]; stages: Stage[] }>("/overview"),

  reports: (month: string) =>
    get<{
      projects: Project[];
      stages: Stage[];
      change_requests: ChangeRequest[];
      feedback_rounds: { id: string; project_id: string; created_at: string }[];
    }>(`/reports?month=${encodeURIComponent(month)}`),
};

export const settings = {
  all: () => get<SettingsPayload>("/settings"),

  update: (input: Partial<AppSettings>) => unwrap(patch<Wrapped<AppSettings>>("/settings", input)),

  addHoliday: (holidayDate: string, label = "") =>
    unwrap(
      post<Wrapped<{ id: string }>>("/settings/holidays", {
        holiday_date: holidayDate,
        label,
      }),
    ),

  removeHoliday: (id: string) => del(`/settings/holidays/${id}`),

  addPriceItem: (input: {
    name: string;
    price: number;
    currency?: string;
    duration_days?: number;
  }) => unwrap(post<Wrapped<PriceItem>>("/settings/price-items", input)),

  removePriceItem: (id: string) => del(`/settings/price-items/${id}`),
};

export type AppNotification = {
  id: string;
  read_at: string | null;
  created_at: string;
  data: { event_key: string; title: string; body: string; url: string };
};

/** صفحة من الإشعارات — العدّاد على غير المقروء كله لا على الصفحة. */
export type NotificationPage = {
  data: AppNotification[];
  unread: number;
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
};

export const notifications = {
  list: (params: { filter?: "all" | "unread"; page?: number; per_page?: number } = {}) =>
    get<NotificationPage>(`/notifications${qs(params)}`),

  /** إشعار بعينه — الجرس كان يعلّم الكل، فيضيع أثر ما لم يُقرأ. */
  markRead: (id: string) => post(`/notifications/${id}/read`),

  markAllRead: () => post("/notifications/read"),
};

// ---------------------------------------------------------------------------

export const api = {
  auth,
  overview,
  projects,
  users,
  stages,
  content,
  access,
  feedback,
  changeRequests,
  files,
  settings,
  notifications,
};

export default api;
