/**
 * Shared business-day utility for Arqam Flow.
 * Work week: Sunday–Thursday. Friday + Saturday are always off.
 * Admin-managed holidays are excluded too.
 * Anything submitted after 16:00 counts from the following business day.
 */

export const CUTOFF_HOUR = 16;

function toKey(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export function isWeekend(d: Date): boolean {
  const day = d.getDay(); // 0 = Sunday ... 6 = Saturday
  return day === 5 || day === 6;
}

export function isBusinessDay(d: Date, holidays: string[] = []): boolean {
  return !isWeekend(d) && !holidays.includes(toKey(d));
}

function startOfDay(d: Date): Date {
  const c = new Date(d);
  c.setHours(0, 0, 0, 0);
  return c;
}

/** The next business day strictly after `d`. */
export function nextBusinessDay(d: Date, holidays: string[] = []): Date {
  const c = startOfDay(d);
  do {
    c.setDate(c.getDate() + 1);
  } while (!isBusinessDay(c, holidays));
  return c;
}

/**
 * The day the countdown starts from: the next business day after submission,
 * pushed one extra day when submitted at/after the 16:00 cutoff.
 */
export function countingStartDate(submittedAt: Date, holidays: string[] = []): Date {
  let start = nextBusinessDay(submittedAt, holidays);
  if (submittedAt.getHours() >= CUTOFF_HOUR) {
    start = nextBusinessDay(start, holidays);
  }
  return start;
}

/** Add N business days to a date. */
export function addBusinessDays(d: Date, days: number, holidays: string[] = []): Date {
  let c = startOfDay(d);
  let left = Math.max(0, Math.round(days));
  while (left > 0) {
    c = nextBusinessDay(c, holidays);
    left -= 1;
  }
  return c;
}

/** Whole business days between two dates (exclusive of `from`, inclusive of `to`). */
export function businessDaysBetween(from: Date, to: Date, holidays: string[] = []): number {
  const a = startOfDay(from);
  const b = startOfDay(to);
  if (b <= a) return 0;
  let count = 0;
  const cursor = new Date(a);
  while (cursor < b) {
    cursor.setDate(cursor.getDate() + 1);
    if (isBusinessDay(cursor, holidays)) count += 1;
  }
  return count;
}

/** Business days remaining until `due` (negative = overdue). */
export function businessDaysUntil(due: Date, holidays: string[] = [], now = new Date()): number {
  if (startOfDay(due) >= startOfDay(now)) return businessDaysBetween(now, due, holidays);
  return -businessDaysBetween(due, now, holidays);
}

export function computeAdjustedDelivery(
  originalDelivery: string,
  clientDelayDays: number,
  holidays: string[] = [],
): Date {
  return addBusinessDays(new Date(`${originalDelivery}T00:00:00`), clientDelayDays, holidays);
}

export function formatDateAr(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d =
    typeof value === "string" ? new Date(value.length === 10 ? `${value}T00:00:00` : value) : value;
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("ar-SA-u-ca-gregory-nu-latn", {
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(d);
}
