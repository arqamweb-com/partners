import { useState } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Bell, CheckCheck, ChevronLeft, ChevronRight } from "lucide-react";

import { api, type AppNotification } from "@/lib/api";
import { relativeAr } from "@/lib/business-days";
import { EmptyState } from "@/components/EmptyState";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";

/**
 * كل الإشعارات.
 *
 * الجرس نافذة على آخر ما حدث، وهذه الصفحة هي السجل. الفرق ليس في الحجم
 * وحده: في الجرس لا معنى لتصفّح ولا لتصفية — من يفتحه يريد أحدث خبر
 * ويغلقه. ومن يفتح هذه الصفحة يبحث عن شيء بعينه، فله التصفية والصفحات.
 *
 * ولاحظ أن «تعليم الكل مقروء» بقي فعلًا صريحًا بزرار، بينما فتح إشعار
 * يعلّمه هو وحده. الأول اختيار، والثاني نتيجة طبيعية للقراءة.
 */

const PER_PAGE = 25;

export const Route = createFileRoute("/_authenticated/notifications")({
  head: () => ({
    meta: [
      { title: "الإشعارات | أرقام ويب" },
      {
        name: "description",
        content: "كل إشعاراتك: تحرّك المراحل، طلبات التغيير، وجولات الملاحظات.",
      },
    ],
  }),
  component: NotificationsPage,
});

function NotificationsPage() {
  const [filter, setFilter] = useState<"all" | "unread">("all");
  const [page, setPage] = useState(1);

  const qc = useQueryClient();
  const navigate = useNavigate();

  const { data, isLoading, error } = useQuery({
    queryKey: ["notifications", { filter, page, per_page: PER_PAGE }],
    queryFn: () => api.notifications.list({ filter, page, per_page: PER_PAGE }),
    // الصفحة السابقة تبقى معروضة أثناء جلب التالية — لا وميض فراغ
    placeholderData: keepPreviousData,
  });

  const items = data?.data ?? [];
  const unread = data?.unread ?? 0;
  const lastPage = data?.last_page ?? 1;

  const markRead = useMutation({
    mutationFn: (id: string) => api.notifications.markRead(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["notifications"] }),
  });

  const markAllRead = useMutation({
    mutationFn: () => api.notifications.markAllRead(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["notifications"] }),
  });

  function changeFilter(next: string) {
    setFilter(next === "unread" ? "unread" : "all");
    setPage(1); // الصفحة الخامسة من قائمة أطول قد لا توجد في الأقصر
  }

  function openNotification(item: AppNotification) {
    if (!item.read_at) markRead.mutate(item.id);
    if (item.data.url) navigate({ to: item.data.url });
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-display text-2xl font-semibold">الإشعارات</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {unread > 0 ? (
              <>
                <Num value={unread} /> إشعار لم يُقرأ بعد.
              </>
            ) : (
              "كل الإشعارات مقروءة."
            )}
          </p>
        </div>

        {unread > 0 && (
          <Button
            variant="outline"
            onClick={() => markAllRead.mutate()}
            disabled={markAllRead.isPending}
          >
            <CheckCheck className="size-4" />
            تعليم الكل مقروء
          </Button>
        )}
      </div>

      <Tabs value={filter} onValueChange={changeFilter} dir="rtl">
        <TabsList>
          <TabsTrigger value="all">الكل</TabsTrigger>
          <TabsTrigger value="unread">
            غير المقروء
            {unread > 0 && (
              <span className="ms-1.5 rounded-full bg-primary/15 px-1.5 text-[11px] text-primary">
                <Num value={unread} />
              </span>
            )}
          </TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="surface-card overflow-hidden">
        {isLoading && <p className="p-10 text-center text-muted-foreground">جارٍ التحميل…</p>}

        {error && <p className="p-10 text-center text-destructive">تعذّر تحميل الإشعارات.</p>}

        {!isLoading && !error && items.length === 0 && (
          <div className="p-10">
            <EmptyState
              icon={Bell}
              title={filter === "unread" ? "مفيش إشعارات غير مقروءة" : "مفيش إشعارات"}
              hint="هيوصلك هنا كل ما تتحرك مرحلة أو يوصلك طلب تغيير."
            />
          </div>
        )}

        {items.length > 0 && (
          <ul className="divide-y divide-border">
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  onClick={() => openNotification(item)}
                  className={cn(
                    "flex w-full items-start gap-3 px-5 py-4 text-start transition-colors",
                    "hover:bg-surface-elevated",
                    !item.read_at && "bg-primary/5",
                  )}
                >
                  <span
                    className={cn(
                      "mt-2 size-1.5 shrink-0 rounded-full",
                      item.read_at ? "bg-transparent" : "bg-primary",
                    )}
                    aria-hidden
                  />
                  <div className="min-w-0 flex-1">
                    <div className="text-sm font-medium">{item.data.title}</div>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">{item.data.body}</p>
                  </div>
                  <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground">
                    {relativeAr(item.created_at)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {lastPage > 1 && (
        <div className="flex items-center justify-between gap-4">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            <ChevronRight className="size-4" />
            الأحدث
          </Button>

          <span className="text-sm text-muted-foreground">
            صفحة <Num value={page} /> من <Num value={lastPage} />
          </span>

          <Button
            variant="outline"
            disabled={page >= lastPage}
            onClick={() => setPage((p) => p + 1)}
          >
            الأقدم
            <ChevronLeft className="size-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
