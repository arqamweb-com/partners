/**
 * عميل الـ API — البديل الكامل لـ @supabase/supabase-js.
 *
 * يقدّم نفس الواجهة التي كان الكود يستخدمها من Supabase:
 *
 *   api.from("projects").select("*").eq("id", id).maybeSingle()
 *   api.from("stages").insert({ ... })
 *   api.from("stages").update({ ... }).eq("id", id)
 *   api.auth.signInWithPassword({ email, password })
 *
 * الفرق الوحيد أن الطلبات تروح لـ /api (PHP + MySQL) بدل Supabase،
 * والجلسة محفوظة في كوكي HttpOnly بدل localStorage.
 */

import type { Database } from "@/lib/db-types";

/** مسار الـ API — نفس الدومين، فلا توجد مشاكل CORS ولا كوكيز طرف ثالث. */
const API_BASE = import.meta.env["VITE_API_BASE"] || "/api";

export type Result<T> = { data: T; error: Error | null };

/** أنواع الجداول تأتي من نفس ملف الأنواع القديم، فتبقى الواجهة مطبوعة كما كانت. */
type PublicTables = Database["public"]["Tables"];
export type TableName = keyof PublicTables & string;
export type RowOf<T extends TableName> = PublicTables[T]["Row"];
type InsertOf<T extends TableName> = PublicTables[T]["Insert"];
type UpdateOf<T extends TableName> = PublicTables[T]["Update"];

type FilterOp = "eq" | "neq" | "gt" | "gte" | "lt" | "lte";
type Filter = [FilterOp, string, unknown];
type Action = "select" | "insert" | "update" | "delete";

async function request<T>(path: string, body?: unknown): Promise<T> {
  const init: RequestInit = {
    method: body === undefined ? "GET" : "POST",
    // مهم: يرسل كوكي الجلسة
    credentials: "same-origin",
  };

  if (body !== undefined) {
    init.headers = { "Content-Type": "application/json" };
    init.body = JSON.stringify(body);
  }

  const res = await fetch(`${API_BASE}${path}`, init);

  return handleResponse<T>(res);
}

/** رسائل بديلة حين لا يكون الرد JSON صالحًا (إعداد سيرفر أو خطأ شبكة). */
function statusMessage(status: number): string {
  if (status === 413) return "الملف أكبر من الحد المسموح.";
  if (status === 401) return "انتهت الجلسة. سجّل الدخول من جديد.";
  if (status === 403) return "ليس لديك صلاحية لهذا الإجراء.";
  return `فشل الطلب (${status})`;
}

async function handleResponse<T>(res: Response): Promise<T> {
  const payload = await res.json().catch(() => null);

  if (!res.ok || (payload && payload.error)) {
    const message = payload?.error?.message || statusMessage(res.status);
    // نرميه كـ Error حقيقي حتى تظهر رسائل قواعد العمل العربية في الـ toast
    throw new Error(message);
  }

  return payload as T;
}

// ---------------------------------------------------------------------------
// باني الاستعلامات
// ---------------------------------------------------------------------------

/**
 * Row  = نوع صف الجدول
 * Data = ما تُرجعه العملية: مصفوفة صفوف، أو صف واحد بعد single/maybeSingle
 */
class QueryBuilder<T extends TableName, Data = RowOf<T>[]> implements PromiseLike<Result<Data>> {
  private action: Action | null = null;
  private values: unknown = null;
  private filters: Filter[] = [];
  private columns: string[] | null = null;
  private orderBy: [string, "asc" | "desc"] | null = null;
  private rowLimit: number | null = null;
  private singleMode: "none" | "single" | "maybe" = "none";

  constructor(private table: T) {}

  select(columns?: string): this {
    if (this.action === null) this.action = "select";
    if (columns && columns.trim() !== "*") {
      this.columns = columns
        .split(",")
        .map((c) => c.trim())
        .filter(Boolean);
    }
    return this;
  }

  insert(values: InsertOf<T> | InsertOf<T>[]): this {
    this.action = "insert";
    this.values = values;
    return this;
  }

  update(values: UpdateOf<T>): this {
    this.action = "update";
    this.values = values;
    return this;
  }

  delete(): this {
    this.action = "delete";
    return this;
  }

  eq(column: string, value: unknown): this {
    return this.filter("eq", column, value);
  }
  neq(column: string, value: unknown): this {
    return this.filter("neq", column, value);
  }
  gt(column: string, value: unknown): this {
    return this.filter("gt", column, value);
  }
  gte(column: string, value: unknown): this {
    return this.filter("gte", column, value);
  }
  lt(column: string, value: unknown): this {
    return this.filter("lt", column, value);
  }
  lte(column: string, value: unknown): this {
    return this.filter("lte", column, value);
  }

  private filter(op: FilterOp, column: string, value: unknown): this {
    this.filters.push([op, column, value]);
    return this;
  }

  order(column: string, options?: { ascending?: boolean }): this {
    this.orderBy = [column, options?.ascending === false ? "desc" : "asc"];
    return this;
  }

  limit(count: number): this {
    this.rowLimit = count;
    return this;
  }

  single(): QueryBuilder<T, RowOf<T>> {
    this.singleMode = "single";
    this.rowLimit = this.rowLimit ?? 2;
    return this as unknown as QueryBuilder<T, RowOf<T>>;
  }

  maybeSingle(): QueryBuilder<T, RowOf<T> | null> {
    this.singleMode = "maybe";
    this.rowLimit = this.rowLimit ?? 2;
    return this as unknown as QueryBuilder<T, RowOf<T> | null>;
  }

  private async run(): Promise<Result<Data>> {
    try {
      const payload = await request<{ data: Record<string, unknown>[] }>("/db", {
        table: this.table,
        action: this.action ?? "select",
        filters: this.filters,
        values: this.values ?? undefined,
        columns: this.columns ?? undefined,
        order: this.orderBy ?? undefined,
        limit: this.rowLimit ?? undefined,
      });

      const rows = payload.data ?? [];

      if (this.singleMode === "single") {
        if (rows.length !== 1) {
          throw new Error("لم يُعثر على سجل واحد مطابق.");
        }
        return { data: rows[0] as Data, error: null };
      }
      if (this.singleMode === "maybe") {
        return { data: (rows[0] ?? null) as Data, error: null };
      }

      return { data: rows as Data, error: null };
    } catch (error) {
      // نفس عقد supabase-js: الخطأ يرجع في الكائن ولا يُرمى
      return { data: (this.singleMode === "none" ? [] : null) as Data, error: error as Error };
    }
  }

  then<R1 = Result<Data>, R2 = never>(
    onfulfilled?: ((value: Result<Data>) => R1 | PromiseLike<R1>) | null,
    onrejected?: ((reason: unknown) => R2 | PromiseLike<R2>) | null,
  ): PromiseLike<R1 | R2> {
    return this.run().then(onfulfilled, onrejected);
  }
}

// ---------------------------------------------------------------------------
// الجلسة والمصادقة
// ---------------------------------------------------------------------------

export type ApiUser = { id: string; email: string };
export type ApiSession = { user: ApiUser } | null;

type AuthEvent = "SIGNED_IN" | "SIGNED_OUT" | "USER_UPDATED" | "INITIAL_SESSION";
type AuthListener = (event: AuthEvent, session: ApiSession) => void;

const listeners = new Set<AuthListener>();
let sessionCache: { value: ApiSession } | null = null;
let inFlight: Promise<ApiSession> | null = null;

function emit(event: AuthEvent, session: ApiSession) {
  listeners.forEach((cb) => cb(event, session));
}

/** يقرأ الجلسة من السيرفر مرة واحدة ويحتفظ بها — getUser تُستدعى في أماكن كثيرة. */
async function loadSession(force = false): Promise<ApiSession> {
  if (!force && sessionCache) return sessionCache.value;
  if (!force && inFlight) return inFlight;

  inFlight = (async () => {
    try {
      const payload = await request<{ user: ApiUser | null }>("/auth/me");
      const session: ApiSession = payload.user ? { user: payload.user } : null;
      sessionCache = { value: session };
      return session;
    } catch {
      sessionCache = { value: null };
      return null;
    } finally {
      inFlight = null;
    }
  })();

  return inFlight;
}

function setSession(session: ApiSession, event: AuthEvent) {
  sessionCache = { value: session };
  emit(event, session);
}

const auth = {
  async getSession(): Promise<{ data: { session: ApiSession }; error: Error | null }> {
    const session = await loadSession();
    return { data: { session }, error: null };
  },

  async getUser(): Promise<{ data: { user: ApiUser | null }; error: Error | null }> {
    const session = await loadSession();
    return {
      data: { user: session?.user ?? null },
      error: session ? null : new Error("لا توجد جلسة."),
    };
  },

  async signInWithPassword(credentials: { email: string; password: string }) {
    try {
      const payload = await request<{ user: ApiUser }>("/auth/login", credentials);
      setSession({ user: payload.user }, "SIGNED_IN");
      return { data: { user: payload.user, session: { user: payload.user } }, error: null };
    } catch (error) {
      return { data: { user: null, session: null }, error: error as Error };
    }
  },

  async signUp(params: {
    email: string;
    password: string;
    options?: { data?: { full_name?: string; agency_name?: string } };
  }) {
    try {
      const payload = await request<{ user: ApiUser }>("/auth/signup", {
        email: params.email,
        password: params.password,
        full_name: params.options?.data?.full_name ?? "",
        agency_name: params.options?.data?.agency_name ?? null,
      });
      // التسجيل هنا يدخّل المستخدم فورًا — لا يوجد تأكيد بريد
      setSession({ user: payload.user }, "SIGNED_IN");
      return { data: { user: payload.user, session: { user: payload.user } }, error: null };
    } catch (error) {
      return { data: { user: null, session: null }, error: error as Error };
    }
  },

  async signOut() {
    try {
      await request("/auth/logout", {});
    } finally {
      setSession(null, "SIGNED_OUT");
    }
    return { error: null };
  },

  onAuthStateChange(callback: AuthListener) {
    listeners.add(callback);

    // نفس سلوك supabase-js: إشعار أولي بالحالة الحالية
    loadSession().then((session) => callback("INITIAL_SESSION", session));

    return {
      data: {
        subscription: {
          unsubscribe: () => {
            listeners.delete(callback);
          },
        },
      },
    };
  },
};

// ---------------------------------------------------------------------------

/**
 * دورة اعتماد المراحل.
 *
 * انتقالات الحالة لا تتم بتعديل أعمدة من المتصفح، بل عبر هذه المسارات:
 * السيرفر هو من يقرر مَن يملك تقديم المرحلة ومَن يملك اعتمادها أو رفضها،
 * حسب مَن الكرة في ملعبه. راجع api/lib/stages.php
 */
export const stages = {
  /** تقديم المرحلة للطرف الآخر لمراجعتها. */
  submit: (stageId: string, note = "") =>
    request<{ data: RowOf<"stages"> }>("/stages/submit", { stage_id: stageId, note }),

  /** اعتماد المرحلة وإقفالها نهائيًا. */
  approve: (stageId: string, approverName: string, acknowledgement: string) =>
    request<{ data: RowOf<"stages"> }>("/stages/approve", {
      stage_id: stageId,
      approver_name: approverName,
      acknowledgement,
    }),

  /** رفض المرحلة وإرجاعها لصاحبها مع سبب مكتوب. */
  reject: (stageId: string, reason: string) =>
    request<{ data: RowOf<"stages"> }>("/stages/reject", { stage_id: stageId, reason }),
};

/** ملف مرفوع كما يُحفظ داخل بيانات المشروع. */
export type UploadedFile = { id: string; name: string; size: number };

/** الحد الأقصى لحجم الملف — لازم يطابق MAX_UPLOAD_BYTES في api/lib/files.php */
export const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

export const ACCEPTED_UPLOADS =
  ".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.zip,.rar";

/**
 * الملفات تُرفع على الاستضافة نفسها لا على خدمة خارجية، وتُخزَّن خارج متناول
 * المتصفح ولا تُقدَّم إلا عبر /api/files/{id} بعد فحص الصلاحية.
 */
export const files = {
  async upload(file: File): Promise<UploadedFile> {
    if (file.size > MAX_UPLOAD_BYTES) {
      throw new Error(`«${file.name}» أكبر من الحد المسموح (8 ميجابايت).`);
    }

    const body = new FormData();
    body.append("file", file);

    const res = await fetch(`${API_BASE}/files/upload`, {
      method: "POST",
      credentials: "same-origin",
      body, // بدون Content-Type: المتصفح يضبط حدود multipart بنفسه
    });

    const payload = await handleResponse<{ data: UploadedFile }>(res);
    return payload.data;
  },

  /** يربط الملفات بالمشروع بعد حفظه — قبلها تكون معلّقة باسم من رفعها. */
  claim: (projectId: string, fileIds: string[]) =>
    request<{ data: { claimed: number } }>("/files/claim", {
      project_id: projectId,
      file_ids: fileIds,
    }),

  remove: (id: string) => request<{ data: { ok: true } }>("/files/delete", { id }),

  /** رابط التنزيل — يفتح فقط لمن له صلاحية على المشروع. */
  url: (id: string) => `${API_BASE}/files/${id}`,
};

export const api = {
  from: <T extends TableName>(table: T) => new QueryBuilder<T>(table),
  auth,
  stages,
  files,
};

/**
 * اسم بديل يطابق الاسم القديم، حتى تبقى ملفات الواجهة كما هي
 * ولا يتغير فيها غير سطر الاستيراد.
 */
export const supabase = api;
