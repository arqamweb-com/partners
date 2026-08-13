import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

/**
 * الإعدادات والأجازات وبنود التسعير — نداء واحد بدل ثلاثة.
 * كان كل واحد استعلامًا مستقلًا على جدوله؛ الآن مسار واحد يعيدها معًا.
 */
function useSettingsPayload() {
  return useQuery({
    queryKey: ["settings"],
    queryFn: () => api.settings.all(),
    staleTime: 5 * 60 * 1000,
  });
}

export function useSettings() {
  const query = useSettingsPayload();
  return { ...query, data: query.data?.settings ?? null };
}

export function usePriceList() {
  const query = useSettingsPayload();
  return { ...query, data: query.data?.price_items ?? [] };
}

/** تواريخ الأجازات فقط — بها تُحسب أيام العمل في الواجهة. */
export function useHolidays() {
  const query = useSettingsPayload();
  return { ...query, data: (query.data?.holidays ?? []).map((h) => h.holiday_date) };
}

/** الأجازات كاملة بمعرّفاتها — لصفحة الإعدادات. */
export function useHolidayRecords() {
  const query = useSettingsPayload();
  return { ...query, data: query.data?.holidays ?? [] };
}
