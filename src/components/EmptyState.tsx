import type { LucideIcon } from "lucide-react";
import { Inbox } from "lucide-react";

export function EmptyState({
  icon: Icon = Inbox,
  title,
  hint,
  action,
}: {
  icon?: LucideIcon;
  title: string;
  hint: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border/80 px-6 py-12 text-center">
      <Icon className="size-6 text-muted-foreground" />
      <div className="text-sm font-medium">{title}</div>
      <p className="max-w-md text-xs leading-6 text-muted-foreground">{hint}</p>
      {action}
    </div>
  );
}
