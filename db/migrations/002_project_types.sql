-- ============================================================================
--  ترقية: أنواع المشاريع وحالة «طلب قيد المراجعة»
--
--  1) عمود project_type — يحدد قالب المراحل والقوائم عند اعتماد المشروع
--  2) توسيع حالة المشروع بقيمة draft — طلب أرسله العميل ولم يعتمده فريق أرقام
--  3) تواريخ التسليم تقبل NULL — العميل يسجّل الأساسيات فقط، والتاريخ بند
--     تعاقدي يكتبه فريق أرقام عند مراجعة الطلب
--
--  التشغيل:  mysql -u root -p arqam_flow < db/migrations/002_project_types.sql
--
--  آمن على البيانات القائمة: المشاريع الموجودة تاخد 'brochure' وحالتها وتواريخها
--  لا تتغير.
-- ============================================================================

SET NAMES utf8mb4;

-- VARCHAR وليس ENUM: إضافة نوع مشروع جديد تبقى تعديل كود فقط، بدون ترقية
-- قاعدة بيانات. القيم المسموحة تُفحص في api/lib/rules.php
ALTER TABLE projects
  ADD COLUMN project_type VARCHAR(32) NOT NULL DEFAULT 'brochure' AFTER partner_agency;

-- 'draft' في آخر القائمة عمدًا: الإضافة في النهاية لا تغيّر ترتيب القيم
-- الحالية فتنفّذها MySQL بسرعة وبدون إعادة بناء الجدول.
ALTER TABLE projects
  MODIFY COLUMN status
    ENUM('active','awaiting_client','frozen','completed','stopped','draft')
    NOT NULL DEFAULT 'active';

-- طلب العميل لا يحمل تاريخ تسليم بعد
ALTER TABLE projects
  MODIFY COLUMN original_delivery_date DATE NULL,
  MODIFY COLUMN adjusted_delivery_date DATE NULL;

CREATE INDEX projects_type_idx ON projects(project_type);
