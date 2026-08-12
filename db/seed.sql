-- ============================================================================
--  Arqam Flow Manager — بيانات أولية
--  يُشغَّل بعد schema.sql
--
--  الجزء الأول (إعدادات + بنود تسعير + أجازات) مطلوب لعمل النظام.
--  الجزء الثاني (مشاريع تجريبية) للتجربة فقط — احذفه قبل الرفع على الاستضافة.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) الإعدادات الافتراضية  (مطلوب)
-- ---------------------------------------------------------------------------
INSERT INTO app_settings (id, warning_threshold_days, freeze_threshold_days,
                          reactivation_fee, warranty_days, revision_rounds_allowed,
                          stage_defaults)
VALUES (1, 5, 10, 1500, 14, 2, JSON_ARRAY(
  JSON_OBJECT('name','اجتماع الاستلام',      'gate','ميثاق موقَّع',  'our',1,  'their',2),
  JSON_OBJECT('name','النطاق والمتطلبات',    'gate','Scope Lock',    'our',3,  'their',3),
  JSON_OBJECT('name','المحتوى والأصول',      'gate','Content Lock',  'our',0,  'their',6),
  JSON_OBJECT('name','التسعير',              'gate','Pricing Lock',  'our',2,  'their',3),
  JSON_OBJECT('name','التصميم',              'gate','Design Lock',   'our',6,  'their',3),
  JSON_OBJECT('name','البرمجة والتطوير',     'gate','Build Complete','our',12, 'their',0),
  JSON_OBJECT('name','الإطلاق والتسليم',     'gate','Go Live',       'our',2,  'their',1),
  JSON_OBJECT('name','الضمان',               'gate',NULL,            'our',14, 'their',0)
))
ON DUPLICATE KEY UPDATE id = id;

-- ---------------------------------------------------------------------------
-- 2) بنود التسعير الجاهزة  (مطلوب)
-- ---------------------------------------------------------------------------
INSERT INTO cr_price_items (id, name, price, duration_days) VALUES
  (UUID(), 'صفحة إضافية',                      900, 3),
  (UUID(), 'تعديل تصميم بعد Design Lock',     1200, 3),
  (UUID(), 'لغة إضافية',                      2500, 7),
  (UUID(), 'ربط بوابة دفع إضافية',            1500, 4),
  (UUID(), 'تكامل خارجي (API)',               2000, 5);

-- ---------------------------------------------------------------------------
-- 3) الأجازات الرسمية  (مطلوب — تؤثر على حساب أيام العمل)
-- ---------------------------------------------------------------------------
INSERT INTO holidays (id, holiday_date, label) VALUES
  (UUID(), '2026-08-10', 'إجازة رسمية'),
  (UUID(), '2026-09-23', 'اليوم الوطني');


-- ===========================================================================
--  ما بعد هذا السطر: بيانات تجريبية فقط
-- ===========================================================================

INSERT INTO projects
 (id, name, end_client_name, partner_agency, owner_name, track, status,
  original_delivery_date, client_delay_days, adjusted_delivery_date,
  warranty_days, revision_rounds_allowed, revision_rounds_used,
  scope, out_of_scope, notes, payment_milestones)
VALUES
 ('11111111-1111-4111-8111-111111111111','موقع شركة نُهى للتطوير العقاري','نُهى للتطوير العقاري','وكالة مدار للتسويق','سارة العتيبي','normal','awaiting_client',
  '2026-09-20', 4, '2026-09-24', 30, 2, 0,
  'موقع تعريفي من 8 صفحات مع مدونة ونظام إدارة محتوى ولوحة تحكم عربية.',
  'التطبيقات الجوالة، كتابة المحتوى، التصوير الفوتوغرافي، الحملات الإعلانية.',
  'العميل النهائي يفضّل هوية بصرية هادئة وخط عربي واضح.', JSON_ARRAY()),
 ('22222222-2222-4222-8222-222222222222','متجر أثر الحرفي','أثر للمنتجات الحرفية','وكالة مدار للتسويق','سارة العتيبي','fast_track','active',
  '2026-08-28', 0, '2026-08-28', 14, 2, 0,
  'متجر إلكتروني بصفحة منتجات وسلة ودفع.','ربط أنظمة المخزون الخارجية.','مسار سريع.', JSON_ARRAY()),
 ('33333333-3333-4333-8333-333333333333','منصة تدريب رواق','رواق للتدريب','وكالة صدى الرقمية','خالد المطيري','normal','frozen',
  '2026-07-30', 11, '2026-08-14', 14, 2, 1,
  'منصة دورات مع تسجيل مستخدمين.','بوابة دفع دولية.','مجمّد بسبب تأخر تسليم المحتوى.', JSON_ARRAY());

-- المراحل التسلسلية: ما قبل المرحلة الحالية مقفول، والحالية بحالتها، وما بعدها معلّق
INSERT INTO stages (id, project_id, stage_index, is_parallel, name, gate_name, gate_size,
                    our_duration_days, their_duration_days, ball_in_court, status,
                    started_at, due_at, locked_at, deliverables)
SELECT UUID(), p.pid, s.idx, 0, s.nm, s.gate, s.gsize, s.ours, s.theirs,
  CASE WHEN s.idx = p.cur THEN p.curball ELSE 'us' END,
  CASE WHEN s.idx < p.cur THEN 'locked'
       WHEN s.idx = p.cur THEN p.curstatus
       ELSE 'pending' END,
  CASE WHEN s.idx <= p.cur THEN DATE_SUB(NOW(), INTERVAL ((p.cur - s.idx + 1) * 6) DAY) END,
  CASE WHEN s.idx = p.cur THEN DATE_ADD(NOW(), INTERVAL 2 DAY) END,
  CASE WHEN s.idx < p.cur THEN DATE_SUB(NOW(), INTERVAL ((p.cur - s.idx) * 6) DAY) END,
  JSON_ARRAY()
FROM (
            SELECT '11111111-1111-4111-8111-111111111111' AS pid, 4 AS cur, 'them' AS curball, 'awaiting_approval' AS curstatus
  UNION ALL SELECT '22222222-2222-4222-8222-222222222222',        2,        'us',            'active'
  UNION ALL SELECT '33333333-3333-4333-8333-333333333333',        2,        'them',          'frozen'
) AS p
CROSS JOIN (
            SELECT 0 AS idx, 'اجتماع الاستلام'     AS nm, 'ميثاق موقّع'    AS gate, 'small' AS gsize, 2  AS ours, 3 AS theirs
  UNION ALL SELECT 1,        'النطاق والمتطلبات',       'Scope Lock',           'small',        2,        3
  UNION ALL SELECT 2,        'المحتوى والأصول',         'Content Lock',         'small',        0,        7
  UNION ALL SELECT 3,        'التسعير',                 'Pricing Lock',         'big',          2,        3
  UNION ALL SELECT 4,        'التصميم',                 'Design Lock',          'big',          7,        4
  UNION ALL SELECT 5,        'البرمجة',                 'Build Complete',       'big',          15,       0
  UNION ALL SELECT 6,        'الإطلاق والتسليم',        'Delivery Sign-off',    'small',        3,        1
  UNION ALL SELECT 7,        'الضمان',                  NULL,                   'small',        30,       0
) AS s;

-- المسار المتوازي: الوصول والحسابات
INSERT INTO stages (id, project_id, stage_index, is_parallel, name, gate_name, gate_size,
                    our_duration_days, their_duration_days, ball_in_court, status,
                    started_at, due_at, deliverables)
SELECT UUID(), id, 100, 1, 'الوصول والحسابات', 'Access Lock', 'small', 0, 5, 'them', 'active',
       DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), JSON_ARRAY()
FROM projects;

INSERT INTO audit_log (id, project_id, actor_name, event_type, description, created_at) VALUES
 (UUID(),'11111111-1111-4111-8111-111111111111','فريق أرقام','stage_locked','تم إقفال مرحلة التسعير بعد اعتماد العميل.', DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'11111111-1111-4111-8111-111111111111','سارة العتيبي','gate_approved','اعتماد بوابة Pricing Lock.', DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'11111111-1111-4111-8111-111111111111','النظام','delay_recorded','تم تسجيل 4 أيام عمل تأخير من طرف العميل وتعديل تاريخ التسليم.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (UUID(),'22222222-2222-4222-8222-222222222222','فريق أرقام','project_created','تم إنشاء المشروع على المسار السريع.', DATE_SUB(NOW(), INTERVAL 12 DAY)),
 (UUID(),'33333333-3333-4333-8333-333333333333','النظام','project_frozen','تجاوز التأخير 10 أيام عمل، تم تجميد المشروع.', DATE_SUB(NOW(), INTERVAL 5 DAY));
