-- ============================================================================
--  ترقية: بوابة العميل
--
--  1) جدول دعوات المشاريع — ربط العميل بمشروعه عن طريق البريد الإلكتروني
--  2) أعمدة تقديم/رفض المراحل — الاعتماد والرفض في الاتجاهين
--
--  التشغيل:  mysql -u root -p arqam_flow < db/migrations/001_client_portal.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) دعوات المشاريع
--
-- الأدمن يضيف بريد العميل على المشروع. لو العميل مسجَّل بالفعل يُربط فورًا،
-- ولو سجّل لاحقًا يُربط تلقائيًا لحظة إنشاء حسابه.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_invites (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  project_id CHAR(36)     NOT NULL,
  email      VARCHAR(255) NOT NULL,
  invited_by CHAR(36)     NULL,
  claimed_at DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY project_invites_key (project_id, email),
  KEY project_invites_email_idx (email),
  CONSTRAINT pi_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT pi_inviter_fk FOREIGN KEY (invited_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) تقديم المرحلة للطرف الآخر ثم اعتمادها أو رفضها
--
-- الدورة: صاحب الدور يقدّم المرحلة (submitted_at) فتصبح awaiting_approval
-- وتنتقل الكرة للطرف الآخر، والطرف الآخر إما يعتمد ويقفل (locked_at) أو
-- يرفض بملاحظات (rejection_reason) فترجع active والكرة تعود لصاحبها.
-- ---------------------------------------------------------------------------
ALTER TABLE stages
  ADD COLUMN submitted_at     DATETIME NULL AFTER started_at,
  ADD COLUMN submitted_by     CHAR(36) NULL AFTER submitted_at,
  ADD COLUMN submission_note  TEXT     NULL AFTER submitted_by,
  ADD COLUMN rejection_reason TEXT     NULL AFTER submission_note,
  ADD COLUMN rejected_at      DATETIME NULL AFTER rejection_reason,
  ADD COLUMN rejected_by      CHAR(36) NULL AFTER rejected_at,
  ADD COLUMN rejection_count  INT      NOT NULL DEFAULT 0 AFTER rejected_by;

ALTER TABLE stages
  ADD CONSTRAINT stages_submitted_by_fk FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT stages_rejected_by_fk  FOREIGN KEY (rejected_by)  REFERENCES users(id) ON DELETE SET NULL;
