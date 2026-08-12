import { cn } from "@/lib/utils";

const TONES: Record<string, string> = {
  draft: "bg-warn/15 text-warn-foreground",
  active: "bg-primary/12 text-primary",
  awaiting_client: "bg-warn/15 text-warn-foreground",
  frozen: "bg-muted text-muted-foreground",
  completed: "bg-success/15 text-success-foreground",
  stopped: "bg-destructive/12 text-destructive",
};

export function StatusPill({ status, label }: { status: string; label: string }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap",
        TONES[status] ?? "bg-muted text-muted-foreground",
      )}
    >
      {label}
    </span>
  );
}
