<?php
/** يبني بيانات ثابتة للاختبار: أدمن + عميل + مشروع بمراحله وقوائمه. */

declare(strict_types=1);
require __DIR__ . '/boot.php';

db()->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['audit_log','gate_approvals','feedback_items','feedback_rounds','change_requests',
          'content_items','access_items','stages','project_members','project_invites',
          'projects','user_roles','profiles','users','uploads'] as $t) {
    db()->exec("TRUNCATE TABLE `$t`");
}
db()->exec('SET FOREIGN_KEY_CHECKS = 1');

function mk_user(string $email, string $name, string $role): string
{
    $id = uuid4();
    db_run('INSERT INTO users (id,email,password_hash) VALUES (?,?,?)',
        [$id, $email, password_hash('password123', PASSWORD_DEFAULT)]);
    db_run('INSERT INTO profiles (id,full_name,email) VALUES (?,?,?)', [$id, $name, $email]);
    db_run('INSERT INTO user_roles (id,user_id,role) VALUES (?,?,?)', [uuid4(), $id, $role]);
    return $id;
}

$admin  = mk_user('admin@test.local', 'مدير أرقام', 'admin');
$client = mk_user('client@test.local', 'عميل الاختبار', 'client');

$project = uuid4();
db_run('INSERT INTO projects (id,name,owner_id,owner_name,status,original_delivery_date,adjusted_delivery_date)
        VALUES (?,?,?,?,?,?,?)',
    [$project, 'مشروع اختبار', $admin, 'مدير أرقام', 'active', '2026-09-01', '2026-09-01']);
db_run('INSERT INTO project_members (id,project_id,user_id) VALUES (?,?,?)', [uuid4(), $project, $client]);

// مرحلتان: الأولى نشطة والكرة عند فريق أرقام، والثانية لسه pending
$stage0 = uuid4();
$stage1 = uuid4();
db_run("INSERT INTO stages (id,project_id,stage_index,name,status,ball_in_court,started_at)
        VALUES (?,?,0,'التصميم','active','us',UTC_TIMESTAMP())", [$stage0, $project]);
db_run("INSERT INTO stages (id,project_id,stage_index,name,status,ball_in_court)
        VALUES (?,?,1,'التنفيذ','pending','us')", [$stage1, $project]);

// عنصر محتوى ينتظر تقديم العميل
$content = uuid4();
db_run("INSERT INTO content_items (id,project_id,item_group,item_order,name,status)
        VALUES (?,?, 'blocking',1,'نصوص الصفحة الرئيسية','pending')", [$content, $project]);

// بند وصول
$access = uuid4();
db_run("INSERT INTO access_items (id,project_id,item_order,name) VALUES (?,?,1,'بيانات الاستضافة')",
    [$access, $project]);

// جولة ملاحظات مفتوحة
$round = uuid4();
db_run("INSERT INTO feedback_rounds (id,project_id,round_number,status) VALUES (?,?,1,'open')",
    [$round, $project]);

// طلب تغيير مُرسَل للعميل بتأثير 5 أيام على التسليم
$cr = uuid4();
db_run("INSERT INTO change_requests (id,project_id,title,description,price,duration_days,
        delivery_impact_days,status,sent_at) VALUES (?,?,?,?,5000,5,5,'sent',UTC_TIMESTAMP())",
    [$cr, $project, 'إضافة صفحة منتجات', 'وصف الطلب']);

file_put_contents(__DIR__ . '/fixtures.json', json_encode(compact(
    'admin', 'client', 'project', 'stage0', 'stage1', 'content', 'access', 'round', 'cr'
)));

echo "fixtures ready\n";
