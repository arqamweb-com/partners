import type { ReactNode } from "react";
import { Link, useNavigate, useRouterState } from "@tanstack/react-router";
import {
  BarChart3,
  LayoutGrid,
  LogOut,
  Plus,
  Settings,
  ShieldCheck,
  Users,
  Workflow,
} from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { api } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { Button } from "@/components/ui/button";
import { NotificationBell } from "@/components/NotificationBell";
import { cn } from "@/lib/utils";

export function AppShell({ children }: { children: ReactNode }) {
  const { data: me } = useCurrentUser();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const pathname = useRouterState({ select: (s) => s.location.pathname });

  async function signOut() {
    await queryClient.cancelQueries();
    queryClient.clear();
    await api.auth.logout();
    navigate({ to: "/auth", replace: true });
  }

  return (
    <div className="min-h-screen bg-surface">
      <header className="sticky top-0 z-30 border-b border-border bg-background/85 backdrop-blur print:hidden">
        <div className="mx-auto flex min-h-16 max-w-[1400px] flex-wrap items-center gap-x-6 gap-y-2 px-4 py-2 sm:px-6">
          <Link to="/dashboard" className="flex items-center gap-2.5">
            <span className="gradient-primary flex size-9 items-center justify-center rounded-xl text-primary-foreground">
              <Workflow className="size-4.5" />
            </span>
            <span className="font-display text-lg font-semibold">أرقام فلو</span>
          </Link>

          <nav className="order-3 flex w-full items-center gap-1 overflow-x-auto sm:order-none sm:w-auto">
            <NavLink to="/dashboard" active={pathname.startsWith("/dashboard")} icon={LayoutGrid}>
              المشاريع
            </NavLink>
            {/* إنشاء المشروع متاح للعميل أيضًا — يسجّل بيانات مشروعه بنفسه */}
            <NavLink to="/projects/new" active={pathname === "/projects/new"} icon={Plus}>
              مشروع جديد
            </NavLink>
            {me?.isAdmin && (
              <>
                <NavLink to="/reports" active={pathname.startsWith("/reports")} icon={BarChart3}>
                  التقرير الشهري
                </NavLink>
                <NavLink to="/settings" active={pathname.startsWith("/settings")} icon={Settings}>
                  الإعدادات
                </NavLink>
              </>
            )}
            {/* الحسابات والأدوار للأدمن وحده — لا للمدير ولا للمشرف */}
            {me?.isSuperUser && (
              <NavLink to="/users" active={pathname.startsWith("/users")} icon={Users}>
                الحسابات
              </NavLink>
            )}
          </nav>

          <div className="ms-auto flex items-center gap-3">
            <NotificationBell />
            <div className="hidden text-end leading-tight sm:block">
              <div className="text-sm font-medium">{me?.fullName ?? "..."}</div>
              <div className="text-xs text-muted-foreground">
                {me?.isAdmin ? "فريق أرقام" : (me?.agency ?? "وكالة شريكة")}
              </div>
            </div>
            <Button variant="ghost" size="icon" onClick={signOut} aria-label="تسجيل الخروج">
              <LogOut className="size-4" />
            </Button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 sm:py-8 print:px-0 print:py-0">
        {children}
      </main>

      {me && !me.isAdmin && (
        <footer className="mt-4 border-t border-border bg-background/60 print:hidden">
          <div className="mx-auto flex max-w-[1400px] items-start gap-2 px-4 py-4 text-xs leading-6 text-muted-foreground sm:px-6">
            <ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-primary" />
            <p>
              القناة الرسمية الوحيدة لطلبات هذا المشروع. الطلبات خارج النظام لا تُسجّل ولا تُنفّذ.
            </p>
          </div>
        </footer>
      )}
    </div>
  );
}

function NavLink({
  to,
  active,
  icon: Icon,
  children,
}: {
  to: string;
  active: boolean;
  icon: typeof LayoutGrid;
  children: ReactNode;
}) {
  return (
    <Link
      to={to}
      className={cn(
        "flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground",
        active && "bg-accent text-foreground",
      )}
    >
      <Icon className="size-4" />
      {children}
    </Link>
  );
}
