-- ============================================================================
--  ترقية: رفع الملفات على الاستضافة
--
--  بدل روابط جوجل درايف، العميل يرفع ملفاته على السيرفر مباشرة. الجدول ده
--  يحمل بيانات كل ملف، والملف نفسه يتخزن في api/storage/uploads خارج متناول
--  المتصفح ولا يُقدَّم إلا عبر /api/files/{id} بعد فحص الصلاحية.
--
--  project_id يبقى NULL أثناء ملء النموذج (المشروع لسه ما اتسجّلش)، ويُضبط
--  لحظة حفظ الطلب عبر /api/files/claim.
--
--  التشغيل:  mysql -u root -p arqam_flow < db/migrations/005_uploads.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS uploads (
  id            CHAR(36)        NOT NULL PRIMARY KEY,
  project_id    CHAR(36)        NULL,
  user_id       CHAR(36)        NULL,
  original_name VARCHAR(255)    NOT NULL,
  stored_path   VARCHAR(255)    NOT NULL,
  mime          VARCHAR(127)    NOT NULL DEFAULT '',
  size_bytes    INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY uploads_project_idx (project_id),
  KEY uploads_user_idx (user_id),
  CONSTRAINT uploads_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT uploads_user_fk    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
