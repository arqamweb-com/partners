import { useEffect, useMemo, useState } from "react";
import { createFileRoute } from "@tanstack/react-router";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { KeyRound, Search, ShieldCheck, Trash2, UserPen, UserPlus } from "lucide-react";
import { toast } from "sonner";

import { api, type ManagedUser, type SystemRole, type UserInput } from "@/lib/api";
import { useCurrentUser } from "@/hooks/useAuth";
import { formatDateAr } from "@/lib/business-days";
import { EmptyState } from "@/components/EmptyState";
import { Num } from "@/components/Num";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

/**
 * الحسابات والصلاحيات.
 *
 * كانت الأدوار تُصنع من التيرمينال وحده (php artisan arqam:user). الأمر
 * باقٍ، لكن من يدير الفريق يوميًا ليس بالضرورة من يملك وصولًا للسيرفر —
 * فهذه الشاشة تفتح نفس الأفعال لمن يملكها في النظام.
 *
 * ملاحظتان في التصميم:
 *
 * ١) الدور والتفعيل يُعدَّلان من الجدول مباشرة لا من نافذة تعديل — لأنهما
 *    الفعل الأكثر تكرارًا («ارفع فلان مشرفًا»، «أوقف حساب من غادر»)،
 *    وبقية الحقول بيانات وصفية تُراجَع مرة كل حين.
 *
 * ٢) لا شيء هنا يقرّر الصلاحية. كل ما تراه من تعطيل أزرار هو شرحٌ لقاعدة
 *    يفرضها السيرفر أصلًا (UserPolicy): الأدمن لا يحكم نفسه، والنظام لا
 *    يبقى بلا أدمن نشط، ومالك المشاريع يُعطَّل ولا يُحذف.
 */

const ROLES: { value: SystemRole; label: string; hint: string }[] = [
  { value: "admin", label: "أدمن", hint: "كل شيء: الحسابات، إعدادات النظام، الحذف." },
  {
    value: "manager",
    label: "مدير",
    hint: "كل المشاريع: تسعير واعتماد وتقارير. بلا حسابات ولا إعدادات.",
  },
  {
    value: "supervisor",
    label: "مشرف",
    hint: "المشاريع المسندة إليه فقط. ينفّذ ويقدّم ولا يسعّر.",
  },
  { value: "partner", label: "شريك", hint: "مشاريع وكالته فقط، ويتعامل كطرف مستلِم أمام أرقام." },
  { value: "client", label: "عميل", hint: "مشاريعه هو فقط." },
];

export const Route = createFileRoute("/_authenticated/users")({
  head: () => ({
    meta: [
      { title: "الحسابات والصلاحيات | أرقام ويب" },
      {
        name: "description",
        content: "إضافة المستخدمين وتعديل بياناتهم وأدوارهم في النظام وإيقاف الحسابات.",
      },
      { property: "og:title", content: "الحسابات والصلاحيات" },
      { property: "og:description", content: "من يدخل النظام، وبأي صفة، وإلى أي حد." },
    ],
  }),
  component: UsersPage,
});

function UsersPage() {
  const { data: me } = useCurrentUser();
  const qc = useQueryClient();

  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [role, setRole] = useState<string>("all");
  const [status, setStatus] = useState<string>("all");

  // البحث لا يلاحق كل حرف — نصف ثانية سكون قبل الطلب
  useEffect(() => {
    const timer = setTimeout(() => setQ(search.trim()), 400);
    return () => clearTimeout(timer);
  }, [search]);

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ManagedUser | null>(null);
  const [passwordFor, setPasswordFor] = useState<ManagedUser | null>(null);
  const [deleting, setDeleting] = useState<ManagedUser | null>(null);
  /** تحويل حساب إلى «شريك» يحتاج اسم وكالة، وإلا لن يرى شيئًا. */
  const [toPartner, setToPartner] = useState<ManagedUser | null>(null);

  const filters = useMemo(
    () => ({
      q,
      role: role === "all" ? "" : role,
      status: status === "all" ? "" : status,
      per_page: 200,
    }),
    [q, role, status],
  );

  const { data, isLoading } = useQuery({
    queryKey: ["users", filters],
    queryFn: () => api.users.list(filters),
    enabled: me?.isSuperUser === true,
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ["users"] });
  const fail = (fallback: string) => (e: unknown) =>
    toast.error(e instanceof Error ? e.message : fallback);

  const update = useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<UserInput> }) =>
      api.users.update(id, input),
    onSuccess: (user) => {
      toast.success(`حُدّث حساب ${user.full_name || user.email}.`);
      setEditing(null);
      setToPartner(null);
      refresh();
      // قد يكون المعدَّل هو المستخدم الحالي (اسمه مثلًا)، فترويسة الشاشة تتبع
      qc.invalidateQueries({ queryKey: ["current-user"] });
    },
    onError: fail("تعذّر حفظ التعديل."),
  });

  const remove = useMutation({
    mutationFn: (id: string) => api.users.remove(id),
    onSuccess: () => {
      toast.success("حُذف الحساب.");
      setDeleting(null);
      refresh();
    },
    onError: fail("تعذّر حذف الحساب."),
  });

  if (me && !me.isSuperUser) {
    return (
      <EmptyState
        icon={ShieldCheck}
        title="إدارة الحسابات للأدمن وحده"
        hint="هذه الشاشة تقرّر من يدخل النظام وبأي صفة، ولذلك لا تُفتح للمدير ولا للمشرف. راجع أدمن أرقام لأي تعديل على الحسابات."
      />
    );
  }

  const rows = data?.data ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-semibold">الحسابات والصلاحيات</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            الدور هنا يحدّد ما يراه صاحبه في كل المشاريع. أما صفته داخل مشروع بعينه (مسؤول تنفيذ،
            عميل، مطّلع) فتُضبط من صفحة المشروع نفسه.
          </p>
        </div>
        <Button onClick={() => setCreating(true)}>
          <UserPlus className="size-4" />
          حساب جديد
        </Button>
      </div>

      <section className="surface-card p-4 sm:p-6">
        <div className="flex flex-wrap items-end gap-3">
          <div className="grid min-w-[220px] flex-1 gap-1.5">
            <Label htmlFor="user-search">بحث</Label>
            <div className="relative">
              <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted-foreground" />
              <Input
                id="user-search"
                className="ps-9"
                placeholder="بالاسم أو البريد أو الوكالة…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </div>

          <div className="grid min-w-[150px] gap-1.5">
            <Label htmlFor="user-role-filter">الدور</Label>
            <Select value={role} onValueChange={setRole}>
              <SelectTrigger id="user-role-filter">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">كل الأدوار</SelectItem>
                {ROLES.map((r) => (
                  <SelectItem key={r.value} value={r.value}>
                    {r.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid min-w-[150px] gap-1.5">
            <Label htmlFor="user-status-filter">الحالة</Label>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger id="user-status-filter">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">الكل</SelectItem>
                <SelectItem value="active">نشط</SelectItem>
                <SelectItem value="disabled">موقوف</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <div className="mt-5 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="text-start">المستخدم</TableHead>
                <TableHead className="text-start">الدور</TableHead>
                <TableHead className="text-start">الحالة</TableHead>
                <TableHead className="text-start">المشاريع</TableHead>
                <TableHead className="text-start">أُنشئ</TableHead>
                <TableHead className="text-start">إجراءات</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((user) => {
                const isSelf = user.id === me?.id;

                return (
                  <TableRow key={user.id} className={user.is_active ? "" : "opacity-60"}>
                    <TableCell>
                      <div className="font-medium">
                        {user.full_name || "—"}
                        {isSelf && (
                          <span className="ms-2 rounded-full bg-primary/12 px-2 py-0.5 text-[11px] text-primary">
                            حسابك
                          </span>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground" dir="ltr">
                        {user.email}
                      </div>
                      {(user.partner_agency || user.agency_name) && (
                        <div className="text-xs text-muted-foreground">
                          {user.partner_agency || user.agency_name}
                        </div>
                      )}
                    </TableCell>

                    <TableCell>
                      <Select
                        value={user.system_role}
                        disabled={isSelf || update.isPending}
                        onValueChange={(next) => {
                          if (next === user.system_role) return;
                          // الشريك بلا وكالة لا يرى مشروعًا واحدًا — نسأل أولًا
                          if (next === "partner" && !user.partner_agency) {
                            setToPartner(user);
                            return;
                          }
                          update.mutate({
                            id: user.id,
                            input: { system_role: next as SystemRole },
                          });
                        }}
                      >
                        <SelectTrigger
                          className="w-[140px]"
                          title={isSelf ? "لا تغيّر دور حسابك بنفسك." : undefined}
                        >
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {ROLES.map((r) => (
                            <SelectItem key={r.value} value={r.value}>
                              {r.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </TableCell>

                    <TableCell>
                      <label className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Switch
                          checked={user.is_active}
                          disabled={isSelf || update.isPending}
                          aria-label={user.is_active ? "إيقاف الحساب" : "تنشيط الحساب"}
                          onCheckedChange={(checked) =>
                            update.mutate({ id: user.id, input: { is_active: checked } })
                          }
                        />
                        {user.is_active ? "نشط" : "موقوف"}
                      </label>
                    </TableCell>

                    <TableCell className="text-xs text-muted-foreground">
                      عضو في <Num value={user.memberships_count} />
                      {user.owned_projects_count > 0 && (
                        <>
                          {" · "}
                          يملك <Num value={user.owned_projects_count} />
                        </>
                      )}
                    </TableCell>

                    <TableCell className="text-xs text-muted-foreground">
                      {formatDateAr(user.created_at)}
                    </TableCell>

                    <TableCell>
                      <div className="flex items-center gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label="تعديل البيانات"
                          title="تعديل البيانات"
                          onClick={() => setEditing(user)}
                        >
                          <UserPen className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label="تعيين كلمة مرور"
                          title="تعيين كلمة مرور"
                          onClick={() => setPasswordFor(user)}
                        >
                          <KeyRound className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label="حذف الحساب"
                          disabled={isSelf || user.owned_projects_count > 0}
                          title={
                            isSelf
                              ? "لا تحذف حسابك بنفسك."
                              : user.owned_projects_count > 0
                                ? "هذا الحساب مالك لمشاريع قائمة — أوقفه بدل حذفه."
                                : "حذف الحساب"
                          }
                          onClick={() => setDeleting(user)}
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>

        {rows.length === 0 && (
          <div className="pt-4">
            <EmptyState
              title={isLoading ? "جارٍ التحميل…" : "لا حسابات مطابقة"}
              hint={
                isLoading
                  ? "لحظة واحدة."
                  : "غيّر البحث أو المرشّحات، أو أنشئ حسابًا جديدًا بالزر أعلى الصفحة."
              }
            />
          </div>
        )}
      </section>

      <section className="surface-card p-6">
        <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
          <ShieldCheck className="size-4 text-primary" /> ماذا يعني كل دور
        </h2>
        <ul className="mt-3 space-y-2 text-sm">
          {ROLES.map((r) => (
            <li key={r.value} className="flex flex-wrap items-baseline gap-2">
              <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-medium">
                {r.label}
              </span>
              <span className="text-muted-foreground">{r.hint}</span>
            </li>
          ))}
        </ul>
        <p className="mt-4 border-t border-border pt-3 text-xs leading-6 text-muted-foreground">
          التسجيل الذاتي من صفحة الدخول ينشئ «عميلًا» دائمًا. أي دور أعلى من ذلك لا يبلغه أحد إلا
          بقرار منك هنا. والحساب الموقوف تُغلق جلساته فورًا ولا يعود يدخل.
        </p>
      </section>

      <CreateUserDialog open={creating} onOpenChange={setCreating} onDone={refresh} />

      <EditUserDialog
        user={editing}
        onOpenChange={(open) => !open && setEditing(null)}
        onSave={(input) => editing && update.mutate({ id: editing.id, input })}
        saving={update.isPending}
      />

      <PasswordDialog user={passwordFor} onOpenChange={(open) => !open && setPasswordFor(null)} />

      <PartnerAgencyDialog
        user={toPartner}
        onOpenChange={(open) => !open && setToPartner(null)}
        onConfirm={(agency) =>
          toPartner &&
          update.mutate({
            id: toPartner.id,
            input: { system_role: "partner", partner_agency: agency },
          })
        }
        saving={update.isPending}
      />

      <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
        <AlertDialogContent dir="rtl" className="text-start">
          <AlertDialogHeader className="text-start">
            <AlertDialogTitle className="font-display">حذف الحساب نهائيًا؟</AlertDialogTitle>
            <AlertDialogDescription className="leading-7">
              حذف «{deleting?.full_name || deleting?.email}» يخرجه من كل المشاريع التي هو عضو فيها.
              أثره في سجل التدقيق يبقى باسمه، لكن الرابط إلى حسابه ينقطع. الأسلم لمن غادر الفريق هو
              الإيقاف لا الحذف.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="gap-2 sm:justify-start">
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                if (deleting) remove.mutate(deleting.id);
              }}
              disabled={remove.isPending}
            >
              نعم، احذف
            </AlertDialogAction>
            <AlertDialogCancel>تراجع</AlertDialogCancel>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ---------------------------------------------------------------------------

const EMPTY_FORM = {
  full_name: "",
  email: "",
  password: "",
  system_role: "client" as SystemRole,
  agency_name: "",
  partner_agency: "",
};

function CreateUserDialog({
  open,
  onOpenChange,
  onDone,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  onDone: () => void;
}) {
  const [form, setForm] = useState(EMPTY_FORM);

  useEffect(() => {
    if (open) setForm(EMPTY_FORM);
  }, [open]);

  const create = useMutation({
    mutationFn: () => {
      if (form.full_name.trim().length < 2) throw new Error("اكتب اسم صاحب الحساب.");
      if (form.password.length < 8) throw new Error("كلمة المرور ثمانية أحرف على الأقل.");

      return api.users.create({
        email: form.email.trim(),
        password: form.password,
        full_name: form.full_name.trim(),
        system_role: form.system_role,
        agency_name: form.agency_name.trim() || null,
        partner_agency: form.system_role === "partner" ? form.partner_agency.trim() : null,
      });
    },
    onSuccess: (user) => {
      toast.success(`أُنشئ حساب ${user.full_name} بدور ${user.role_label}.`);
      onOpenChange(false);
      onDone();
    },
    onError: (e: unknown) => toast.error(e instanceof Error ? e.message : "تعذّر إنشاء الحساب."),
  });

  const set = (patch: Partial<typeof EMPTY_FORM>) => setForm((f) => ({ ...f, ...patch }));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-lg text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="flex items-center gap-2 font-display">
            <UserPlus className="size-4 text-primary" /> حساب جديد
          </DialogTitle>
          <DialogDescription className="leading-7">
            أنت تنشئ الحساب وتحدّد دوره وكلمة مروره الأولى. سلّمها لصاحبها بقناة موثوقة — النظام لا
            يرسلها بالبريد.
          </DialogDescription>
        </DialogHeader>

        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            create.mutate();
          }}
        >
          <div className="grid gap-1.5">
            <Label htmlFor="new-name">الاسم الكامل</Label>
            <Input
              id="new-name"
              required
              value={form.full_name}
              onChange={(e) => set({ full_name: e.target.value })}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="new-email">البريد الإلكتروني</Label>
            <Input
              id="new-email"
              type="email"
              required
              dir="ltr"
              placeholder="name@company.com"
              value={form.email}
              onChange={(e) => set({ email: e.target.value })}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="new-password">كلمة المرور الأولى</Label>
            <Input
              id="new-password"
              type="password"
              required
              minLength={8}
              dir="ltr"
              value={form.password}
              onChange={(e) => set({ password: e.target.value })}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="new-role">الدور في النظام</Label>
            <Select
              value={form.system_role}
              onValueChange={(v) => set({ system_role: v as SystemRole })}
            >
              <SelectTrigger id="new-role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROLES.map((r) => (
                  <SelectItem key={r.value} value={r.value}>
                    {r.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {ROLES.find((r) => r.value === form.system_role)?.hint}
            </p>
          </div>

          {form.system_role === "partner" ? (
            <div className="grid gap-1.5">
              <Label htmlFor="new-partner-agency">اسم الوكالة الشريكة</Label>
              <Input
                id="new-partner-agency"
                required
                value={form.partner_agency}
                onChange={(e) => set({ partner_agency: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                يجب أن يطابق اسم الوكالة المكتوب في المشاريع — عليه وحده يتحدّد ما يراه.
              </p>
            </div>
          ) : (
            <div className="grid gap-1.5">
              <Label htmlFor="new-agency">جهة العمل (اختياري)</Label>
              <Input
                id="new-agency"
                value={form.agency_name}
                onChange={(e) => set({ agency_name: e.target.value })}
              />
            </div>
          )}

          <DialogFooter className="gap-2 sm:justify-start">
            <Button type="submit" disabled={create.isPending}>
              إنشاء الحساب
            </Button>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              إلغاء
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function EditUserDialog({
  user,
  onOpenChange,
  onSave,
  saving,
}: {
  user: ManagedUser | null;
  onOpenChange: (v: boolean) => void;
  onSave: (input: Partial<UserInput>) => void;
  saving: boolean;
}) {
  const [form, setForm] = useState({ full_name: "", email: "", agency: "" });

  useEffect(() => {
    if (!user) return;
    setForm({
      full_name: user.full_name,
      email: user.email,
      agency: (user.system_role === "partner" ? user.partner_agency : user.agency_name) ?? "",
    });
  }, [user]);

  const isPartner = user?.system_role === "partner";

  return (
    <Dialog open={user !== null} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-lg text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="flex items-center gap-2 font-display">
            <UserPen className="size-4 text-primary" /> تعديل الحساب
          </DialogTitle>
          <DialogDescription className="leading-7">
            بيانات الحساب الوصفية. الدور والتفعيل يُعدَّلان من الجدول مباشرة، لأنهما وحدهما ما يغيّر
            ما يستطيع الحساب فعله.
          </DialogDescription>
        </DialogHeader>

        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            onSave({
              full_name: form.full_name.trim(),
              email: form.email.trim(),
              ...(isPartner
                ? { partner_agency: form.agency.trim() }
                : { agency_name: form.agency.trim() || null }),
            });
          }}
        >
          <div className="grid gap-1.5">
            <Label htmlFor="edit-name">الاسم الكامل</Label>
            <Input
              id="edit-name"
              required
              value={form.full_name}
              onChange={(e) => setForm((f) => ({ ...f, full_name: e.target.value }))}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="edit-email">البريد الإلكتروني</Label>
            <Input
              id="edit-email"
              type="email"
              required
              dir="ltr"
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
            />
            <p className="text-xs text-muted-foreground">
              تغيير البريد يغيّر ما يدخل به صاحبه. الدعوات المعلّقة بالبريد القديم لن تلتقطه بعدها.
            </p>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="edit-agency">{isPartner ? "اسم الوكالة الشريكة" : "جهة العمل"}</Label>
            <Input
              id="edit-agency"
              required={isPartner}
              value={form.agency}
              onChange={(e) => setForm((f) => ({ ...f, agency: e.target.value }))}
            />
            {isPartner && (
              <p className="text-xs text-muted-foreground">
                على هذا الاسم وحده يتحدّد ما تراه الوكالة من مشاريع.
              </p>
            )}
          </div>

          <DialogFooter className="gap-2 sm:justify-start">
            <Button type="submit" disabled={saving}>
              حفظ
            </Button>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              إلغاء
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PasswordDialog({
  user,
  onOpenChange,
}: {
  user: ManagedUser | null;
  onOpenChange: (v: boolean) => void;
}) {
  const [password, setPassword] = useState("");

  useEffect(() => {
    if (user) setPassword("");
  }, [user]);

  const save = useMutation({
    mutationFn: () => {
      if (!user) throw new Error("لا حساب محدّد.");
      if (password.length < 8) throw new Error("كلمة المرور ثمانية أحرف على الأقل.");
      return api.users.setPassword(user.id, password);
    },
    onSuccess: () => {
      toast.success("عُيّنت كلمة المرور، وأُغلقت جلسات الحساب المفتوحة.");
      onOpenChange(false);
    },
    onError: (e: unknown) =>
      toast.error(e instanceof Error ? e.message : "تعذّر تغيير كلمة المرور."),
  });

  return (
    <Dialog open={user !== null} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-md text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="flex items-center gap-2 font-display">
            <KeyRound className="size-4 text-primary" /> كلمة مرور جديدة
          </DialogTitle>
          <DialogDescription className="leading-7">
            لحساب «{user?.full_name || user?.email}». تعيينها من هنا يُنهي كل جلساته المفتوحة — وهو
            المطلوب إن كان السبب تسريبًا أو جهازًا مفقودًا.
          </DialogDescription>
        </DialogHeader>

        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          <div className="grid gap-1.5">
            <Label htmlFor="reset-password">كلمة المرور</Label>
            <Input
              id="reset-password"
              type="password"
              required
              minLength={8}
              dir="ltr"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </div>

          <DialogFooter className="gap-2 sm:justify-start">
            <Button type="submit" disabled={save.isPending}>
              تعيين
            </Button>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              إلغاء
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/** تحويل حساب إلى «شريك»: الوكالة ليست بيانات وصفية بل نطاق رؤيته كله. */
function PartnerAgencyDialog({
  user,
  onOpenChange,
  onConfirm,
  saving,
}: {
  user: ManagedUser | null;
  onOpenChange: (v: boolean) => void;
  onConfirm: (agency: string) => void;
  saving: boolean;
}) {
  const [agency, setAgency] = useState("");

  useEffect(() => {
    if (user) setAgency(user.agency_name ?? "");
  }, [user]);

  return (
    <Dialog open={user !== null} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl" className="max-w-md text-start">
        <DialogHeader className="text-start">
          <DialogTitle className="font-display">
            تحويل {user?.full_name || user?.email} إلى شريك
          </DialogTitle>
          <DialogDescription className="leading-7">
            الشريك يرى مشاريع وكالته وحدها. بلا اسم وكالة مطابق لما هو مكتوب في المشاريع، لن يرى
            شيئًا على الإطلاق.
          </DialogDescription>
        </DialogHeader>

        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            if (agency.trim() !== "") onConfirm(agency.trim());
          }}
        >
          <div className="grid gap-1.5">
            <Label htmlFor="partner-agency">اسم الوكالة</Label>
            <Input
              id="partner-agency"
              required
              value={agency}
              onChange={(e) => setAgency(e.target.value)}
            />
          </div>

          <DialogFooter className="gap-2 sm:justify-start">
            <Button type="submit" disabled={saving || agency.trim() === ""}>
              تحويل إلى شريك
            </Button>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              إلغاء
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
