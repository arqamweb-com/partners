/**
 * بذر مشروع من قالب نوعه وتفاصيله.
 *
 * مكان واحد يستخدمه المساران: إنشاء الأدمن المباشر، واعتماد طلب العميل.
 * كل خطوة هنا مفحوصة الخطأ — النسخة القديمة كانت بتكتب المراحل والقوائم
 * بدون فحص، فلو فشلت واحدة كان المستخدم يتنقل لمشروع ناقص وهو فاكره تمام.
 *
 * تفاصيل النوع (عدد المنتجات، بوابة الدفع، اللغات…) مش توثيقًا فقط: بتضيف
 * عناصر لقائمتي الوصول والمحتوى وبتزوّد مدد المراحل — انظر project-types.ts
 */

import { supabase } from "@/lib/api";
import {
  accessForType,
  contentForType,
  type StageTemplate,
  type TypeDetails,
} from "@/lib/project-types";

/** يبذر مراحل المشروع وقوائم الوصول والمحتوى حسب نوعه وتفاصيله. */
export async function seedProjectFromType(
  projectId: string,
  typeId: string,
  options: { fastTrack?: boolean; details?: TypeDetails; stages?: StageTemplate[] } = {},
): Promise<void> {
  const details = options.details ?? {};
  const factor = options.fastTrack ? 0.6 : 1;
  const now = new Date().toISOString();

  // البذر مرة واحدة فقط: لو فشل الاعتماد بعد البذر وأعاد الأدمن المحاولة،
  // الإدراج الثاني هيصطدم بـ UNIQUE (project_id, stage_index)
  const { data: existing } = await supabase
    .from("stages")
    .select("id")
    .eq("project_id", projectId)
    .limit(1);
  if (existing && existing.length > 0) return;

  // المراحل تأتي من المعالج بعد ما عدّلها الأدمن، وإلا من قالب النوع
  const stages = options.stages ?? [];

  const stagesResult = await supabase.from("stages").insert(
    stages.map((s, i) => ({
      project_id: projectId,
      stage_index: i,
      is_parallel: false,
      name: s.name,
      gate_name: s.gate,
      gate_size: s.gate_size,
      our_duration_days: Math.ceil(s.our * factor),
      their_duration_days: Math.ceil(s.their * factor),
      status: i === 0 ? ("active" as const) : ("pending" as const),
      started_at: i === 0 ? now : null,
      ball_in_court: "us" as const,
    })),
  );
  if (stagesResult.error)
    throw new Error(`تعذّر إنشاء مراحل المشروع: ${stagesResult.error.message}`);

  // المسار المتوازي: الوصول والحسابات يمشي بالتوازي مع باقي المراحل
  const parallelResult = await supabase.from("stages").insert({
    project_id: projectId,
    stage_index: 100,
    is_parallel: true,
    name: "الوصول والحسابات",
    gate_name: "Access Ready",
    gate_size: "small",
    our_duration_days: 0,
    their_duration_days: 10,
    status: "active",
    ball_in_court: "them",
    started_at: now,
  });
  if (parallelResult.error) {
    throw new Error(`تعذّر إنشاء مسار الوصول: ${parallelResult.error.message}`);
  }

  const accessResult = await supabase.from("access_items").insert(
    accessForType(typeId, details).map((a, i) => ({
      project_id: projectId,
      item_order: i + 1,
      name: a.name,
      note: a.note,
      is_slow: a.slow,
    })),
  );
  if (accessResult.error) {
    throw new Error(`تعذّر إنشاء قائمة الوصول: ${accessResult.error.message}`);
  }

  const contentResult = await supabase.from("content_items").insert(
    contentForType(typeId, details).map((c, i) => ({
      project_id: projectId,
      item_group: c.group,
      item_order: i + 1,
      name: c.name,
      acceptance_criteria: c.ac,
    })),
  );
  if (contentResult.error) {
    throw new Error(`تعذّر إنشاء قائمة المحتوى: ${contentResult.error.message}`);
  }
}
