import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { Bell, CheckCheck } from "lucide-react";
import { api, type AppNotification } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ScrollArea } from "@/components/ui/scroll-area";
import { EmptyState } from "@/components/EmptyState";
import { Num } from "@/components/Num";
import { cn } from "@/lib/utils";

/**
 * جرس الإشعارات.
 *
 * التحديث بالاستطلاع كل 30 ثانية لا بالبث الحيّ: البث يحتاج عملية دائمة
 * (Reverb أو ما يشبهه) لا تتوفر على الاستضافة المشتركة التي بُني عليها
 * المشروع. الاستطلاع نداء واحد خفيف، ويتوقف تلقائيًا حين تكون النافذة
 * في الخلفية.
 */

const POLL_MS = 30_000;

/** «من دقيقتين»، «من 3 ساعات» — أقرب للقراءة من تاريخ كامل. */
function relativeAr(iso: string): string {
  const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

  if (seconds < 60) return "الآن";
  if (seconds < 3600) return `من ${Math.floor(seconds / 60)} دقيقة`;
  if (seconds < 86400) return `من ${Math.floor(seconds / 3600)} ساعة`;
  if (seconds < 604800) return `من ${Math.floor(seconds / 86400)} يوم`;

  return new Date(iso).toLocaleDateString("ar-EG", {
    day: "numeric",
    month: "long",
  });
}

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const qc = useQueryClient();
  const navigate = useNavigate();

  const { data } = useQuery({
    queryKey: ["notifications"],
    queryFn: () => api.notifications.list(),
    refetchInterval: POLL_MS,
    // لا نستطلع ونحن في تبويب آخر — لا فائدة، وهو استهلاك بلا مقابل
    refetchIntervalInBackground: false,
    staleTime: POLL_MS / 2,
  });

  const items = data?.data ?? [];
  const unread = data?.unread ?? 0;

  const markAllRead = useMutation({
    mutationFn: () => api.notifications.markAllRead(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["notifications"] }),
  });

  function openNotification(item: AppNotification) {
    setOpen(false);

    // الرابط يبنيه السيرفر مع الإشعار، فلا تعيد الواجهة تركيبه
    if (item.data.url) {
      navigate({ to: item.data.url });
    }

    if (unread > 0) markAllRead.mutate();
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative"
          aria-label={unread > 0 ? `الإشعارات، ${unread} غير مقروء` : "الإشعارات"}
        >
          <Bell className="size-4" />
          {unread > 0 && (
            <span
              className={cn(
                "absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center",
                "rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4",
                "text-destructive-foreground",
              )}
            >
              <Num value={unread > 99 ? 99 : unread} />
            </span>
          )}
        </Button>
      </PopoverTrigger>

      <PopoverContent align="end" className="w-[min(22rem,calc(100vw-2rem))] p-0">
        <div className="flex items-center justify-between border-b border-border px-4 py-3">
          <h3 className="font-display text-sm font-semibold">الإشعارات</h3>
          {unread > 0 && (
            <button
              type="button"
              className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              onClick={() => markAllRead.mutate()}
              disabled={markAllRead.isPending}
            >
              <CheckCheck className="size-3.5" />
              تعليم الكل مقروء
            </button>
          )}
        </div>

        {items.length === 0 ? (
          <div className="p-6">
            <EmptyState
              icon={Bell}
              title="مفيش إشعارات"
              hint="هيوصلك هنا كل ما تتحرك مرحلة أو يوصلك طلب تغيير."
            />
          </div>
        ) : (
          <ScrollArea className="max-h-[22rem]">
            <ul className="divide-y divide-border">
              {items.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => openNotification(item)}
                    className={cn(
                      "w-full px-4 py-3 text-start transition-colors hover:bg-surface-elevated",
                      !item.read_at && "bg-primary/5",
                    )}
                  >
                    <div className="flex items-start gap-2">
                      {!item.read_at && (
                        <span
                          className="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary"
                          aria-hidden
                        />
                      )}
                      <div className={cn("min-w-0 flex-1", item.read_at && "ps-3.5")}>
                        <div className="text-sm font-medium">{item.data.title}</div>
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                          {item.data.body}
                        </p>
                        <div className="mt-1 text-[11px] text-muted-foreground">
                          {relativeAr(item.created_at)}
                        </div>
                      </div>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          </ScrollArea>
        )}
      </PopoverContent>
    </Popover>
  );
}
