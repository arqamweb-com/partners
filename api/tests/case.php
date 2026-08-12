<?php
/** حالة اختبار واحدة لكل عملية — argv[1] = اسم الحالة. */

declare(strict_types=1);
require __DIR__ . '/boot.php';

$f = json_decode((string) file_get_contents(__DIR__ . '/fixtures.json'), true);
$case = $argv[1] ?? '';

switch ($case) {

// ---------------------------------------------------------------------------
case 'audit':
    login_as($f['client']);
    echo "سجل التدقيق:\n";
    q(['table' => 'audit_log', 'action' => 'insert', 'values' => [
        'project_id' => $f['project'],
        'actor_id'   => $f['admin'],            // محاولة انتحال الأدمن
        'actor_name' => 'فريق أرقام',
        'event_type' => 'gate_approved',
        'description'=> 'إدخال مزوَّر',
    ]]);
    $row = db_one('SELECT actor_id, actor_name FROM audit_log ORDER BY created_at DESC LIMIT 1');
    if ($row['actor_id'] === $f['admin']) {
        echo "  ❌ الفاعل اتزوّر: لسه مسجَّل باسم الأدمن\n"; exit(1);
    }
    if ($row['actor_id'] !== $f['client']) {
        echo "  ❌ الفاعل غلط: {$row['actor_id']}\n"; exit(1);
    }
    echo "  ✅ الفاعل اتكتب من الجلسة: {$row['actor_name']} (مش «فريق أرقام»)\n";
    break;

// ---------------------------------------------------------------------------
case 'gate':
    login_as($f['client']);
    echo "اعتماد بوابة مفبرك:\n";
    expect_blocked('العميل يزرع gate_approval مباشرة', fn() =>
        q(['table' => 'gate_approvals', 'action' => 'insert', 'values' => [
            'project_id' => $f['project'], 'stage_id' => $f['stage0'],
            'approved_by' => $f['admin'], 'approver_name' => 'مدير أرقام',
            'acknowledgement_text' => 'اعتماد مفبرك',
        ]]));
    break;

// ---------------------------------------------------------------------------
case 'stage_selfapprove':
    login_as($f['client']);
    echo "إقفال مرحلة بلا مراجعة:\n";
    // نحاكي ما بعد الرفض: الكرة عند العميل والحالة active
    db_run("UPDATE stages SET ball_in_court='them', status='active' WHERE id=?", [$f['stage0']]);
    expect_blocked('العميل يعتمد ويقفل مرحلته بنفسه', fn() =>
        stage_approve($f['stage0'], 'عميل الاختبار', 'موافق'));
    expect_blocked('العميل يرفض مرحلة لم تُقدَّم', fn() =>
        stage_reject($f['stage0'], 'سبب مكتوب بوضوح'));
    break;

case 'stage_pending':
    login_as($f['admin']);
    echo "تقديم مرحلة لم تبدأ:\n";
    expect_blocked('الأدمن يقدّم المرحلة الثانية وهي pending', fn() =>
        stage_submit($f['stage1'], 'تقديم مبكر'));
    break;

case 'stage_happy':
    login_as($f['admin']);
    echo "المسار السليم للمراحل:\n";
    expect_ok('الأدمن يقدّم المرحلة النشطة', fn() => stage_submit($f['stage0'], 'التصميم جاهز'));
    $s = db_one('SELECT status, ball_in_court FROM stages WHERE id=?', [$f['stage0']]);
    echo "     -> status={$s['status']} ball={$s['ball_in_court']}\n";
    break;

case 'stage_happy2':
    login_as($f['client']);
    echo "اعتماد العميل بعد التقديم:\n";
    expect_ok('العميل يعتمد المرحلة المقدَّمة', fn() =>
        stage_approve($f['stage0'], 'عميل الاختبار', 'أقر بالاستلام'));
    $s  = db_one('SELECT status, locked_at FROM stages WHERE id=?', [$f['stage0']]);
    $s1 = db_one('SELECT status FROM stages WHERE id=?', [$f['stage1']]);
    $g  = db_one('SELECT approver_name FROM gate_approvals WHERE stage_id=?', [$f['stage0']]);
    echo "     -> المرحلة: {$s['status']}، التالية: {$s1['status']}، سجل الاعتماد: {$g['approver_name']}\n";
    if ($s['status'] !== 'locked' || $s1['status'] !== 'active' || !$g) {
        echo "  ❌ المسار السليم اتكسر\n"; exit(1);
    }
    break;

// ---------------------------------------------------------------------------
case 'content':
    login_as($f['client']);
    echo "قبول المحتوى ذاتيًا:\n";
    expect_blocked('العميل يحط accepted لنفسه', fn() =>
        q(['table' => 'content_items', 'action' => 'update',
           'filters' => [['eq', 'id', $f['content']]],
           'values'  => ['status' => 'accepted', 'reviewed_at' => '2026-08-12T00:00:00.000Z']]));

    expect_ok('العميل يقدّم المحتوى (المسار السليم)', fn() =>
        q(['table' => 'content_items', 'action' => 'update',
           'filters' => [['eq', 'id', $f['content']]],
           'values'  => ['value' => 'النص المطلوب', 'status' => 'submitted',
                         'submitted_by' => $f['admin'], 'reviewed_at' => '2026-08-12T00:00:00.000Z']]));

    $row = db_one('SELECT status, submitted_by, submitted_at, reviewed_at FROM content_items WHERE id=?',
        [$f['content']]);
    if ($row['status'] !== 'submitted' || $row['submitted_by'] !== $f['client'] || $row['reviewed_at'] !== null) {
        echo "  ❌ الختم السيرفري غلط: " . json_encode($row) . "\n"; exit(1);
    }
    echo "  ✅ submitted_by اتكتب من الجلسة و reviewed_at اتصفّر\n";
    break;

// ---------------------------------------------------------------------------
case 'access':
    login_as($f['client']);
    echo "بند الوصول:\n";
    q(['table' => 'access_items', 'action' => 'update',
       'filters' => [['eq', 'id', $f['access']]],
       'values'  => ['is_done' => true, 'provided_by' => $f['admin']]]);
    $row = db_one('SELECT is_done, provided_by, provided_at FROM access_items WHERE id=?', [$f['access']]);
    if ($row['provided_by'] !== $f['client'] || $row['provided_at'] === null) {
        echo "  ❌ provided_by اتزوّر: " . json_encode($row) . "\n"; exit(1);
    }
    echo "  ✅ provided_by اتكتب من الجلسة ووقت التسليم من السيرفر\n";
    break;

// ---------------------------------------------------------------------------
case 'round':
    login_as($f['client']);
    echo "جولة الملاحظات:\n";
    expect_blocked('العميل يقفز بالجولة لـ classified', fn() =>
        q(['table' => 'feedback_rounds', 'action' => 'update',
           'filters' => [['eq', 'id', $f['round']]], 'values' => ['status' => 'classified']]));
    expect_ok('العميل يرسل جولته (المسار السليم)', fn() =>
        q(['table' => 'feedback_rounds', 'action' => 'update',
           'filters' => [['eq', 'id', $f['round']]], 'values' => ['status' => 'submitted']]));
    break;

// ---------------------------------------------------------------------------
case 'cr':
    login_as($f['client']);
    echo "طلب التغيير — تمديد التسليم المتكرر:\n";

    $before = db_one('SELECT adjusted_delivery_date FROM projects WHERE id=?', [$f['project']]);
    echo "     تاريخ التسليم قبل: {$before['adjusted_delivery_date']}\n";

    expect_ok('العميل يعتمد الطلب المُرسَل (مرة واحدة مشروعة)', fn() =>
        q(['table' => 'change_requests', 'action' => 'update',
           'filters' => [['eq', 'id', $f['cr']]],
           'values'  => ['status' => 'approved', 'decided_by' => $f['admin'],
                         'decision_note' => 'موافق']]));

    $after1 = db_one('SELECT adjusted_delivery_date FROM projects WHERE id=?', [$f['project']]);
    echo "     تاريخ التسليم بعد الاعتماد: {$after1['adjusted_delivery_date']}\n";

    $row = db_one('SELECT decided_by FROM change_requests WHERE id=?', [$f['cr']]);
    if ($row['decided_by'] !== $f['client']) {
        echo "  ❌ decided_by اتزوّر\n"; exit(1);
    }
    echo "  ✅ decided_by اتكتب من الجلسة\n";

    // محاولة الاستغلال: approved -> sent -> approved تاني
    expect_blocked('إرجاع الطلب المعتمد لـ sent', fn() =>
        q(['table' => 'change_requests', 'action' => 'update',
           'filters' => [['eq', 'id', $f['cr']]], 'values' => ['status' => 'sent']]));

    $after2 = db_one('SELECT adjusted_delivery_date FROM projects WHERE id=?', [$f['project']]);
    if ($after1['adjusted_delivery_date'] !== $after2['adjusted_delivery_date']) {
        echo "  ❌ التاريخ اتمدّ تاني!\n"; exit(1);
    }
    echo "  ✅ التاريخ اتمدّ مرة واحدة بس: {$after2['adjusted_delivery_date']}\n";
    break;

case 'cr_selfsend':
    login_as($f['client']);
    echo "العميل يسعّر ويرسل لنفسه:\n";
    $id = uuid4();
    db_run("INSERT INTO change_requests (id,project_id,title,delivery_impact_days,status)
            VALUES (?,?,'طلب مسودة',10,'draft')", [$id, $f['project']]);
    expect_blocked('العميل يعتمد طلبًا لم يُرسَل له', fn() =>
        q(['table' => 'change_requests', 'action' => 'update',
           'filters' => [['eq', 'id', $id]], 'values' => ['status' => 'approved']]));
    break;

// ---------------------------------------------------------------------------
case 'throttle':
    echo "كبح محاولات الدخول:\n";
    $blocked = false;
    for ($i = 1; $i <= 10; $i++) {
        try {
            auth_login('client@test.local', 'كلمة-غلط');
        } catch (ApiError $e) {
            if ($e->status() === 429) {
                echo "  ✅ اتقفل بعد $i محاولة — «{$e->getMessage()}»\n";
                $blocked = true;
                break;
            }
        }
    }
    if (!$blocked) { echo "  ❌ مفيش كبح\n"; exit(1); }

    // الحساب مقفول، بس الكلمة الصح المفروض تفضل مرفوضة لحد ما النافذة تعدّي
    try {
        auth_login('client@test.local', 'password123');
        echo "  ❌ الكبح اتخطى بكلمة المرور الصح\n"; exit(1);
    } catch (ApiError $e) {
        echo "  ✅ الكبح شغال حتى مع كلمة المرور الصح ({$e->status()})\n";
    }
    break;

default:
    fwrite(STDERR, "unknown case: $case\n");
    exit(2);
}
