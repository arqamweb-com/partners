import { supabase } from "@/lib/api";

/** Append an immutable audit entry. Never throws — logging must not break a flow. */
export async function logAudit(
  projectId: string,
  eventType: string,
  description: string,
  actorName?: string,
): Promise<void> {
  try {
    const { data } = await supabase.auth.getUser();
    const uid = data.user?.id ?? null;
    await supabase.from("audit_log").insert({
      project_id: projectId,
      actor_id: uid,
      actor_name: actorName || data.user?.email || "النظام",
      event_type: eventType,
      description,
    });
  } catch {
    /* audit failures are silent by design */
  }
}
