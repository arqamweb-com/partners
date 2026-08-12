<?php
/**
 * دورة اعتماد المراحل — في الاتجاهين.
 *
 *   صاحب الدور يقدّم المرحلة  ->  awaiting_approval والكرة تنتقل للطرف الآخر
 *   الطرف الآخر يعتمد          ->  locked (مقفولة نهائيًا) وتبدأ المرحلة التالية
 *   أو يرفض بملاحظات           ->  active والكرة ترجع لصاحبها مع سبب الرفض
 *
 * مثال: الأدمن يرفع التصميم ويقدّمه، فالعميل يعتمده أو يرفضه بملاحظات.
 * والعكس: العميل يجهّز مرحلته ويقدّمها، فالأدمن يعتمدها أو يرفضها.
 *
 * الانتقالات هنا وليست في تعديل أعمدة مباشر، حتى لا يستطيع أي طرف إقفال
 * مرحلة ليست من حقه أو تزوير الكرة في ملعب الآخر.
 */

declare(strict_types=1);

/** جهة المستخدم الحالي في هذا المشروع: 'us' لفريق أرقام و'them' للعميل. */
function my_side(): string
{
    return is_admin() ? 'us' : 'them';
}

function other_side(string $side): string
{
    return $side === 'us' ? 'them' : 'us';
}

function side_label(string $side): string
{
    return $side === 'us' ? 'فريق أرقام' : 'العميل';
}

/** يجلب المرحلة بعد التأكد أن المستخدم عضو في مشروعها. */
function load_stage(string $stageId): array
{
    $stage = db_one('SELECT * FROM stages WHERE id = ?', [$stageId]);
    if (!$stage) {
        throw new ApiError('المرحلة غير موجودة.', 404);
    }
    if (!is_admin() && !is_project_member($stage['project_id'])) {
        throw new ApiError('ليس لديك صلاحية على هذا المشروع.', 403);
    }
    if ($stage['locked_at'] !== null) {
        throw new ApiError('هذه المرحلة مقفولة. أي تعديل عليها يتطلب طلب تغيير.', 409);
    }

    return $stage;
}

function actor_name(): string
{
    $user = require_user();
    $profile = db_one('SELECT full_name FROM profiles WHERE id = ?', [$user['id']]);

    return $profile['full_name'] ?: $user['email'];
}

function write_audit(string $projectId, string $eventType, string $description): void
{
    db_run(
        'INSERT INTO audit_log (id, project_id, actor_id, actor_name, event_type, description)
         VALUES (?, ?, ?, ?, ?, ?)',
        [uuid4(), $projectId, current_user()['id'], actor_name(), $eventType, $description]
    );
}

// ---------------------------------------------------------------------------

/** تقديم المرحلة للطرف الآخر لمراجعتها. */
function stage_submit(string $stageId, string $note): array
{
    $stage = load_stage($stageId);
    $side = my_side();

    if ($stage['ball_in_court'] !== $side) {
        throw new ApiError('الدور الآن على ' . side_label($stage['ball_in_court']) . '، فلا يمكنك تقديم هذه المرحلة.', 409);
    }
    if ($stage['status'] === 'awaiting_approval') {
        throw new ApiError('المرحلة مقدَّمة بالفعل وفي انتظار المراجعة.', 409);
    }
    // مرحلة لم تبدأ بعد لا تُقدَّم — وإلا أمكن تخطّي ترتيب المراحل بتقديم
    // مرحلة متأخرة وإقفالها قبل أن تُقفل التي قبلها
    if ($stage['status'] !== 'active') {
        throw new ApiError('هذه المرحلة لم تبدأ بعد.', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run(
            "UPDATE stages
             SET status = 'awaiting_approval', ball_in_court = ?, submitted_at = ?,
                 submitted_by = ?, submission_note = ?, rejection_reason = NULL
             WHERE id = ?",
            [other_side($side), now_utc(), current_user()['id'], mb_substr(trim($note), 0, 4000), $stageId]
        );

        write_audit(
            $stage['project_id'],
            'stage_submitted',
            sprintf('تقديم مرحلة «%s» من %s لمراجعة %s.%s',
                $stage['name'], side_label($side), side_label(other_side($side)),
                trim($note) !== '' ? ' ملاحظة: ' . mb_substr(trim($note), 0, 300) : '')
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return db_one('SELECT * FROM stages WHERE id = ?', [$stageId]) ?? [];
}

/** اعتماد المرحلة وإقفالها نهائيًا، وبدء المرحلة التالية. */
function stage_approve(string $stageId, string $approverName, string $acknowledgement): array
{
    $stage = load_stage($stageId);
    $side = my_side();

    if ($stage['ball_in_court'] !== $side) {
        throw new ApiError('هذه المرحلة ليست في انتظار مراجعتك.', 409);
    }
    // الكرة في ملعبك لا تكفي: لازم تكون المرحلة مقدَّمة فعلًا للمراجعة.
    // بدون هذا الشرط كان الطرف الذي رُفض عمله (وترجع له الكرة بحالة active)
    // يستطيع اعتماد مرحلته بنفسه وإقفالها نهائيًا بلا مراجعة من أحد.
    if ($stage['status'] !== 'awaiting_approval') {
        throw new ApiError('المرحلة لم تُقدَّم للمراجعة بعد، فلا يوجد ما يُعتمد.', 409);
    }
    if (mb_strlen(trim($approverName)) < 3) {
        throw new ApiError('اكتب اسمك كاملًا للاعتماد.');
    }

    $user = current_user();
    $now = now_utc();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run(
            "UPDATE stages SET locked_at = ?, locked_by = ?, status = 'locked' WHERE id = ?",
            [$now, $user['id'], $stageId]
        );

        db_run(
            'INSERT INTO gate_approvals
             (id, project_id, stage_id, approved_by, approver_name, acknowledgement_text, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [uuid4(), $stage['project_id'], $stageId, $user['id'],
             mb_substr(trim($approverName), 0, 255), mb_substr($acknowledgement, 0, 4000), $now]
        );

        // المرحلة التالية تبدأ والكرة ترجع لفريق أرقام
        if (!$stage['is_parallel']) {
            db_run(
                "UPDATE stages SET status = 'active', started_at = ?, ball_in_court = 'us'
                 WHERE project_id = ? AND stage_index = ? AND is_parallel = 0 AND locked_at IS NULL",
                [$now, $stage['project_id'], (int) $stage['stage_index'] + 1]
            );
        }

        write_audit(
            $stage['project_id'],
            'gate_approved',
            sprintf('اعتماد وإقفال مرحلة «%s»%s من %s.',
                $stage['name'],
                $stage['gate_name'] ? ' — بوابة ' . $stage['gate_name'] : '',
                side_label($side))
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return db_one('SELECT * FROM stages WHERE id = ?', [$stageId]) ?? [];
}

/** رفض المرحلة وإرجاعها لصاحبها مع سبب مكتوب. */
function stage_reject(string $stageId, string $reason): array
{
    $stage = load_stage($stageId);
    $side = my_side();

    if ($stage['ball_in_court'] !== $side) {
        throw new ApiError('هذه المرحلة ليست في انتظار مراجعتك.', 409);
    }
    if ($stage['status'] !== 'awaiting_approval') {
        throw new ApiError('المرحلة لم تُقدَّم للمراجعة بعد، فلا يوجد ما يُرفض.', 409);
    }

    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new ApiError('سبب الرفض مطلوب ومكتوب بوضوح (5 أحرف على الأقل).');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run(
            "UPDATE stages
             SET status = 'active', ball_in_court = ?, rejection_reason = ?, rejected_at = ?,
                 rejected_by = ?, rejection_count = rejection_count + 1, submitted_at = NULL
             WHERE id = ?",
            [other_side($side), mb_substr($reason, 0, 4000), now_utc(), current_user()['id'], $stageId]
        );

        write_audit(
            $stage['project_id'],
            'stage_rejected',
            sprintf('رفض مرحلة «%s» من %s — %s', $stage['name'], side_label($side), mb_substr($reason, 0, 500))
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return db_one('SELECT * FROM stages WHERE id = ?', [$stageId]) ?? [];
}
