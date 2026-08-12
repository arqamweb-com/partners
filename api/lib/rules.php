<?php
/**
 * قواعد العمل — النقل الحرفي لـ triggers قاعدة البيانات في Postgres.
 *
 * كانت في Postgres:
 *   enforce_stage_rules()        -> rule_stage_update() / rule_stage_delete()
 *   enforce_feedback_window()    -> rule_feedback_item_insert()
 *   enforce_round_one_way()      -> rule_feedback_round_update()
 *   enforce_cr_resubmit_once()   -> rule_cr_insert()
 *   add_business_days()          -> add_business_days()
 *   sync_adjusted_delivery()     -> rule_project_write()
 *   block_audit_mutation()       -> معطَّل عبر 'update'/'delete' => 'none' في schema.php
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// أيام العمل — أسبوع العمل من الأحد للخميس، والجمعة والسبت إجازة دائمة
// ---------------------------------------------------------------------------

function holiday_dates(): array
{
    static $dates = null;
    if ($dates === null) {
        $dates = array_column(db_all('SELECT holiday_date FROM holidays'), 'holiday_date');
    }

    return $dates;
}

function is_business_day(DateTimeImmutable $d, array $holidays): bool
{
    $dow = (int) $d->format('N');           // 1 = الاثنين ... 5 = الجمعة، 6 = السبت
    if ($dow === 5 || $dow === 6) {
        return false;
    }

    return !in_array($d->format('Y-m-d'), $holidays, true);
}

/** يقابل public.add_business_days(date, integer) */
function add_business_days(string $from, int $days): string
{
    $holidays = holiday_dates();
    $d = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
    $left = max(0, $days);

    // حد أمان: يمنع الدوران اللانهائي لو الأرقام جت غلط
    $guard = 0;
    while ($left > 0 && $guard++ < 10000) {
        $d = $d->modify('+1 day');
        if (is_business_day($d, $holidays)) {
            $left--;
        }
    }

    return $d->format('Y-m-d');
}

// ---------------------------------------------------------------------------
// المشاريع — إعادة حساب تاريخ التسليم المعدَّل
// ---------------------------------------------------------------------------

/**
 * معرّفات أنواع المشاريع المسموحة — لازم تطابق src/lib/project-types.ts
 * (القوالب نفسها في الواجهة؛ هنا المعرّفات فقط للتحقق).
 */
const PROJECT_TYPE_IDS = ['brochure', 'woocommerce', 'laravel', 'landing'];

/**
 * كتابة المشروع:
 *   - يقابل sync_adjusted_delivery: adjusted_delivery_date تُحسب دائمًا من
 *     original_delivery_date + client_delay_days بأيام العمل ولا تُترك للعميل
 *   - المنشئ هو المالك، ومشروع العميل يبدأ طلبًا ينتظر مراجعة فريق أرقام
 *   - الاعتماد في اتجاه واحد، والميثاق المعتمد لا يعدّله العميل
 */
function rule_project_write(array $values, ?array $existing): array
{
    if (array_key_exists('project_type', $values)
        && !in_array($values['project_type'], PROJECT_TYPE_IDS, true)) {
        throw new ApiError('نوع المشروع غير معروف.');
    }

    // المنشئ هو المالك دائمًا — لا يُقبل owner_id من الطلب
    if ($existing === null) {
        $user = require_user();
        $values['owner_id'] = $user['id'];

        if (!is_admin()) {
            // مشروع العميل يبدأ كطلب ينتظر مراجعة فريق أرقام. المراحل والقوائم
            // لا تُبذَر إلا عند الاعتماد، والحالة والمسار وأيام التأخير كلها
            // من صلاحيات فريق أرقام.
            $values['status'] = 'draft';
            $values['track'] = 'normal';
            $values['client_delay_days'] = 0;

            if (trim((string) ($values['owner_name'] ?? '')) === '') {
                $profile = db_one('SELECT full_name FROM profiles WHERE id = ?', [$user['id']]);
                $values['owner_name'] = $profile['full_name'] ?? '';
            }
        }
    } else {
        if (!is_admin()) {
            // العميل لا يحرّك حالة مشروعه — الاعتماد قرار فريق أرقام وحده
            unset($values['status']);

            // وبعد الاعتماد يصبح الميثاق مرجعًا لا يُعدَّل إلا بطلب تغيير
            if ($existing['status'] !== 'draft') {
                throw new ApiError(
                    'الميثاق معتمد ولا يُعدَّل إلا عبر طلب تغيير.',
                    409
                );
            }
        }

        // الاعتماد في اتجاه واحد: لا رجوع بمشروع قائم إلى حالة طلب
        if (($values['status'] ?? null) === 'draft' && $existing['status'] !== 'draft') {
            throw new ApiError('لا يمكن إرجاع مشروع معتمد إلى حالة طلب.', 409);
        }
    }

    $original = $values['original_delivery_date'] ?? $existing['original_delivery_date'] ?? null;
    $delay    = $values['client_delay_days']      ?? $existing['client_delay_days']      ?? 0;

    $originalChanged = array_key_exists('original_delivery_date', $values)
        && $existing !== null
        && $values['original_delivery_date'] !== $existing['original_delivery_date'];

    $delayChanged = array_key_exists('client_delay_days', $values)
        && $existing !== null
        && (int) $values['client_delay_days'] !== (int) $existing['client_delay_days'];

    $isInsert = $existing === null;

    if ($original !== null && ($isInsert || $originalChanged || $delayChanged)) {
        $values['adjusted_delivery_date'] = add_business_days((string) $original, (int) $delay);
    }

    if ($isInsert && empty($values['adjusted_delivery_date']) && $original !== null) {
        $values['adjusted_delivery_date'] = (string) $original;
    }

    return $values;
}

// ---------------------------------------------------------------------------
// المراحل — القفل نهائي وفي اتجاه واحد
// ---------------------------------------------------------------------------

/** يقابل enforce_stage_rules عند التعديل. */
function rule_stage_update(array $values, array $existing): array
{
    if ($existing['locked_at'] !== null) {
        throw new ApiError('هذه المرحلة مقفولة. أي تعديل عليها يتطلب طلب تغيير.', 409);
    }

    $becomingActive = ($values['status'] ?? null) === 'active' && $existing['status'] !== 'active';
    $isParallel = array_key_exists('is_parallel', $values)
        ? (bool) $values['is_parallel']
        : (bool) $existing['is_parallel'];
    $index = (int) ($values['stage_index'] ?? $existing['stage_index']);

    if ($becomingActive && !$isParallel && $index > 0) {
        $prev = db_one(
            'SELECT locked_at FROM stages
             WHERE project_id = ? AND stage_index = ? AND is_parallel = 0',
            [$existing['project_id'], $index - 1]
        );
        if (!$prev || $prev['locked_at'] === null) {
            throw new ApiError('لا يمكن بدء هذه المرحلة قبل إقفال المرحلة السابقة.', 409);
        }
    }

    if (!empty($values['locked_at'])) {
        $values['status'] = 'locked';
    }

    return $values;
}

/** يقابل enforce_stage_rules عند الحذف. */
function rule_stage_delete(array $existing): void
{
    if ($existing['locked_at'] !== null) {
        throw new ApiError('هذه المرحلة مقفولة ولا يمكن حذفها.', 409);
    }
}

// ---------------------------------------------------------------------------
// الملاحظات
// ---------------------------------------------------------------------------

/** يقابل enforce_feedback_window: لا تُضاف ملاحظة لجولة غير مفتوحة. */
function rule_feedback_item_insert(array $values): void
{
    $roundId = $values['round_id'] ?? null;
    if (!$roundId) {
        throw new ApiError('الملاحظة لازم تكون تابعة لجولة.');
    }

    $round = db_one('SELECT status FROM feedback_rounds WHERE id = ?', [$roundId]);
    if (!$round) {
        throw new ApiError('جولة الملاحظات غير موجودة.', 404);
    }
    if ($round['status'] !== 'open') {
        throw new ApiError('نافذة الملاحظات لهذه الجولة مقفولة. لا يمكن إضافة ملاحظات جديدة.', 409);
    }
}

/** يقابل enforce_round_one_way: الجولة المُرسلة لا تُعاد للفتح. */
function rule_feedback_round_update(array $values, array $existing): array
{
    if ($existing['status'] !== 'open' && ($values['status'] ?? null) === 'open') {
        throw new ApiError('لا يمكن إعادة فتح جولة ملاحظات مُرسلة.', 409);
    }

    if (!is_admin() && array_key_exists('status', $values)) {
        // العميل يرسل جولته فقط؛ التصنيف والإقفال قرار فريق أرقام
        if ($existing['status'] !== 'open' || $values['status'] !== 'submitted') {
            throw new ApiError('تصنيف جولة الملاحظات وإقفالها من فريق أرقام.', 403);
        }
        $values['submitted_at'] = now_utc();
    }

    return $values;
}

/** وقت الاعتراض يُختم من السيرفر، فلا يُقدَّم ولا يُؤخَّر من المتصفح. */
function rule_feedback_item_update(array $values): array
{
    if (!is_admin() && trim((string) ($values['objection_text'] ?? '')) !== '') {
        $values['objection_at'] = now_utc();
    }

    return $values;
}

// ---------------------------------------------------------------------------
// قوائم الوصول والمحتوى
// ---------------------------------------------------------------------------

/** من يعلّم بند الوصول مُسلَّمًا يُسجَّل عليه هو، ووقت التسليم من السيرفر. */
function rule_access_item_update(array $values): array
{
    if (is_admin() || !array_key_exists('is_done', $values)) {
        return $values;
    }

    $done = (bool) $values['is_done'];
    $values['provided_by'] = $done ? require_user()['id'] : null;
    $values['provided_at'] = $done ? now_utc() : null;

    return $values;
}

/**
 * تقديم المحتوى حق العميل، وقبوله أو رفضه قرار فريق أرقام وحده.
 *
 * كانت member_columns تسمح للعميل بكتابة status و reviewed_at مباشرة،
 * فكان يقدر يحط 'accepted' لنفسه ويتخطى المراجعة كلها.
 */
function rule_content_item_update(array $values, array $existing): array
{
    if (is_admin()) {
        return $values;
    }

    if ($existing['status'] === 'accepted') {
        throw new ApiError('العنصر مقبول ولا يُعدَّل.', 409);
    }

    $status = $values['status'] ?? null;
    if ($status !== null && $status !== 'submitted') {
        throw new ApiError('قبول المحتوى أو رفضه قرار فريق أرقام.', 403);
    }

    if ($status === 'submitted') {
        $values['submitted_by'] = require_user()['id'];
        // إعادة التقديم لا تصفّر تاريخ التقديم الأصلي — عليه يُحسب التأخير
        $values['submitted_at'] = $existing['submitted_at'] ?? now_utc();
        $values['reviewed_at'] = null;
        $values['reviewed_by'] = null;
        $values['rejection_reason'] = '';
    }

    return $values;
}

// ---------------------------------------------------------------------------
// سجل التدقيق
// ---------------------------------------------------------------------------

/**
 * السجل هو الدليل التعاقدي، فلا يُقبل فيه فاعل من الطلب.
 * الفرونت يرسل actor_id و actor_name (انظر src/lib/audit.ts) ونتجاهل الأول
 * دائمًا — وإلا نسب أي عضو إدخالًا لفريق أرقام. والاسم يُقبل من الأدمن وحده
 * حتى تبقى الإجراءات التلقائية مسجَّلة باسم «النظام».
 */
function rule_audit_insert(array $values): array
{
    $user = require_user();
    $values['actor_id'] = $user['id'];

    if (!is_admin() || trim((string) ($values['actor_name'] ?? '')) === '') {
        $values['actor_name'] = actor_name();
    }

    $values['event_type'] = mb_substr(trim((string) ($values['event_type'] ?? '')), 0, 64);
    if ($values['event_type'] === '') {
        throw new ApiError('نوع الحدث مطلوب.');
    }

    return $values;
}

// ---------------------------------------------------------------------------
// طلبات التغيير
// ---------------------------------------------------------------------------

/**
 * اعتماد طلب التغيير يمدّ تاريخ التسليم بمقدار delivery_impact_days أيام عمل.
 *
 * في النسخة القديمة كان الفرونت هو اللي بيعدّل جدول projects بعد الاعتماد،
 * وسياسة RLS كانت بتمنع العميل من ذلك بصمت — فالتاريخ ما كانش بيتمدّ لما
 * العميل هو اللي يعتمد. هنا الامتداد يتم في السيرفر داخل نفس المعاملة،
 * فيشتغل لأي معتمِد، والعميل لا يستطيع تمرير تاريخ من عنده.
 */
function rule_cr_approval_side_effect(array $values, array $existing): void
{
    $becomingApproved = ($values['status'] ?? null) === 'approved' && $existing['status'] !== 'approved';
    $impact = (int) ($values['delivery_impact_days'] ?? $existing['delivery_impact_days'] ?? 0);

    if (!$becomingApproved || $impact <= 0) {
        return;
    }

    $project = db_one('SELECT adjusted_delivery_date FROM projects WHERE id = ?', [$existing['project_id']]);
    if (!$project) {
        return;
    }

    db_run(
        'UPDATE projects SET adjusted_delivery_date = ? WHERE id = ?',
        [add_business_days((string) $project['adjusted_delivery_date'], $impact), $existing['project_id']]
    );
}

/** حالات القرار النهائي — لا يُعاد فتحها ولا يُعاد اتخاذ القرار فيها. */
const CR_FINAL_STATUSES = ['approved', 'rejected', 'expired', 'withdrawn'];

/**
 * تعديل طلب التغيير.
 *
 * القرار نهائي في الاتجاهين. وهذا بالذات ما يسدّ ثغرة تمديد التسليم المتكرر:
 * بدون هذا الشرط كان يمكن التنقّل approved -> sent -> approved فيُضاف
 * delivery_impact_days لتاريخ التسليم مرة بعد مرة بلا حد.
 *
 * والعميل لا يكتب الحالة كيفما شاء: يعتمد أو يرفض طلبًا مُرسَلًا إليه، لا أكثر.
 */
function rule_cr_update(array $values, array $existing): array
{
    $status = $values['status'] ?? null;
    $changing = $status !== null && $status !== $existing['status'];

    if ($changing && in_array($existing['status'], CR_FINAL_STATUSES, true)) {
        throw new ApiError('قرار طلب التغيير نهائي ولا يُعاد فتحه.', 409);
    }

    if (!is_admin()) {
        if ($changing) {
            if ($existing['status'] !== 'sent' || !in_array($status, ['approved', 'rejected'], true)) {
                throw new ApiError('تسعير طلب التغيير وإرساله من فريق أرقام.', 403);
            }
            $values['decided_by'] = require_user()['id'];
            $values['decided_at'] = now_utc();
        } else {
            unset($values['decided_by'], $values['decided_at']);
        }
    }

    rule_cr_approval_side_effect($values, $existing);

    return $values;
}

/**
 * إنشاء طلب تغيير:
 *   - يقابل enforce_cr_resubmit_once: الطلب المرفوض يُعاد تقديمه مرة واحدة فقط
 *   - طلب العميل يُسجَّل مسودة بلا سعر ولا مدة: التسعير قرار فريق أرقام،
 *     والطلب لا يصل للعميل للاعتماد إلا بعد أن يُسعَّر ويُرسل
 */
function rule_cr_insert(array $values): array
{
    if (!is_admin()) {
        $values['requested_by'] = require_user()['id'];
        $values['status'] = 'draft';
        $values['price'] = 0;
        $values['duration_days'] = 0;
        $values['delivery_impact_days'] = 0;

        if (mb_strlen(trim((string) ($values['title'] ?? ''))) < 5) {
            throw new ApiError('اكتب عنوانًا واضحًا للطلب (5 أحرف على الأقل).');
        }
    }

    $source = $values['resubmitted_from'] ?? null;
    if ($source) {
        $row = db_one(
            'SELECT COUNT(*) AS c FROM change_requests WHERE resubmitted_from = ?',
            [$source]
        );
        if ((int) ($row['c'] ?? 0) >= 1) {
            throw new ApiError('لا يمكن إعادة تقديم طلب التغيير أكثر من مرة واحدة.', 409);
        }
    }

    return $values;
}

// ---------------------------------------------------------------------------
// الموزّع: يُستدعى من query.php قبل كل كتابة
// ---------------------------------------------------------------------------

/**
 * دعوة عميل بالبريد. لو كان مسجَّلًا بالفعل نربطه بالمشروع في الحال،
 * وإلا تنتظر الدعوة حتى ينشئ حسابه (claim_project_invites).
 */
function rule_invite_insert(array $values): array
{
    $email = mb_strtolower(trim((string) ($values['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('البريد الإلكتروني غير صالح.');
    }

    $values['email'] = $email;
    $values['invited_by'] = require_user()['id'];

    $user = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($user) {
        db_run(
            'INSERT IGNORE INTO project_members (id, project_id, user_id) VALUES (?, ?, ?)',
            [uuid4(), $values['project_id'], $user['id']]
        );
        $values['claimed_at'] = now_utc();
    }

    return $values;
}

function apply_insert_rules(string $table, array $values): array
{
    return match ($table) {
        'projects'        => rule_project_write($values, null),
        'project_invites' => rule_invite_insert($values),
        'feedback_items'  => (function () use ($values) { rule_feedback_item_insert($values); return $values; })(),
        'change_requests' => rule_cr_insert($values),
        'audit_log'       => rule_audit_insert($values),
        default           => $values,
    };
}

function apply_update_rules(string $table, array $values, array $existing): array
{
    return match ($table) {
        'projects'        => rule_project_write($values, $existing),
        'stages'          => rule_stage_update($values, $existing),
        'feedback_rounds' => rule_feedback_round_update($values, $existing),
        'feedback_items'  => rule_feedback_item_update($values),
        'access_items'    => rule_access_item_update($values),
        'content_items'   => rule_content_item_update($values, $existing),
        'change_requests' => rule_cr_update($values, $existing),
        default           => $values,
    };
}

function apply_delete_rules(string $table, array $existing): void
{
    if ($table === 'stages') {
        rule_stage_delete($existing);
    }
}
