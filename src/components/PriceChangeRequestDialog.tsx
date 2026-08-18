import { useEffect, useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Send } from "lucide-react";
import { toast } from "sonner";

import { api } from "@/lib/api";
import type { ChangeRequest } from "@/lib/domain";
import { usePriceList, useHolidays } from "@/hooks/useSettings";
import { addBusinessDays, addDays, isoDate } from "@/lib/business-days";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * تسعير طلب تغيير سجّله العميل.
 *
 * ═══ ما الذي كان ناقصًا ═══
 *
 * العميل يسجّل الطلب بلا سعر — وهذا صحيح ومقصود، فالتسعير ليس صلاحيته.
 * لكن الشاشة لم تكن تعطي فريق أرقام أي مكان لكتابة السعر بعد ذلك: الطلب
 * الوارد كان له زرّ «إرسال للعميل» وحده، وهو يرسل بالسعر المخزَّن — أي
 * صفرًا. فكان الطلب يعود للعميل مجانًا، أو لا يعود أصلًا.
 *
 * نافذة إنشاء الطلب فيها حقول تسعير، لكنها تُنشئ طلبًا جديدًا؛ ولا تصلح
 * لطلب قائم كتبه العميل بكلماته.
 *
 * ═══ لماذا العنوان والوصف للقراءة فقط ═══
 *
 * لأنهما كلام العميل، وهما ما سيعتمده. تعديلهما من جهتنا يجعل الاعتماد
 * على نصّ غير الذي طلبه.
 */
export function PriceChangeRequestDialog({
  cr,
  open,
  onOpenChange,
  onPriced,
}: {
  cr: ChangeRequest | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPriced?: () => void;
}) {
  const { data: prices = [] } = usePriceList();
  const { data: holidays = [] } = useHolidays();

  const [price, setPrice] = useState("");
  const [durationDays, setDurationDays] = useState("");
  const [impactDays, setImpactDays] = useState("");

  /*
   * النافذة تُعاد استعمالها لطلبات مختلفة، فلا يبقى فيها تسعير سابق.
   *
   * التتبّع على cr.id لا على cr نفسه: الكائن يتغيّر هويّته مع كل إعادة
   * جلب للقائمة، فربط الأثر به يمسح ما يكتبه المستخدم تحت يده.
   */
  useEffect(() => {
    if (!open || !cr) return;

    setPrice(String(cr.price || ""));
    setDurationDays(String(cr.duration_days || ""));
    setImpactDays(String(cr.delivery_impact_days || ""));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cr?.id, open]);

  const send = useMutation({
    mutationFn: () => {
      if (!cr) throw new Error("لا يوجد طلب.");

      const value = Number(price);
      if (!Number.isFinite(value) || value < 0) throw new Error("اكتب سعرًا صحيحًا.");

      return api.changeRequests.send(cr.id, {
        price: value,
        duration_days: Number(durationDays) || 0,
        delivery_impact_days: Number(impactDays) || 0,
        // نفس مهل النافذة الأخرى: العرض ١٤ يومًا، والقرار ٣ أيام عمل
        quote_valid_until: isoDate(addDays(new Date(), 14)),
        decision_deadline: isoDate(addBusinessDays(new Date(), 3, holidays)),
      });
    },
    onSuccess: () => {
      onOpenChange(false);
      toast.success("اتسعّر الطلب واتبعت للعميل للاعتماد.");
      onPriced?.();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر الإرسال."),
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-lg text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="font-display">تسعير طلب التغيير</DialogTitle>
          <DialogDescription>
            السعر والمدة وأثر التسليم تُعرض على العميل، ولا يبدأ العمل قبل اعتماده كتابيًا.
          </DialogDescription>
        </DialogHeader>

        {/* كلام العميل كما كتبه — مرجع التسعير ولا يُعدَّل من جهتنا */}
        <div className="rounded-xl border border-border bg-surface-elevated p-3">
          <div className="text-[11px] text-muted-foreground">طلب العميل</div>
          <div className="mt-1 text-sm font-medium">{cr?.title}</div>
          {cr?.description && (
            <p className="mt-1.5 text-xs leading-6 text-muted-foreground">{cr.description}</p>
          )}
        </div>

        {prices.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {prices.map((p) => (
              <Button
                key={p.id}
                size="sm"
                variant="outline"
                type="button"
                onClick={() => {
                  setPrice(String(p.price));
                  setDurationDays(String(p.duration_days));
                  setImpactDays(String(p.duration_days));
                }}
              >
                {p.name} · <span className="num">{p.price}</span>
              </Button>
            ))}
          </div>
        )}

        <div className="grid grid-cols-3 gap-3">
          <NumField label="السعر" value={price} onChange={setPrice} />
          <NumField label="مدة التنفيذ" value={durationDays} onChange={setDurationDays} />
          <NumField label="أثر التسليم" value={impactDays} onChange={setImpactDays} />
        </div>

        <DialogFooter className="gap-2 sm:justify-start">
          <Button onClick={() => send.mutate()} disabled={send.isPending}>
            <Send className="size-4" />
            {send.isPending ? "جارٍ الإرسال…" : "تسعير وإرسال للعميل"}
          </Button>
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            رجوع
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function NumField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="grid gap-1.5">
      <Label className="text-xs">{label}</Label>
      <Input
        type="number"
        min={0}
        inputMode="numeric"
        className="num"
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}
