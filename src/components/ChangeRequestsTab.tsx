import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Check, FileSignature, Plus, RotateCcw, Send, X } from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { usePriceList, useHolidays } from "@/hooks/useSettings";
import {
  CR_LAUNCH_MESSAGE,
  CR_STATUS_AR,
  isOpenCR,
  type ChangeRequest,
  type FeedbackItem,
  type Project,
} from "@/lib/domain";
import { addBusinessDays, formatDateAr } from "@/lib/business-days";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { EmptyState } from "@/components/EmptyState";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

const EMPTY_DRAFT = {
  title: "",
  description: "",
  price: 0,
  duration_days: 0,
  delivery_impact_days: 0,
  source_feedback_item_id: null as string | null,
};

export function ChangeRequestsTab({
  project,
  seedFrom,
  onSeedConsumed,
}: {
  project: Project;
  seedFrom?: FeedbackItem | null;
  onSeedConsumed?: () => void;
}) {
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();
  const { data: prices = [] } = usePriceList();
  const { data: holidays = [] } = useHolidays();
  const [draft, setDraft] = useState(EMPTY_DRAFT);
  const [open, setOpen] = useState(false);
  const [confirming, setConfirming] = useState<{ cr: ChangeRequest; approve: boolean } | null>(
    null,
  );

  const { data: crs = [], isLoading } = useQuery({
    queryKey: ["change-requests", project.id],
    queryFn: () => api.changeRequests.list(project.id),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["change-requests", project.id] });
    qc.invalidateQueries({ queryKey: ["projects-overview"] });
  };

  useEffect(() => {
    if (!seedFrom) return;
    setDraft({
      ...EMPTY_DRAFT,
      title: seedFrom.description.slice(0, 90),
      description: `محوّل من ملاحظة${seedFrom.page_or_section ? ` (${seedFrom.page_or_section})` : ""}: ${seedFrom.description}`,
      source_feedback_item_id: seedFrom.id,
    });
    setOpen(true);
    onSeedConsumed?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [seedFrom?.id]);

  /*
   * انتهاء مهلة القرار انتقل للسيرفر: أمر arqam:expire-change-requests
   * يعمل يوميًا. كان هنا في useEffect بحارس isAdmin، فلا تنتهي المهلة إلا
   * إن صادف أن موظفًا فتح هذا التبويب.
   */

  const save = useMutation({
    mutationFn: async (send: boolean) => {
      const title = draft.title.trim();
      if (title.length < 3) throw new Error("عنوان الطلب مطلوب.");
      if (title.length > 200) throw new Error("العنوان طويل جدًا.");

      // الطلب يُسجَّل أولًا بلا سعر؛ التسعير والإرسال فعل ثانٍ بصلاحية أخرى،
      // فلا يستطيع العميل أن يسعّر لنفسه ولو عدّل الطلب
      const cr = await api.changeRequests.create(project.id, {
        title,
        description: draft.description.trim().slice(0, 4000),
        source_feedback_item_id: draft.source_feedback_item_id,
      });

      if (send) {
        await api.changeRequests.send(cr.id, {
          price: Number(draft.price) || 0,
          duration_days: Number(draft.duration_days) || 0,
          delivery_impact_days: Number(draft.delivery_impact_days) || 0,
          quote_valid_until: isoDate(addDays(new Date(), 14)),
          decision_deadline: isoDate(addBusinessDays(new Date(), 3, holidays)),
        });
      }

      if (draft.source_feedback_item_id) {
        await api.feedback.classifyItem(
          draft.source_feedback_item_id,
          "new_scope",
          "converted_to_cr",
        );
      }
    },
    onSuccess: () => {
      setOpen(false);
      setDraft(EMPTY_DRAFT);
      if (!me?.isAdmin) toast.success("وصل طلبك. فريق أرقام هيسعّره ويرجعلك بيه.");
      qc.invalidateQueries({ queryKey: ["feedback", project.id] });
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر الحفظ."),
  });

  const sendCR = useMutation({
    mutationFn: (cr: ChangeRequest) =>
      api.changeRequests.send(cr.id, {
        price: Number(cr.price) || 0,
        duration_days: cr.duration_days,
        delivery_impact_days: cr.delivery_impact_days,
        quote_valid_until: isoDate(addDays(new Date(), 14)),
        decision_deadline: isoDate(addBusinessDays(new Date(), 3, holidays)),
      }),
    onSuccess: invalidate,
    onError: () => toast.error("تعذّر الإرسال."),
  });

  const decide = useMutation({
    mutationFn: async ({ cr, approve }: { cr: ChangeRequest; approve: boolean }) => {
      if (cr.quote_valid_until && new Date(`${cr.quote_valid_until}T23:59:59`) < new Date())
        throw new Error("انتهت صلاحية العرض (14 يومًا). يلزم إعادة التسعير قبل الاعتماد.");

      // من قرّر ومتى، وتمديد التسليم مرة واحدة، كلها في السيرفر
      await api.changeRequests.decide(cr.id, approve);
    },
    onSuccess: () => {
      setConfirming(null);
      qc.invalidateQueries({ queryKey: ["project", project.id] });
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر تسجيل القرار."),
  });

  const reprice = useMutation({
    // إعادة التقديم مرة واحدة فقط — يفرضها السيرفر
    mutationFn: (cr: ChangeRequest) =>
      api.changeRequests.create(project.id, {
        title: cr.title,
        description: cr.description ?? "",
        source_feedback_item_id: cr.source_feedback_item_id,
        resubmitted_from: cr.id,
      }),
    onSuccess: () => {
      toast.success("أُنشئت مسودة جديدة لإعادة التسعير.");
      invalidate();
    },
    onError: (e: unknown) =>
      toast.error(e instanceof Error ? e.message : "لا يمكن إعادة تقديم هذا الطلب مرة أخرى."),
  });

  const openCount = crs.filter(isOpenCR).length;

  return (
    <div className="mt-4 space-y-6">
      <div className="flex items-start gap-3 rounded-xl border border-border bg-surface-elevated p-4 text-sm">
        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-primary" />
        <p className="leading-6">
          {CR_LAUNCH_MESSAGE} الإطلاق يمضي على النطاق المعتمد، وطلبات التغيير المفتوحة (
          <Num value={openCount} />) تُنفَّذ بعد الاعتماد الكتابي في جدول مستقل.
        </p>
      </div>

      <div className="surface-card p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="font-display text-base font-semibold">طلبات التغيير</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              لا يبدأ أي عمل قبل اعتماد كتابي. العرض صالح <Num value={14} /> يومًا، ومهلة القرار{" "}
              <Num value={3} /> أيام عمل.
            </p>
          </div>
          <Button onClick={() => setOpen(true)}>
            <Plus className="size-4" />
            {me?.isAdmin ? "طلب تغيير جديد" : "اطلب تعديلًا"}
          </Button>
        </div>

        {isLoading && <p className="mt-6 text-sm text-muted-foreground">جارٍ التحميل…</p>}

        {!isLoading && crs.length === 0 && (
          <div className="mt-4">
            <EmptyState
              icon={FileSignature}
              title="لا توجد طلبات تغيير"
              hint="طلب التغيير هو الطريق الرسمي لأي إضافة خارج النطاق المعتمد: سعر ومدة وأثر واضح على التسليم، باعتماد كتابي قبل بدء العمل."
            />
          </div>
        )}

        <ul className="mt-5 space-y-3">
          {crs.map((cr) => {
            const quoteExpired =
              !!cr.quote_valid_until && new Date(`${cr.quote_valid_until}T23:59:59`) < new Date();
            return (
              <li key={cr.id} className="rounded-xl border border-border/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="text-sm font-medium">{cr.title}</div>
                    {cr.description && (
                      <p className="mt-1 text-xs leading-6 text-muted-foreground">
                        {cr.description}
                      </p>
                    )}
                  </div>
                  <span
                    className={cn(
                      "rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap",
                      cr.status === "approved" && "bg-success/15 text-success-foreground",
                      cr.status === "sent" && "bg-warn/15 text-warn-foreground",
                      cr.status === "draft" && "bg-muted text-muted-foreground",
                      (cr.status === "rejected" ||
                        cr.status === "expired" ||
                        cr.status === "withdrawn") &&
                        "bg-destructive/12 text-destructive",
                    )}
                  >
                    {CR_STATUS_AR[cr.status]}
                  </span>
                </div>

                <div className="mt-3 grid gap-2 text-xs sm:grid-cols-4">
                  <Field
                    label="السعر"
                    value={
                      cr.requested_by && cr.status === "draft"
                        ? "بانتظار التسعير"
                        : `${cr.price} ${cr.currency}`
                    }
                  />
                  <Field label="مدة التنفيذ" value={`${cr.duration_days} يوم عمل`} />
                  <Field label="أثر التسليم" value={`+${cr.delivery_impact_days} يوم عمل`} />
                  <Field
                    label="صلاحية العرض"
                    value={cr.quote_valid_until ? formatDateAr(cr.quote_valid_until) : "—"}
                  />
                </div>

                {cr.status === "sent" && (
                  <p className="mt-3 text-[11px] text-muted-foreground">
                    مهلة القرار حتى {formatDateAr(cr.decision_deadline)} — بعدها ينتهي الطلب
                    تلقائيًا ويُسجَّل في سجل التدقيق. الردود غير الحاسمة لا توقف العدّاد.
                  </p>
                )}

                {quoteExpired && cr.status !== "approved" && (
                  <p className="mt-3 flex items-center gap-1.5 text-[11px] text-destructive">
                    <AlertTriangle className="size-3.5" /> انتهت صلاحية العرض — يلزم إعادة تسعير،
                    ولا يُعاد تفعيله كما هو.
                  </p>
                )}

                <div className="mt-3 flex flex-wrap gap-2">
                  {me?.isAdmin && cr.status === "draft" && (
                    <Button size="sm" onClick={() => sendCR.mutate(cr)}>
                      <Send className="size-3.5" /> إرسال للعميل
                    </Button>
                  )}
                  {cr.status === "sent" && !quoteExpired && (
                    <>
                      <Button size="sm" onClick={() => setConfirming({ cr, approve: true })}>
                        <Check className="size-3.5" /> اعتماد كتابي
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setConfirming({ cr, approve: false })}
                      >
                        <X className="size-3.5" /> رفض
                      </Button>
                    </>
                  )}
                  {me?.isAdmin &&
                    (cr.status === "expired" ||
                      cr.status === "rejected" ||
                      cr.status === "withdrawn") && (
                      <Button size="sm" variant="secondary" onClick={() => reprice.mutate(cr)}>
                        <RotateCcw className="size-3.5" /> إعادة تقديم وتسعير (مرة واحدة)
                      </Button>
                    )}
                </div>
              </li>
            );
          })}
        </ul>
      </div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent dir="rtl" className="max-w-lg text-start">
          <DialogHeader className="text-start">
            <DialogTitle className="font-display">
              {me?.isAdmin ? "طلب تغيير جديد" : "طلب تعديل"}
            </DialogTitle>
            <DialogDescription>
              {me?.isAdmin
                ? "السعر والمدة وأثر التسليم تُعرض على العميل، ولا يبدأ العمل قبل اعتماده كتابيًا."
                : "اشرح التعديل المطلوب بدقة. فريق أرقام هيسعّره ويحدد أثره على التسليم ويرجعلك للاعتماد قبل بدء أي عمل."}
            </DialogDescription>
          </DialogHeader>

          {me?.isAdmin && prices.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {prices.map((p) => (
                <Button
                  key={p.id}
                  size="sm"
                  variant="outline"
                  type="button"
                  onClick={() =>
                    setDraft((d) => ({
                      ...d,
                      title: d.title || p.name,
                      price: Number(p.price),
                      duration_days: p.duration_days,
                      delivery_impact_days: p.duration_days,
                    }))
                  }
                >
                  {p.name} · <span className="num">{p.price}</span>
                </Button>
              ))}
            </div>
          )}

          <div className="grid gap-3">
            <div className="grid gap-1.5">
              <Label htmlFor="cr-title">العنوان</Label>
              <Input
                id="cr-title"
                dir="rtl"
                maxLength={200}
                value={draft.title}
                onChange={(e) => setDraft((d) => ({ ...d, title: e.target.value }))}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="cr-desc">الوصف</Label>
              <Textarea
                id="cr-desc"
                dir="rtl"
                rows={3}
                maxLength={4000}
                value={draft.description}
                onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))}
              />
            </div>
            {/* التسعير قرار فريق أرقام — العميل يصف المطلوب فقط */}
            {me?.isAdmin && (
              <div className="grid grid-cols-3 gap-3">
                <NumField
                  label="السعر"
                  value={draft.price}
                  onChange={(v) => setDraft((d) => ({ ...d, price: v }))}
                />
                <NumField
                  label="مدة التنفيذ"
                  value={draft.duration_days}
                  onChange={(v) => setDraft((d) => ({ ...d, duration_days: v }))}
                />
                <NumField
                  label="أثر التسليم"
                  value={draft.delivery_impact_days}
                  onChange={(v) => setDraft((d) => ({ ...d, delivery_impact_days: v }))}
                />
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:justify-start">
            {me?.isAdmin ? (
              <>
                <Button onClick={() => save.mutate(true)} disabled={save.isPending}>
                  <Send className="size-4" /> حفظ وإرسال
                </Button>
                <Button
                  variant="secondary"
                  onClick={() => save.mutate(false)}
                  disabled={save.isPending}
                >
                  حفظ كمسودة
                </Button>
              </>
            ) : (
              <Button onClick={() => save.mutate(false)} disabled={save.isPending}>
                <Send className="size-4" /> إرسال الطلب لفريق أرقام
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!confirming} onOpenChange={(v) => !v && setConfirming(null)}>
        <AlertDialogContent dir="rtl" className="text-start">
          <AlertDialogHeader className="text-start">
            <AlertDialogTitle className="font-display">
              {confirming?.approve ? "اعتماد كتابي لطلب التغيير" : "رفض طلب التغيير"}
            </AlertDialogTitle>
            <AlertDialogDescription className="leading-7">
              {confirming?.approve ? (
                <>
                  الاعتماد ملزم: تُضاف قيمة {confirming?.cr.price} {confirming?.cr.currency} إلى
                  المستحقات، ويتأخر التسليم المعدّل {confirming?.cr.delivery_impact_days} يوم عمل.
                  لا يمكن التراجع بعد بدء التنفيذ.
                </>
              ) : (
                <>
                  الرفض يُقفل الطلب. يمكن إعادة تقديمه بنفس الجوهر مرة واحدة فقط، وبتسعير جديد إن
                  انتهت صلاحية العرض.
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="gap-2 sm:justify-start">
            <AlertDialogAction onClick={() => confirming && decide.mutate(confirming)}>
              تأكيد
            </AlertDialogAction>
            <AlertDialogCancel>رجوع</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg bg-surface-elevated p-2.5">
      <div className="text-[11px] text-muted-foreground">{label}</div>
      <div className="num mt-0.5 font-medium">{value}</div>
    </div>
  );
}

function NumField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: number;
  onChange: (v: number) => void;
}) {
  return (
    <div className="grid gap-1.5">
      <Label className="text-xs">{label}</Label>
      <Input
        type="number"
        min={0}
        className="num"
        value={value}
        onChange={(e) => onChange(Math.max(0, Number(e.target.value) || 0))}
      />
    </div>
  );
}

function addDays(d: Date, n: number): Date {
  const c = new Date(d);
  c.setDate(c.getDate() + n);
  return c;
}

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}
