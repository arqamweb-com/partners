import { useQuery } from "@tanstack/react-query";
import { supabase } from "@/lib/api";
import type { AppSettings, PriceItem } from "@/lib/domain";

export function useSettings() {
  return useQuery({
    queryKey: ["app-settings"],
    queryFn: async () => {
      const { data } = await supabase.from("app_settings").select("*").maybeSingle();
      return (data ?? null) as AppSettings | null;
    },
  });
}

export function usePriceList() {
  return useQuery({
    queryKey: ["cr-price-items"],
    queryFn: async () => {
      const { data } = await supabase.from("cr_price_items").select("*").order("created_at");
      return (data ?? []) as PriceItem[];
    },
  });
}

export function useHolidays() {
  return useQuery({
    queryKey: ["holidays"],
    queryFn: async () => {
      const { data } = await supabase.from("holidays").select("holiday_date").order("holiday_date");
      return (data ?? []).map((h) => h.holiday_date);
    },
  });
}
