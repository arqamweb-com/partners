import { useEffect, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { CalendarDays, Plus, Save, SlidersHorizontal, Tags, X } from "lucide-react";
import { toast } from "sonner";
import { supabase } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { usePriceList, useSettings } from "@/hooks/useSettings";
import { useQuery } from "@tanstack/react-query";
import { formatDateAr } from "@/lib/business-days";
import { EmptyState } from "@/components/EmptyState";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export const Route = createFileRoute("/_authenticated/settings")({
  head: () => ({
    meta: [
      { title: "الإعدادات | أرقام فلو" },
      {
        name: "description",
        content: "العطل الرسمية، المدد الافتراضية، قوائم أسعار التغييرات، وحدود التأخير.",
      },
      { property: "og:title", content: "إعدادات أرقام فلو" },
      { property: "og:description", content: "ضبط قواعد العمل التي تسري على كل المشاريع." },
    ],
  }),
  component: SettingsPage,
});

function SettingsPage() {
  const { data: me } = useCurrentUser();
  const qc = useQueryClient();
  const { data: settings } = useSettings();
  const { data: holidays = [] } = useQuery({
    queryKey: ["holiday-rows"],
    queryFn: async () => {
      const { data } = await supabase.from("holidays").select("*").order("holiday_date");
      return data ?? [];
    },
  });
  const { data: prices = [] } = usePriceList();

  const [form, setForm] = useState<Record<string, number>>({});
  useEffect(() => {
    if (!settings) return;
    setForm({
      warranty_days: settings.warranty_days,
      revision_rounds_allowed: settings.revision_rounds_allowed,
      warning_threshold_days: settings.warning_threshold_days,
      freeze_threshold_days: settings.freeze_threshold_days,
      reactivation_fee: Number(settings.reactivation_fee),
    });
  }, [settings]);

  const [holiday, setHoliday] = useState({ date: "", label: "" });
  const [price, setPrice] = useState({ name: "", amount: 0, days: 0 });

  const saveSettings = useMutation({
    mutationFn: async () => {
      if (!settings) return;
      const { error } = await supabase
        .from("app_settings")
        .update(form as never)
        .eq("id", settings.id);
      if (error) throw error;
    },
    onSuccess: () => {
      toast.success("حُفظت الإعدادات.");
      qc.invalidateQueries({ queryKey: ["app-settings"] });
    },
    onError: () => toast.error("تعذّر الحفظ."),
  });

  const addHoliday = useMutation({
    mutationFn: async () => {
      if (!holiday.date) throw new Error("حدّد التاريخ.");
      const { error } = await supabase
        .from("holidays")
        .insert({ holiday_date: holiday.date, label: holiday.label.trim().slice(0, 200) });
      if (error) throw error;
    },
    onSuccess: () => {
      setHoliday({ date: "", label: "" });
      qc.invalidateQueries({ queryKey: ["holidays"] });
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت الإضافة."),
  });

  const removeHoliday = useMutation({
    mutationFn: async (id: string) => {
      const { error } = await supabase.from("holidays").delete().eq("id", id);
      if (error) throw error;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["holidays"] }),
  });

  const addPrice = useMutation({
    mutationFn: async () => {
      if (price.name.trim().length < 2) throw new Error("اسم البند مطلوب.");
      const { error } = await supabase.from("cr_price_items").insert({
        name: price.name.trim().slice(0, 200),
        price: price.amount,
        duration_days: price.days,
      });
      if (error) throw error;
    },
    onSuccess: () => {
      setPrice({ name: "", amount: 0, days: 0 });
      qc.invalidateQueries({ queryKey: ["cr-price-items"] });
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّرت الإضافة."),
  });

  const removePrice = useMutation({
    mutationFn: async (id: string) => {
      const { error } = await supabase.from("cr_price_items").delete().eq("id", id);
      if (error) throw error;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ["cr-price-items"] }),
  });

  if (me && !me.isAdmin) {
    return (
      <EmptyState
        title="الإعدادات لفريق أرقام فقط"
        hint="هذه الصفحة تضبط قواعد تسري على كل المشاريع، ولذلك تقتصر على المدير."
      />
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl font-semibold">الإعدادات</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          هذه القيم تسري على المشاريع الجديدة وعلى حسابات أيام العمل في كل المشاريع القائمة.
        </p>
      </div>

      <section className="surface-card p-6">
        <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
          <SlidersHorizontal className="size-4 text-primary" /> المدد والحدود الافتراضية
        </h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {(
            [
              ["warranty_days", "أيام الضمان بعد الإطلاق"],
              ["revision_rounds_allowed", "جولات التعديل المشمولة"],
              ["warning_threshold_days", "حد التنبيه لتأخير العميل (أيام عمل)"],
              ["freeze_threshold_days", "حد التجميد التلقائي (أيام عمل)"],
              ["reactivation_fee", "رسوم إعادة التنشيط (SAR)"],
            ] as const
          ).map(([key, label]) => (
            <div key={key} className="grid gap-1.5">
              <Label htmlFor={key}>{label}</Label>
              <Input
                id={key}
                type="number"
                min={0}
                className="num"
                value={form[key] ?? 0}
                onChange={(e) =>
                  setForm({ ...form, [key]: Math.max(0, Number(e.target.value) || 0) })
                }
              />
            </div>
          ))}
        </div>
        <Button
          className="mt-5"
          onClick={() => saveSettings.mutate()}
          disabled={saveSettings.isPending}
        >
          <Save className="size-4" /> حفظ الإعدادات
        </Button>
      </section>

      <section className="surface-card p-6">
        <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
          <CalendarDays className="size-4 text-primary" /> العطل الرسمية
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          أيام العمل هي الأحد إلى الخميس، وتُستثنى منها هذه التواريخ في كل الحسابات.
        </p>
        <div className="mt-4 flex flex-wrap items-end gap-2">
          <div className="grid gap-1.5">
            <Label htmlFor="hdate">التاريخ</Label>
            <Input
              id="hdate"
              type="date"
              className="num"
              value={holiday.date}
              onChange={(e) => setHoliday({ ...holiday, date: e.target.value })}
            />
          </div>
          <div className="grid flex-1 gap-1.5">
            <Label htmlFor="hlabel">المناسبة</Label>
            <Input
              id="hlabel"
              dir="rtl"
              maxLength={200}
              value={holiday.label}
              onChange={(e) => setHoliday({ ...holiday, label: e.target.value })}
              placeholder="عيد الفطر…"
            />
          </div>
          <Button variant="secondary" onClick={() => addHoliday.mutate()}>
            <Plus className="size-4" /> إضافة
          </Button>
        </div>

        {holidays.length === 0 ? (
          <p className="mt-4 text-sm text-muted-foreground">لا توجد عطل مسجّلة بعد.</p>
        ) : (
          <ul className="mt-4 divide-y divide-border/60">
            {holidays.map((h) => (
              <li key={h.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span>
                  <span className="num">{formatDateAr(h.holiday_date)}</span>
                  {h.label && <span className="text-muted-foreground"> — {h.label}</span>}
                </span>
                <button
                  type="button"
                  aria-label="حذف العطلة"
                  className="text-muted-foreground hover:text-destructive"
                  onClick={() => removeHoliday.mutate(h.id)}
                >
                  <X className="size-4" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="surface-card p-6">
        <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
          <Tags className="size-4 text-primary" /> قائمة أسعار التغييرات
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          تسعيرة معلنة مسبقًا تُستخدم عند تسعير طلبات التغيير، فلا يبدو السعر ارتجاليًا.
        </p>
        <div className="mt-4 flex flex-wrap items-end gap-2">
          <div className="grid flex-1 gap-1.5">
            <Label htmlFor="pname">البند</Label>
            <Input
              id="pname"
              dir="rtl"
              maxLength={200}
              value={price.name}
              onChange={(e) => setPrice({ ...price, name: e.target.value })}
              placeholder="صفحة إضافية…"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="pamount">السعر (SAR)</Label>
            <Input
              id="pamount"
              type="number"
              min={0}
              className="num w-32"
              value={price.amount}
              onChange={(e) =>
                setPrice({ ...price, amount: Math.max(0, Number(e.target.value) || 0) })
              }
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="pdays">أيام إضافية</Label>
            <Input
              id="pdays"
              type="number"
              min={0}
              className="num w-28"
              value={price.days}
              onChange={(e) =>
                setPrice({ ...price, days: Math.max(0, Number(e.target.value) || 0) })
              }
            />
          </div>
          <Button variant="secondary" onClick={() => addPrice.mutate()}>
            <Plus className="size-4" /> إضافة
          </Button>
        </div>

        {prices.length === 0 ? (
          <p className="mt-4 text-sm text-muted-foreground">لا توجد بنود تسعير بعد.</p>
        ) : (
          <ul className="mt-4 divide-y divide-border/60">
            {prices.map((p) => (
              <li key={p.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span>{p.name}</span>
                <span className="flex items-center gap-4 text-muted-foreground">
                  <Num value={Number(p.price)} suffix={` ${p.currency}`} />
                  <span>
                    +<Num value={p.duration_days} /> يوم
                  </span>
                  <button
                    type="button"
                    aria-label="حذف البند"
                    className="hover:text-destructive"
                    onClick={() => removePrice.mutate(p.id)}
                  >
                    <X className="size-4" />
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
