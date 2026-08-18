/**
 * أنواع صفوف قاعدة البيانات كما يعيدها الـ API.
 *
 * كان هذا الملف 1012 سطرًا مولّدة من Supabase، فيها Row/Insert/Update
 * وعلاقات لكل جدول — لأن العميل كان يبني استعلامات فيحتاج أنواع الكتابة.
 * الآن العميل ينادي أفعالًا، فما يحتاجه هو شكل ما يُقرأ فقط.
 *
 * التواريخ نصوص ISO، والمنطقيات منطقيات حقيقية (لارافيل يحوّلها بالـ casts).
 */

export type Uuid = string;
export type IsoDateTime = string;
export type IsoDate = string;

export type Side = "us" | "them";

export type ProjectStatus =
  | "draft"
  | "active"
  | "awaiting_client"
  | "frozen"
  | "completed"
  | "stopped";

export type StageStatus = "pending" | "active" | "awaiting_approval" | "locked" | "frozen";

export type ContentItemStatus = "pending" | "submitted" | "accepted" | "rejected";

export type FeedbackRoundStatus = "open" | "submitted" | "classified" | "closed";

export type ChangeRequestStatus =
  | "draft"
  | "sent"
  | "approved"
  | "rejected"
  | "expired"
  | "withdrawn";

/** دور العضو داخل مشروع بعينه — مستقل عن دوره في النظام. */
export type ProjectMemberRole = "lead" | "contributor" | "partner" | "client" | "viewer";

// ---------------------------------------------------------------------------

export interface ProjectRow {
  id: Uuid;
  name: string;
  end_client_name: string;
  partner_agency: string;
  project_type: string;
  type_details: Record<string, number | boolean | string> | null;
  intake_data: Record<string, unknown> | null;
  owner_id: Uuid | null;
  owner_name: string;
  track: "normal" | "fast_track";
  status: ProjectStatus;
  original_delivery_date: IsoDate | null;
  client_delay_days: number;
  adjusted_delivery_date: IsoDate | null;
  warranty_days: number;
  revision_rounds_allowed: number;
  revision_rounds_used: number;
  scope: string | null;
  out_of_scope: string | null;
  notes: string | null;
  supported_devices: string | null;
  supported_browsers: string | null;
  supported_screens: string | null;
  payment_milestones: unknown[] | null;
  queue_slot_date: IsoDate | null;
  reactivation_fee: number;
  reactivated_at: IsoDateTime | null;
  credit_amount: number;
  credit_expires_at: IsoDate | null;
  frozen_at: IsoDateTime | null;
  created_at: IsoDateTime;
  updated_at: IsoDateTime;
  /** مؤرشف — لا يظهر في أي شاشة إلا شاشة الأرشيف. */
  deleted_at: IsoDateTime | null;
  deleted_by: Uuid | null;
}

export interface ProjectMemberRow {
  id: Uuid;
  project_id: Uuid;
  user_id: Uuid | null;
  invited_email: string | null;
  role: ProjectMemberRole;
  invited_by: Uuid | null;
  claimed_at: IsoDateTime | null;
}

export interface StageRow {
  id: Uuid;
  project_id: Uuid;
  stage_index: number;
  is_parallel: boolean;
  name: string;
  gate_name: string | null;
  gate_size: string;
  our_duration_days: number;
  their_duration_days: number;
  ball_in_court: Side;
  status: StageStatus;
  started_at: IsoDateTime | null;
  due_at: IsoDateTime | null;
  submitted_at: IsoDateTime | null;
  submitted_by: Uuid | null;
  submission_note: string | null;
  rejection_reason: string | null;
  rejected_at: IsoDateTime | null;
  rejected_by: Uuid | null;
  rejection_count: number;
  locked_at: IsoDateTime | null;
  locked_by: Uuid | null;
  deliverables: string[] | null;
  created_at: IsoDateTime;
}

export interface AuditLogRow {
  id: Uuid;
  project_id: Uuid;
  actor_id: Uuid | null;
  actor_name: string;
  /** بأي صفة تصرّف الفاعل في هذا المشروع — جديد. */
  actor_role: ProjectMemberRole | null;
  event_type: string;
  description: string;
  created_at: IsoDateTime;
}

export interface AccessItemRow {
  id: Uuid;
  project_id: Uuid;
  item_order: number;
  name: string;
  note: string | null;
  is_slow: boolean;
  is_done: boolean;
  provided_by: Uuid | null;
  provided_at: IsoDateTime | null;
}

export interface ContentItemRow {
  id: Uuid;
  project_id: Uuid;
  item_group: "blocking" | "non_blocking";
  item_order: number;
  name: string;
  acceptance_criteria: string | null;
  status: ContentItemStatus;
  value: string | null;
  due_at: IsoDateTime | null;
  submitted_at: IsoDateTime | null;
  submitted_by: Uuid | null;
  reviewed_at: IsoDateTime | null;
  reviewed_by: Uuid | null;
  rejection_reason: string | null;
  auto_accepted: boolean;
}

export interface FeedbackRoundRow {
  id: Uuid;
  project_id: Uuid;
  stage_id: Uuid | null;
  round_number: number;
  status: FeedbackRoundStatus;
  is_paid: boolean;
  opened_at: IsoDateTime | null;
  submitted_at: IsoDateTime | null;
  closed_at: IsoDateTime | null;
}

export interface FeedbackItemRow {
  id: Uuid;
  round_id: Uuid;
  project_id: Uuid;
  description: string;
  page_or_section: string;
  classification: "defect" | "enhancement" | "new_scope" | null;
  classified_at: IsoDateTime | null;
  classified_by: Uuid | null;
  objection_text: string | null;
  objection_at: IsoDateTime | null;
  resolution: "fixed" | "converted_to_cr" | "goodwill_fix" | null;
}

export interface ChangeRequestRow {
  id: Uuid;
  project_id: Uuid;
  requested_by: Uuid | null;
  source_feedback_item_id: Uuid | null;
  title: string;
  description: string | null;
  price: number;
  currency: string;
  duration_days: number;
  delivery_impact_days: number;
  status: ChangeRequestStatus;
  sent_at: IsoDateTime | null;
  quote_valid_until: IsoDate | null;
  decision_deadline: IsoDate | null;
  decided_at: IsoDateTime | null;
  decided_by: Uuid | null;
  decision_note: string | null;
  resubmitted_from: Uuid | null;
  /** يُختم مرة واحدة — حارس تكرار تمديد التسليم. */
  delivery_extended_at: IsoDateTime | null;
}

export interface AppSettingsRow {
  id: number;
  warning_threshold_days: number;
  freeze_threshold_days: number;
  reactivation_fee: number;
  warranty_days: number;
  revision_rounds_allowed: number;
  stage_defaults: unknown | null;
}

export interface CrPriceItemRow {
  id: Uuid;
  name: string;
  price: number;
  currency: string;
  duration_days: number;
}

export interface HolidayRow {
  id: Uuid;
  holiday_date: IsoDate;
  label: string;
}

// ---------------------------------------------------------------------------

/** يبقى الاسم كما كان حتى لا تتغيّر أسطر الاستيراد في domain.ts */
export interface TableMap {
  projects: ProjectRow;
  project_members: ProjectMemberRow;
  stages: StageRow;
  audit_log: AuditLogRow;
  access_items: AccessItemRow;
  content_items: ContentItemRow;
  feedback_rounds: FeedbackRoundRow;
  feedback_items: FeedbackItemRow;
  change_requests: ChangeRequestRow;
  app_settings: AppSettingsRow;
  cr_price_items: CrPriceItemRow;
  holidays: HolidayRow;
}

export type Tables<T extends keyof TableMap> = TableMap[T];
