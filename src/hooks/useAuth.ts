import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { api, type CurrentUser } from "@/lib/api";

/**
 * الجلسة والمستخدم الحالي.
 *
 * ما تغيّر: لم يعد المتصفح يجلب الملف الشخصي والأدوار في استعلامين ثم
 * يستنتج isAdmin منهما. السيرفر يعيد الدور وصلاحياته محسوبة، فمصدر
 * الحقيقة واحد ولا يمكن للواجهة أن تخالفه.
 */
export function useSession() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const unsubscribe = api.auth.onChange((_event, next) => {
      setUser(next);
      setReady(true);
    });

    return () => {
      unsubscribe();
    };
  }, []);

  return { user, ready, session: user ? { user } : null };
}

/**
 * صلاحيات المستخدم على مستوى النظام.
 *
 * `isAdmin` باقٍ كاسم لأن معظم الواجهة تسأل «هل هذا من فريق أرقام؟» —
 * وهو الآن `is_staff` القادم من السيرفر. أما ما يخص التسعير والبنود
 * التعاقدية فله `canPrice`، لأن المشرف من الفريق ولا يسعّر.
 */
export function useCurrentUser() {
  return useQuery({
    queryKey: ["current-user"],
    queryFn: async () => {
      const user = await api.auth.me();
      if (!user) return null;

      return {
        id: user.id,
        email: user.email,
        fullName: user.full_name || user.email,
        agency: user.agency_name,
        role: user.system_role,
        roleLabel: user.role_label,
        /** من فريق أرقام — أدمن أو مدير أو مشرف. */
        isAdmin: user.is_staff,
        /** التسعير والبنود التعاقدية — أدمن أو مدير فقط. */
        canPrice: user.can_price,
        /** إعدادات النظام وإدارة الحسابات. */
        isSuperUser: user.system_role === "admin",
        partnerAgency: user.partner_agency,
      };
    },
  });
}

export type AppUser = NonNullable<ReturnType<typeof useCurrentUser>["data"]>;
