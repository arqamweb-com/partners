import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Check, CircleSlash, Clock, FileText, Send, X } from "lucide-react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { useHolidays } from "@/hooks/useSettings";
import {
  CONTENT_BLOCKER_MESSAGE,
  CONTENT_STATUS_AR,
  NON_DECISIVE_NOTE,
  type ContentItem,
} from "@/lib/domain";
import { addBusinessDays, formatDateAr } from "@/lib/business-days";
import { cn } from "@/lib/utils";
import { Num } from "@/components/Num";
import { EmptyState } from "@/components/EmptyState";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Progress } from "@/components/ui/progress";

export function ContentChecklist({ projectId }: { projectId: string }) {
  const qc = useQueryClient();
  const { data: me } = useCurrentUser();
  const { data: holidays = [] } = useHolidays();
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  const { data: items = [], isLoading } = useQuery({
    queryKey: ["content-items", projectId],
    queryFn: () => api.content.list(projectId),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["content-items", projectId] });
    qc.invalidateQueries({ queryKey: ["project", projectId] });
  };

  /*
   * القبول التلقائي بعد يوم عمل انتقل للسيرفر: أمر arqam:auto-accept
   * مجدول كل ساعة. كان هنا في useEffect فلا يعمل إلا إن صادف أن موظفًا
   * فاتح الصفحة — والتزام تعاقدي لا يصح أن يتوقف على ذلك.
   */

  const submit = useMutation({
    mutationFn: async (item: ContentItem) => {
      const value = (draft[item.id] ?? item.value ?? "").trim();
      if (!value) throw new Error("اكتب المحتوى أو الرابط أولًا.");
      if (value.length > 4000) throw new Error("النص طويل جدًا (الحد 4000 حرف).");
      // تاريخ التقديم الأصلي وسجل التدقيق يتولاهما السيرفر
      await api.content.submit(item.id, value);
    },
    onSuccess: () => {
      toast.success("تم التقديم. مهلة مراجعتنا يوم عمل واحد.");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر التقديم."),
  });

  const review = useMutation({
    // مسار مستقل بصلاحية مستقلة: العميل يقدّم ولا يراجع
    mutationFn: async ({ item, accept }: { item: ContentItem; accept: boolean }) => {
      if (!accept && reason.trim().length < 5) throw new Error("سبب الرفض مطلوب ومكتوب بوضوح.");
      await api.content.review(item.id, accept, accept ? undefined : reason.trim());
    },
    onSuccess: () => {
      setRejecting(null);
      setReason("");
      invalidate();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر حفظ المراجعة."),
  });

  if (isLoading)
    return (
      <div className="surface-card mt-4 p-10 text-center text-sm text-muted-foreground">
        جارٍ التحميل…
      </div>
    );

  if (items.length === 0)
    return (
      <div className="surface-card mt-4 p-6">
        <EmptyState
          icon={FileText}
          title="لا توجد قائمة محتوى لهذا المشروع"
          hint="قائمة المحتوى تحدّد ما يجب أن يصل منك قبل بدء التصميم، وما يمكن أن يبدأ بمحتوى مبدئي."
        />
      </div>
    );

  const blocking = items.filter((i) => i.item_group === "blocking");
  const nonBlocking = items.filter((i) => i.item_group === "non_blocking");
  const blockingDone = blocking.filter((i) => i.status === "accepted").length;
  const blockingPct = blocking.length ? Math.round((blockingDone / blocking.length) * 100) : 100;

  const renderGroup = (group: ContentItem[], title: string, tone: "blocking" | "non_blocking") => {
    const done = group.filter((i) => i.status === "accepted").length;
    const pct = group.length ? Math.round((done / group.length) * 100) : 100;
    return (
      <div className="surface-card p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <span
              className={cn(
                "size-2.5 rounded-full",
                tone === "blocking" ? "bg-destructive" : "bg-warn",
              )}
            />
            <h3 className="font-display text-base font-semibold">{title}</h3>
          </div>
          <span className="text-xs text-muted-foreground">
            <Num value={done} /> / <Num value={group.length} /> · <Num value={pct} suffix="%" />
          </span>
        </div>
        <Progress value={pct} className="mt-3 h-1.5" />
        <p className="mt-3 text-xs leading-6 text-muted-foreground">
          {tone === "blocking"
            ? "لا يبدأ التصميم قبل قبول كل عناصر هذه المجموعة."
            : "يبدأ العمل بمحتوى مبدئي، ولكل عنصر موعد نهائي قبل مرحلة البرمجة."}
        </p>

        <ul className="mt-5 space-y-3">
          {group.map((item) => {
            const reviewDue = item.submitted_at
              ? addBusinessDays(new Date(item.submitted_at), 1, holidays)
              : null;
            return (
              <li
                key={item.id}
                className={cn(
                  "rounded-xl border p-4",
                  item.status === "accepted" && "border-success/40 bg-success/5",
                  item.status === "rejected" && "border-destructive/40 bg-destructive/5",
                  item.status === "submitted" && "border-warn/40 bg-warn/5",
                  item.status === "pending" && "border-border/70",
                )}
              >
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <div className="text-sm font-medium">{item.name}</div>
                    <div className="mt-0.5 text-xs leading-6 text-muted-foreground">
                      {item.acceptance_criteria}
                    </div>
                  </div>
                  <span
                    className={cn(
                      "rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap",
                      item.status === "accepted" && "bg-success/15 text-success-foreground",
                      item.status === "rejected" && "bg-destructive/12 text-destructive",
                      item.status === "submitted" && "bg-warn/15 text-warn-foreground",
                      item.status === "pending" && "bg-muted text-muted-foreground",
                    )}
                  >
                    {CONTENT_STATUS_AR[item.status]}
                    {item.auto_accepted ? " تلقائيًا" : ""}
                  </span>
                </div>

                {item.value && (
                  <p className="mt-3 rounded-lg bg-surface-elevated p-3 text-xs leading-6 whitespace-pre-wrap">
                    {item.value}
                  </p>
                )}

                {item.submitted_at && (
                  <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                    <span>تاريخ التقديم الأصلي: {formatDateAr(item.submitted_at)}</span>
                    {item.status === "submitted" && reviewDue && (
                      <span className="flex items-center gap-1">
                        <Clock className="size-3" /> مهلة مراجعتنا حتى {formatDateAr(reviewDue)} —
                        بعدها يُقبل العنصر تلقائيًا.
                      </span>
                    )}
                  </p>
                )}

                {item.status === "rejected" && (
                  <div className="mt-3 rounded-lg border border-destructive/30 bg-background p-3 text-xs leading-6">
                    <div className="flex items-center gap-1.5 font-medium text-destructive">
                      <CircleSlash className="size-3.5" /> سبب الرفض
                    </div>
                    <p className="mt-1">{item.rejection_reason}</p>
                    <p className="mt-2 text-muted-foreground">
                      الرفض لا يعيد ضبط العدّاد: التأخير يُحتسب بأثر رجعي من تاريخ التقديم الأصلي (
                      {formatDateAr(item.submitted_at)}) وليس من تاريخ الرفض.
                    </p>
                  </div>
                )}

                {item.status !== "accepted" && (
                  <div className="mt-3 space-y-2">
                    <Textarea
                      dir="rtl"
                      rows={2}
                      maxLength={4000}
                      placeholder="اكتب المحتوى أو ضع رابط الملف…"
                      value={draft[item.id] ?? item.value ?? ""}
                      onChange={(e) => setDraft((d) => ({ ...d, [item.id]: e.target.value }))}
                    />
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={submit.isPending}
                      onClick={() => submit.mutate(item)}
                    >
                      <Send className="size-3.5" />
                      {item.status === "rejected" ? "إعادة التقديم" : "تقديم للمراجعة"}
                    </Button>
                  </div>
                )}

                {me?.isAdmin && item.status === "submitted" && (
                  <div className="mt-3 space-y-2 border-t border-border/70 pt-3">
                    {rejecting === item.id ? (
                      <>
                        <Textarea
                          dir="rtl"
                          rows={2}
                          maxLength={1000}
                          placeholder="سبب الرفض — اذكر المعيار الذي لم يتحقق."
                          value={reason}
                          onChange={(e) => setReason(e.target.value)}
                        />
                        <p className="text-[11px] text-muted-foreground">
                          الرفض لا يوقف ولا يعيد ضبط عدّاد التأخير — يبقى محسوبًا من التقديم الأصلي.
                        </p>
                        <div className="flex gap-2">
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => review.mutate({ item, accept: false })}
                          >
                            تأكيد الرفض
                          </Button>
                          <Button size="sm" variant="ghost" onClick={() => setRejecting(null)}>
                            إلغاء
                          </Button>
                        </div>
                      </>
                    ) : (
                      <div className="flex gap-2">
                        <Button size="sm" onClick={() => review.mutate({ item, accept: true })}>
                          <Check className="size-3.5" /> قبول
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setRejecting(item.id)}>
                          <X className="size-3.5" /> رفض مع سبب
                        </Button>
                      </div>
                    )}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    );
  };

  return (
    <div className="mt-4 space-y-6">
      {blockingPct < 100 && (
        <div className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <p className="leading-6">
            {CONTENT_BLOCKER_MESSAGE} المكتمل الآن <Num value={blockingPct} suffix="%" /> فقط.{" "}
            {NON_DECISIVE_NOTE}
          </p>
        </div>
      )}
      {renderGroup(blocking, "المحتوى الحاكم", "blocking")}
      {renderGroup(nonBlocking, "المحتوى القابل للاستبدال", "non_blocking")}
    </div>
  );
}
