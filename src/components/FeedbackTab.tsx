import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  AlertTriangle,
  Gavel,
  Lock,
  MessageSquare,
  Plus,
  Send,
  ShieldQuestion,
  Timer,
} from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import {
  CLASSIFICATION_AR,
  FEEDBACK_SUBMIT_WARNING,
  NON_DECISIVE_NOTE,
  RESOLUTION_AR,
  ROUNDS_EXHAUSTED_MESSAGE,
  ROUND_STATUS_AR,
  hoursLeft,
  type FeedbackItem,
  type FeedbackRound,
  type Project,
  type Stage,
} from "@/lib/domain";
import { formatDateAr } from "@/lib/business-days";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { EmptyState } from "@/components/EmptyState";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
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

type Classification = "defect" | "enhancement" | "new_scope";

export function FeedbackTab({
  project,
  currentStage,
  onConvertToCR,
}: {
  project: Project;
  currentStage: Stage | undefined;
  onConvertToCR: (item: FeedbackItem) => void;
}) {
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();
  const [newItem, setNewItem] = useState({ description: "", page: "" });
  const [confirmSubmit, setConfirmSubmit] = useState<FeedbackRound | null>(null);
  const [objecting, setObjecting] = useState<string | null>(null);
  const [objection, setObjection] = useState("");

  const { data, isLoading } = useQuery({
    queryKey: ["feedback", project.id],
    // نداء واحد يعيد الجولات ببنودها؛ نفرد البنود للحسابات القائمة
    queryFn: async () => {
      const rounds = await api.feedback.list(project.id);
      return {
        rounds: rounds as FeedbackRound[],
        items: rounds.flatMap((r) => r.items) as FeedbackItem[],
      };
    },
  });

  const rounds = data?.rounds ?? [];
  const items = data?.items ?? [];
  const invalidate = () => qc.invalidateQueries({ queryKey: ["feedback", project.id] });

  const usedRounds = rounds.length;
  const exhausted = usedRounds >= project.revision_rounds_allowed;
  const openRound = rounds.find((r) => r.status === "open");

  const openNewRound = useMutation({
    // رقم الجولة وسجل التدقيق من السيرفر
    mutationFn: () =>
      api.feedback.openRound(project.id, {
        stage_id: currentStage?.id ?? null,
        is_paid: usedRounds + 1 > project.revision_rounds_allowed,
      }),
    onSuccess: () => {
      toast.success("تم فتح جولة ملاحظات جديدة.");
      invalidate();
    },
    onError: () => toast.error("تعذّر فتح الجولة."),
  });

  const addItem = useMutation({
    mutationFn: async (round: FeedbackRound) => {
      const description = newItem.description.trim();
      if (description.length < 5) throw new Error("اكتب وصف الملاحظة بوضوح.");
      if (description.length > 2000) throw new Error("الوصف طويل جدًا (الحد 2000 حرف).");
      // نافذة الجولة لازم تكون مفتوحة — يفرضه السيرفر
      await api.feedback.addItem(round.id, {
        description,
        page_or_section: newItem.page.trim().slice(0, 200),
      });
    },
    onSuccess: () => {
      setNewItem({ description: "", page: "" });
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت الإضافة."),
  });

  const submitRound = useMutation({
    mutationFn: async (round: FeedbackRound) => {
      const count = items.filter((i) => i.round_id === round.id).length;
      if (count === 0) throw new Error("أضف ملاحظة واحدة على الأقل قبل الإرسال.");
      await api.feedback.submitRound(round.id);
    },
    onSuccess: () => {
      setConfirmSubmit(null);
      toast.success("تم إرسال الجولة وإقفال النافذة.");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر الإرسال."),
  });

  const classify = useMutation({
    mutationFn: async ({ item, value }: { item: FeedbackItem; value: Classification }) => {
      // التصنيف قرار فريق أرقام — السياسة في السيرفر
      await api.feedback.classifyItem(item.id, value);
      await api.feedback.classifyRound(item.round_id, "classified");
    },
    onSuccess: invalidate,
    onError: () => toast.error("تعذّر التصنيف."),
  });

  const objectMutation = useMutation({
    mutationFn: async (item: FeedbackItem) => {
      const text = objection.trim();
      if (text.length < 5)
        throw new Error("الاعتراض يجب أن يستشهد بالبند المحدد في النطاق المعتمد أو التصميم.");
      if (hoursLeft(item.classified_at) <= 0) throw new Error("انتهت مهلة الاعتراض (24 ساعة).");
      // وقت الاعتراض يختمه السيرفر
      await api.feedback.object(item.id, text);
    },
    onSuccess: () => {
      setObjecting(null);
      setObjection("");
      toast.success("سُجّل الاعتراض. باقي الملاحظات تمضي في التنفيذ دون انتظار.");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر تسجيل الاعتراض."),
  });

  if (isLoading)
    return (
      <div className="surface-card mt-4 p-10 text-center text-sm text-muted-foreground">
        جارٍ التحميل…
      </div>
    );

  const classified = items.filter((i) => i.classification);
  const disputed = classified.filter((i) => i.objection_at && !i.resolution);
  const proceeding = classified.filter((i) => !i.objection_at || i.resolution);

  // ملاحظات أُرسلت ولم يصنّفها فريق أرقام بعد. بدون هذا القسم تختفي من
  // الشاشة تمامًا بين الإرسال والتصنيف — موجودة في القاعدة ولا يراها أحد.
  const awaitingReview = items.filter(
    (i) => !i.classification && rounds.find((r) => r.id === i.round_id)?.status !== "open",
  );

  return (
    <div className="mt-4 space-y-6">
      <div className="surface-card p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="font-display text-base font-semibold">جولات الملاحظات</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              نافذة واحدة لكل جولة: تُملأ مرة واحدة وتُرسل مرة واحدة. {NON_DECISIVE_NOTE}
            </p>
          </div>
          <span className="text-xs text-muted-foreground">
            المستخدَم <Num value={usedRounds} /> من <Num value={project.revision_rounds_allowed} />{" "}
            جولة مشمولة
          </span>
        </div>

        {exhausted && (
          <div className="mt-4 flex items-start gap-3 rounded-xl border border-warn/40 bg-warn/10 p-4 text-sm text-warn-foreground">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
            <p className="leading-6">
              {ROUNDS_EXHAUSTED_MESSAGE} أمام مسؤول المشروع <Num value={2} /> يوم عمل لاعتماد الجولة
              المدفوعة، وإلا يُجمَّد المشروع.
            </p>
          </div>
        )}

        {me?.isAdmin && !openRound && (
          <Button
            className="mt-4"
            onClick={() => openNewRound.mutate()}
            disabled={openNewRound.isPending}
          >
            <Plus className="size-4" /> فتح جولة ملاحظات{exhausted ? " مدفوعة" : ""} للمرحلة الحالية
          </Button>
        )}

        {rounds.length === 0 && (
          <div className="mt-4">
            <EmptyState
              icon={MessageSquare}
              title="لا توجد جولات ملاحظات بعد"
              hint="جولة الملاحظات هي النافذة الوحيدة لجمع تعديلات المرحلة دفعة واحدة، بدل الملاحظات المتقطعة التي تُعطّل الجدول."
            />
          </div>
        )}

        <ul className="mt-4 space-y-2">
          {rounds.map((r) => (
            <li
              key={r.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/70 p-3 text-xs"
            >
              <span className="font-medium">
                الجولة <Num value={r.round_number} />
                {r.is_paid && <span className="ms-2 text-warn-foreground">مدفوعة</span>}
              </span>
              <span className="text-muted-foreground">
                {ROUND_STATUS_AR[r.status]} · فُتحت {formatDateAr(r.opened_at)}
                {r.submitted_at ? ` · أُرسلت ${formatDateAr(r.submitted_at)}` : ""}
              </span>
            </li>
          ))}
        </ul>
      </div>

      {openRound && (
        <div className="surface-card p-6">
          <h3 className="font-display text-base font-semibold">
            إدخال ملاحظات الجولة <Num value={openRound.round_number} />
          </h3>
          <p className="mt-1 flex items-center gap-1.5 text-xs text-destructive">
            <AlertTriangle className="size-3.5" /> {FEEDBACK_SUBMIT_WARNING}
          </p>

          <ul className="mt-4 space-y-2">
            {items
              .filter((i) => i.round_id === openRound.id)
              .map((i, idx) => (
                <li key={i.id} className="rounded-xl border border-border/70 p-3 text-sm">
                  <div className="flex items-start gap-2">
                    <span className="text-xs text-muted-foreground">
                      <Num value={idx + 1} />
                    </span>
                    <div>
                      <div>{i.description}</div>
                      {i.page_or_section && (
                        <div className="mt-0.5 text-xs text-muted-foreground">
                          {i.page_or_section}
                        </div>
                      )}
                    </div>
                  </div>
                </li>
              ))}
          </ul>

          <div className="mt-4 grid gap-2">
            <Input
              dir="rtl"
              maxLength={200}
              placeholder="الصفحة أو القسم (مثال: الصفحة الرئيسية — قسم الأسعار)"
              value={newItem.page}
              onChange={(e) => setNewItem((n) => ({ ...n, page: e.target.value }))}
            />
            <Textarea
              dir="rtl"
              rows={3}
              maxLength={2000}
              placeholder="وصف الملاحظة بدقة…"
              value={newItem.description}
              onChange={(e) => setNewItem((n) => ({ ...n, description: e.target.value }))}
            />
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => addItem.mutate(openRound)}
                disabled={addItem.isPending}
              >
                <Plus className="size-3.5" /> إضافة ملاحظة
              </Button>
              <Button size="sm" onClick={() => setConfirmSubmit(openRound)}>
                <Send className="size-3.5" /> إرسال الملاحظات
              </Button>
            </div>
          </div>
        </div>
      )}

      {awaitingReview.length > 0 && (
        <div className="surface-card p-6">
          <h3 className="flex items-center gap-2 font-display text-base font-semibold">
            <ShieldQuestion className="size-4 text-warn" />
            بانتظار التصنيف
            <span className="rounded-full bg-warn/15 px-2 py-0.5 text-xs text-warn-foreground">
              <Num value={awaitingReview.length} />
            </span>
          </h3>
          <p className="mt-1 text-xs text-muted-foreground">
            {me?.isAdmin
              ? "صنّف كل ملاحظة لتبدأ في التنفيذ: عيب مجاني، أم تحسين أو نطاق جديد مدفوع."
              : "ملاحظاتك وصلت وفريق أرقام بيراجعها ويصنّفها."}
          </p>

          <ul className="mt-4 space-y-3">
            {awaitingReview.map((i) => (
              <ItemCard
                key={i.id}
                item={i}
                isAdmin={!!me?.isAdmin}
                onClassify={(v) => classify.mutate({ item: i, value: v })}
                onObject={() => setObjecting(i.id)}
                objecting={objecting === i.id}
                objection={objection}
                setObjection={setObjection}
                onSubmitObjection={() => objectMutation.mutate(i)}
                onCancelObjection={() => setObjecting(null)}
                onConvert={() => onConvertToCR(i)}
              />
            ))}
          </ul>
        </div>
      )}

      {classified.length > 0 && (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="surface-card p-6 lg:col-span-2">
            <h3 className="flex items-center gap-2 font-display text-base font-semibold">
              <Timer className="size-4 text-primary" /> قيد التنفيذ
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              الملاحظات غير المتنازع عليها تمضي فورًا — أي نقاش على بند واحد لا يوقف باقي الجولة.
            </p>
            <ul className="mt-4 space-y-3">
              {proceeding.length === 0 && (
                <li className="text-xs text-muted-foreground">
                  لا توجد ملاحظات جاهزة للتنفيذ الآن.
                </li>
              )}
              {proceeding.map((i) => (
                <ItemCard
                  key={i.id}
                  item={i}
                  isAdmin={!!me?.isAdmin}
                  onClassify={(v) => classify.mutate({ item: i, value: v })}
                  onObject={() => setObjecting(i.id)}
                  objecting={objecting === i.id}
                  objection={objection}
                  setObjection={setObjection}
                  onSubmitObjection={() => objectMutation.mutate(i)}
                  onCancelObjection={() => setObjecting(null)}
                  onConvert={() => onConvertToCR(i)}
                />
              ))}
            </ul>
          </div>

          <div className="surface-card p-6">
            <h3 className="flex items-center gap-2 font-display text-base font-semibold">
              <ShieldQuestion className="size-4 text-warn" /> قيد النقاش
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              بنود مُعترَض عليها فقط. لا تعطّل التنفيذ ولا الجدول.
            </p>
            <ul className="mt-4 space-y-3">
              {disputed.length === 0 && (
                <li className="text-xs text-muted-foreground">لا توجد بنود متنازع عليها.</li>
              )}
              {disputed.map((i) => (
                <li
                  key={i.id}
                  className="rounded-xl border border-warn/40 bg-warn/5 p-3 text-xs leading-6"
                >
                  <div className="font-medium">{i.description}</div>
                  <div className="mt-1 text-muted-foreground">
                    التصنيف: {CLASSIFICATION_AR[i.classification!]}
                  </div>
                  <div className="mt-1">اعتراض العميل: {i.objection_text}</div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}

      {/* Classification queue for submitted-but-unclassified items */}
      {items.some(
        (i) => !i.classification && rounds.find((r) => r.id === i.round_id)?.status !== "open",
      ) && (
        <div className="surface-card p-6">
          <h3 className="flex items-center gap-2 font-display text-base font-semibold">
            <Gavel className="size-4 text-primary" /> بانتظار التصنيف
          </h3>
          <ul className="mt-4 space-y-3">
            {items
              .filter(
                (i) =>
                  !i.classification && rounds.find((r) => r.id === i.round_id)?.status !== "open",
              )
              .map((i) => (
                <ItemCard
                  key={i.id}
                  item={i}
                  isAdmin={!!me?.isAdmin}
                  onClassify={(v) => classify.mutate({ item: i, value: v })}
                  onObject={() => setObjecting(i.id)}
                  objecting={false}
                  objection={objection}
                  setObjection={setObjection}
                  onSubmitObjection={() => {}}
                  onCancelObjection={() => {}}
                  onConvert={() => onConvertToCR(i)}
                />
              ))}
          </ul>
        </div>
      )}

      <AlertDialog open={!!confirmSubmit} onOpenChange={(v) => !v && setConfirmSubmit(null)}>
        <AlertDialogContent dir="rtl" className="text-start">
          <AlertDialogHeader className="text-start">
            <AlertDialogTitle className="font-display">
              إرسال نهائي لملاحظات الجولة
            </AlertDialogTitle>
            <AlertDialogDescription className="leading-7">
              {FEEDBACK_SUBMIT_WARNING} أي ملاحظة جديدة بعد الإرسال تحتاج جولة مستقلة، وقد تكون
              مدفوعة إن استُنفدت الجولات المشمولة.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="gap-2 sm:justify-start">
            <AlertDialogAction onClick={() => confirmSubmit && submitRound.mutate(confirmSubmit)}>
              <Lock className="size-4" /> إرسال وإقفال النافذة
            </AlertDialogAction>
            <AlertDialogCancel>رجوع</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function ItemCard({
  item,
  isAdmin,
  onClassify,
  onObject,
  objecting,
  objection,
  setObjection,
  onSubmitObjection,
  onCancelObjection,
  onConvert,
}: {
  item: FeedbackItem;
  isAdmin: boolean;
  onClassify: (v: Classification) => void;
  onObject: () => void;
  objecting: boolean;
  objection: string;
  setObjection: (v: string) => void;
  onSubmitObjection: () => void;
  onCancelObjection: () => void;
  onConvert: () => void;
}) {
  const left = item.classified_at ? hoursLeft(item.classified_at) : 0;
  const canObject = !!item.classified_at && !item.objection_at && left > 0;
  const paid = item.classification === "enhancement" || item.classification === "new_scope";

  return (
    <li className="rounded-xl border border-border/70 p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-sm">{item.description}</div>
          {item.page_or_section && (
            <div className="mt-0.5 text-xs text-muted-foreground">{item.page_or_section}</div>
          )}
        </div>
        {item.classification && (
          <span
            className={cn(
              "rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap",
              item.classification === "defect" && "bg-success/15 text-success-foreground",
              item.classification === "enhancement" && "bg-warn/15 text-warn-foreground",
              item.classification === "new_scope" && "bg-destructive/12 text-destructive",
            )}
          >
            {CLASSIFICATION_AR[item.classification]}
          </span>
        )}
      </div>

      {item.resolution && (
        <div className="mt-2 text-xs text-muted-foreground">
          النتيجة: {RESOLUTION_AR[item.resolution]}
        </div>
      )}

      {isAdmin && !item.classification && (
        <div className="mt-3 flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={() => onClassify("defect")}>
            🟢 عيب (مجاني)
          </Button>
          <Button size="sm" variant="outline" onClick={() => onClassify("enhancement")}>
            🟡 تحسين (مدفوع)
          </Button>
          <Button size="sm" variant="outline" onClick={() => onClassify("new_scope")}>
            🔴 نطاق جديد (مدفوع)
          </Button>
        </div>
      )}

      {item.classified_at && (
        <p className="mt-2 text-[11px] text-muted-foreground">
          {left > 0 ? (
            <>
              مهلة الاعتراض المتبقية: <Num value={Math.max(0, Math.floor(left))} /> ساعة و
              <Num value={Math.max(0, Math.round((left % 1) * 60))} /> دقيقة. {NON_DECISIVE_NOTE}
            </>
          ) : (
            <>انتهت مهلة الاعتراض (24 ساعة) — التصنيف نهائي.</>
          )}
        </p>
      )}

      {item.objection_text && (
        <div className="mt-2 rounded-lg bg-surface-elevated p-3 text-xs leading-6">
          اعتراض مسجَّل: {item.objection_text}
        </div>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        {canObject && !objecting && (
          <Button size="sm" variant="ghost" onClick={onObject}>
            اعتراض خلال المهلة
          </Button>
        )}
        {isAdmin && paid && (
          <Button size="sm" variant="secondary" onClick={onConvert}>
            تحويل إلى طلب تغيير
          </Button>
        )}
      </div>

      {objecting && (
        <div className="mt-3 space-y-2">
          <Textarea
            dir="rtl"
            rows={3}
            maxLength={1000}
            placeholder="استشهد بالبند المحدد في النطاق المعتمد أو في التصميم المعتمد…"
            value={objection}
            onChange={(e) => setObjection(e.target.value)}
          />
          <p className="text-[11px] text-muted-foreground">
            الاعتراض بلا استشهاد ببند محدد لا يُقبل، ولا يوقف تنفيذ باقي الملاحظات.
          </p>
          <div className="flex gap-2">
            <Button size="sm" onClick={onSubmitObjection}>
              تسجيل الاعتراض
            </Button>
            <Button size="sm" variant="ghost" onClick={onCancelObjection}>
              إلغاء
            </Button>
          </div>
        </div>
      )}
    </li>
  );
}
