<?php
/**
 * تعريف الجداول والصلاحيات — هذا الملف هو البديل الكامل لسياسات RLS في Postgres.
 *
 * لكل جدول:
 *   scope   : كيف تُقيَّد الصفوف على المستخدم غير الأدمن
 *             'project' = صفوف مشاريعه فقط | 'own' = صفوفه هو | 'global' = الكل يقرأ
 *   read/insert/update/delete :
 *             'admin'  = الأدمن فقط
 *             'owner'  = الأدمن أو مالك المشروع (من أنشأه)
 *             'member' = الأدمن أو عضو المشروع
 *             'own'    = صاحب الصف فقط (أو الأدمن)
 *             'any'    = أي مستخدم مسجّل دخوله
 *             'none'   = ممنوع على الجميع عبر الـ API
 *   columns : الأعمدة المسموح كتابتها (أي عمود آخر في الطلب يُتجاهل — حماية من mass assignment)
 *   member_columns : الأعمدة التي يملك العضو غير الأدمن حق تعديلها فقط
 *   bool/json/num  : أعمدة تحتاج تحويل نوع قبل التخزين
 */

declare(strict_types=1);

function table_schema(string $table): ?array
{
    return TABLES[$table] ?? null;
}

const TABLES = [

    'profiles' => [
        'scope'   => 'own',
        'own_key' => 'id',
        'read'    => 'own',
        'insert'  => 'own',
        'update'  => 'own',
        'delete'  => 'none',
        'columns' => ['id', 'full_name', 'email', 'agency_name'],
    ],

    'user_roles' => [
        'scope'   => 'own',
        'own_key' => 'user_id',
        'read'    => 'own',
        'insert'  => 'admin',
        'update'  => 'admin',
        'delete'  => 'admin',
        'columns' => ['user_id', 'role'],
    ],

    'holidays' => [
        'scope'   => 'global',
        'read'    => 'any',
        'insert'  => 'admin',
        'update'  => 'admin',
        'delete'  => 'admin',
        'columns' => ['holiday_date', 'label'],
    ],

    'projects' => [
        'scope'    => 'project',
        'project_key' => 'id',
        'read'     => 'member',
        // العميل ينشئ مشروعه بنفسه ويسجّل بياناته الأساسية.
        // owner_id يُضبط في السيرفر (rule_project_write) ولا يأتي من الطلب.
        'insert'   => 'any',
        'update'   => 'owner',
        'delete'   => 'admin',
        // العميل يسجّل الأساسيات وبياناته ومواصفات مشروعه، بما فيها ما يعتبره
        // خارج النطاق. المدد والتسعير يكتبها فريق أرقام عند مراجعة الطلب —
        // ولا يقبلها السيرفر من العميل أصلًا.
        'member_insert_columns' => [
            'name', 'end_client_name', 'partner_agency', 'project_type', 'owner_name',
            'intake_data', 'type_details', 'scope', 'out_of_scope',
        ],
        // وما يملك تعديله بعد الإنشاء — وقبل الاعتماد فقط
        // (rule_project_write تمنع تعديل ميثاق مشروع معتمد)
        'member_columns' => [
            'name', 'end_client_name', 'partner_agency', 'owner_name', 'notes',
            'intake_data', 'type_details', 'scope', 'out_of_scope',
        ],
        // ملاحظة: تمديد تاريخ التسليم عند اعتماد العميل لطلب تغيير يتم في السيرفر
        // (rule_cr_approval_side_effect)، فلا يحتاج العميل صلاحية كتابة هنا.
        'columns'  => [
            'name', 'end_client_name', 'partner_agency', 'project_type', 'type_details',
            'intake_data', 'owner_id', 'owner_name',
            'track', 'status', 'original_delivery_date', 'client_delay_days',
            'adjusted_delivery_date', 'warranty_days', 'revision_rounds_allowed',
            'revision_rounds_used', 'scope', 'out_of_scope', 'notes',
            'supported_devices', 'supported_browsers', 'supported_screens',
            'payment_milestones', 'queue_slot_date', 'reactivation_fee',
            'reactivated_at', 'credit_amount', 'credit_expires_at', 'frozen_at',
        ],
        'json'     => ['payment_milestones', 'type_details', 'intake_data'],
        'num'      => ['client_delay_days', 'warranty_days', 'revision_rounds_allowed',
                       'revision_rounds_used', 'reactivation_fee', 'credit_amount'],
    ],

    'project_members' => [
        'scope'   => 'own',
        'own_key' => 'user_id',
        'read'    => 'own',
        'insert'  => 'admin',
        'update'  => 'admin',
        'delete'  => 'admin',
        'columns' => ['project_id', 'user_id'],
    ],

    'stages' => [
        'scope'   => 'project',
        'read'    => 'member',
        // المراحل تتولد من قالب نوع المشروع عند اعتماد فريق أرقام للطلب،
        // فلا يبذر العميل مراحل مشروعه بنفسه قبل المراجعة.
        'insert'  => 'admin',
        // تقديم المرحلة واعتمادها ورفضها تتم عبر /api/stages/* وليس بتعديل
        // الأعمدة مباشرة — انظر api/lib/stages.php
        'update'  => 'admin',
        'delete'  => 'admin',
        'columns' => [
            'project_id', 'stage_index', 'is_parallel', 'name', 'gate_name', 'gate_size',
            'our_duration_days', 'their_duration_days', 'ball_in_court', 'status',
            'started_at', 'due_at', 'locked_at', 'locked_by', 'deliverables',
            'submitted_at', 'submitted_by', 'submission_note',
            'rejection_reason', 'rejected_at', 'rejected_by', 'rejection_count',
        ],
        'bool'    => ['is_parallel'],
        'json'    => ['deliverables'],
        'num'     => ['stage_index', 'our_duration_days', 'their_duration_days'],
    ],

    'gate_approvals' => [
        'scope'   => 'project',
        'read'    => 'member',
        // سجل الاعتماد يكتبه السيرفر وحده داخل stage_approve، فلا يُقبل من
        // المتصفح إطلاقًا — وإلا زرع أي عضو اعتمادًا مفبركًا باسم غيره
        'insert'  => 'none',
        'update'  => 'none',   // اعتماد البوابة لا يُعدَّل
        'delete'  => 'none',
        'columns' => ['project_id', 'stage_id', 'approved_by', 'approver_name',
                      'acknowledgement_text', 'is_silent'],
        'bool'    => ['is_silent'],
    ],

    'audit_log' => [
        'scope'   => 'project',
        'read'    => 'member',
        'insert'  => 'member',
        'update'  => 'none',   // سجل التدقيق: إضافة فقط
        'delete'  => 'none',
        // actor_id يُفرض من الجلسة في rule_audit_insert ولا يُقبل من الطلب
        'columns' => ['project_id', 'actor_id', 'actor_name', 'event_type', 'description'],
    ],

    'project_invites' => [
        'scope'   => 'project',
        'read'    => 'admin',
        'insert'  => 'admin',
        'update'  => 'none',
        'delete'  => 'admin',
        'columns' => ['project_id', 'email', 'invited_by'],
    ],

    'access_items' => [
        'scope'   => 'project',
        'read'    => 'member',
        // تتولد من قالب النوع عند الاعتماد — انظر ملاحظة جدول stages
        'insert'  => 'admin',
        'update'  => 'member',
        'delete'  => 'admin',
        // provided_by/provided_at يفرضهما rule_access_item_update من الجلسة
        'member_columns' => ['is_done', 'note'],
        'columns' => ['project_id', 'item_order', 'name', 'note', 'is_slow', 'is_done',
                      'provided_by', 'provided_at'],
        'bool'    => ['is_slow', 'is_done'],
        'num'     => ['item_order'],
    ],

    'content_items' => [
        'scope'   => 'project',
        'read'    => 'member',
        // تتولد من قالب النوع عند الاعتماد — انظر ملاحظة جدول stages
        'insert'  => 'admin',
        'update'  => 'member',
        'delete'  => 'admin',
        // العميل يقدّم المحتوى؛ القبول والرفض للأدمن فقط.
        // status مسموح هنا لأن العميل يحتاجه لـ 'submitted'، وأي قيمة غيرها
        // يرفضها rule_content_item_update — والتقديم يُختم من السيرفر
        'member_columns' => ['value', 'status'],
        'columns' => ['project_id', 'item_group', 'item_order', 'name', 'acceptance_criteria',
                      'status', 'value', 'due_at', 'submitted_at', 'submitted_by',
                      'reviewed_at', 'reviewed_by', 'rejection_reason', 'auto_accepted'],
        'bool'    => ['auto_accepted'],
        'num'     => ['item_order'],
    ],

    'feedback_rounds' => [
        'scope'   => 'project',
        'read'    => 'member',
        'insert'  => 'admin',
        'update'  => 'member',
        'delete'  => 'admin',
        // إرسال الجولة فقط (open -> submitted)؛ التصنيف والإقفال للأدمن
        // — يفرضه rule_feedback_round_update، و submitted_at من السيرفر
        'member_columns' => ['status'],
        'columns' => ['project_id', 'stage_id', 'round_number', 'status', 'is_paid',
                      'opened_at', 'submitted_at', 'closed_at'],
        'bool'    => ['is_paid'],
        'num'     => ['round_number'],
    ],

    'feedback_items' => [
        'scope'   => 'project',
        'read'    => 'member',
        'insert'  => 'member',
        'update'  => 'member',
        'delete'  => 'admin',
        // التصنيف (classification) قرار الأدمن؛ العميل يكتب ملاحظته أو اعتراضه
        // — و objection_at يختمه السيرفر في rule_feedback_item_update
        'member_columns' => ['description', 'page_or_section', 'objection_text'],
        'member_insert_columns' => ['round_id', 'project_id', 'description', 'page_or_section'],
        'columns' => ['round_id', 'project_id', 'description', 'page_or_section',
                      'classification', 'classified_at', 'classified_by', 'objection_text',
                      'objection_at', 'resolution'],
    ],

    'change_requests' => [
        'scope'   => 'project',
        'read'    => 'member',
        // العميل يسجّل طلبه بدون سعر؛ التسعير والإرسال من فريق أرقام
        'insert'  => 'member',
        'update'  => 'member',
        'delete'  => 'admin',
        // العميل يعتمد أو يرفض طلبًا مُرسَلًا إليه فقط — الانتقالات المسموحة
        // في rule_cr_update، و decided_by/decided_at يختمهما السيرفر
        'member_columns' => ['status', 'decision_note'],
        'member_insert_columns' => ['project_id', 'title', 'description', 'source_feedback_item_id'],
        'columns' => ['project_id', 'requested_by', 'source_feedback_item_id', 'title', 'description', 'price',
                      'currency', 'duration_days', 'delivery_impact_days', 'status', 'sent_at',
                      'quote_valid_until', 'decision_deadline', 'decided_at', 'decided_by',
                      'decision_note', 'resubmitted_from'],
        'num'     => ['price', 'duration_days', 'delivery_impact_days'],
    ],

    'app_settings' => [
        'scope'   => 'global',
        'read'    => 'any',
        'insert'  => 'admin',
        'update'  => 'admin',
        'delete'  => 'none',
        'columns' => ['warning_threshold_days', 'freeze_threshold_days', 'reactivation_fee',
                      'warranty_days', 'revision_rounds_allowed', 'stage_defaults'],
        'json'    => ['stage_defaults'],
        'num'     => ['warning_threshold_days', 'freeze_threshold_days', 'reactivation_fee',
                      'warranty_days', 'revision_rounds_allowed'],
    ],

    'cr_price_items' => [
        'scope'   => 'global',
        'read'    => 'any',
        'insert'  => 'admin',
        'update'  => 'admin',
        'delete'  => 'admin',
        'columns' => ['name', 'price', 'currency', 'duration_days'],
        'num'     => ['price', 'duration_days'],
    ],
];
