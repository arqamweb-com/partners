-- ============================================================================
--  Arqam Flow Manager — MySQL schema
--  محوّل من Postgres/Supabase. يعمل على MySQL 5.7+ / 8.x و MariaDB 10.3+
--
--  ملاحظات التحويل:
--    uuid            -> CHAR(36)   (تتولّد من PHP عبر uuid4())
--    timestamptz     -> DATETIME   (كل الأوقات تُخزَّن UTC)
--    jsonb           -> JSON       (بدون DEFAULT — MySQL لا يسمح، القيمة تُضبط من PHP)
--    CREATE TYPE ... -> ENUM(...) داخل تعريف العمود
--    auth.users      -> جدول users المحلي (تسجيل الدخول عبر PHP)
--    RLS policies    -> فحص صلاحيات داخل api/lib/auth.php (غير موجودة هنا إطلاقًا)
--    plpgsql triggers-> api/lib/rules.php (ما عدا updated_at وسجل التدقيق أدناه)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS uploads;
DROP TABLE IF EXISTS cr_price_items;
DROP TABLE IF EXISTS app_settings;
DROP TABLE IF EXISTS change_requests;
DROP TABLE IF EXISTS feedback_items;
DROP TABLE IF EXISTS feedback_rounds;
DROP TABLE IF EXISTS content_items;
DROP TABLE IF EXISTS access_items;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS gate_approvals;
DROP TABLE IF EXISTS stages;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS project_invites;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS profiles;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- المستخدمون  (بديل auth.users في Supabase)
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY users_email_key (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الملفات الشخصية
-- ---------------------------------------------------------------------------
CREATE TABLE profiles (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  full_name   VARCHAR(255) NOT NULL DEFAULT '',
  email       VARCHAR(255) NOT NULL DEFAULT '',
  agency_name VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT profiles_id_fk FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الأدوار — التسجيل من الموقع ينشئ 'client' دائمًا،
-- وحسابات 'admin' تُنشأ من التيرمينال عبر api/bin/admin.php
-- ---------------------------------------------------------------------------
CREATE TABLE user_roles (
  id      CHAR(36)                 NOT NULL PRIMARY KEY,
  user_id CHAR(36)                 NOT NULL,
  role    ENUM('admin','client')   NOT NULL,
  UNIQUE KEY user_roles_user_role_key (user_id, role),
  CONSTRAINT user_roles_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الأجازات الرسمية — تُستخدم في حساب أيام العمل
-- ---------------------------------------------------------------------------
CREATE TABLE holidays (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  holiday_date DATE         NOT NULL,
  label        VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY holidays_date_key (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- المشاريع
-- ---------------------------------------------------------------------------
CREATE TABLE projects (
  id                      CHAR(36)     NOT NULL PRIMARY KEY,
  name                    VARCHAR(255) NOT NULL,
  end_client_name         VARCHAR(255) NOT NULL DEFAULT '',
  partner_agency          VARCHAR(255) NOT NULL DEFAULT '',
  -- نوع المشروع يحدد قالب المراحل والقوائم. VARCHAR وليس ENUM حتى تكون
  -- إضافة نوع جديد تعديل كود فقط — القيم المسموحة في api/lib/rules.php
  project_type            VARCHAR(32)  NOT NULL DEFAULT 'brochure',
  -- إجابات حقول النوع (عدد المنتجات، بوابة الدفع…) — الحقول معرّفة في
  -- src/lib/project-types.ts فلا تحتاج ترقية عند إضافة حقل
  type_details            JSON         NULL,
  -- بيانات ومرفقات يسجّلها العميل وقت الطلب (لوجو، روابط درايف، تواصل، سوشيال)
  intake_data             JSON         NULL,
  owner_id                CHAR(36)     NULL,
  owner_name              VARCHAR(255) NOT NULL DEFAULT '',
  track                   ENUM('normal','fast_track') NOT NULL DEFAULT 'normal',
  -- draft = طلب أرسله العميل وينتظر مراجعة فريق أرقام واعتماده
  status                  ENUM('active','awaiting_client','frozen','completed','stopped','draft')
                                       NOT NULL DEFAULT 'active',
  -- التواريخ تقبل NULL: طلب العميل يحمل الأساسيات فقط، وتاريخ التسليم بند
  -- تعاقدي يكتبه فريق أرقام عند مراجعة الطلب
  original_delivery_date  DATE         NULL,
  client_delay_days       INT          NOT NULL DEFAULT 0,
  -- تُحسب تلقائيًا من original_delivery_date + client_delay_days بأيام العمل
  -- (كان trigger اسمه sync_adjusted_delivery — الآن في api/lib/rules.php)
  adjusted_delivery_date  DATE         NULL,
  warranty_days           INT          NOT NULL DEFAULT 14,
  revision_rounds_allowed INT          NOT NULL DEFAULT 2,
  revision_rounds_used    INT          NOT NULL DEFAULT 0,
  scope                   TEXT         NULL,
  out_of_scope            TEXT         NULL,
  notes                   TEXT         NULL,
  supported_devices       TEXT         NULL,
  supported_browsers      TEXT         NULL,
  supported_screens       TEXT         NULL,
  payment_milestones      JSON         NULL,
  queue_slot_date         DATE         NULL,
  reactivation_fee        DECIMAL(12,2) NOT NULL DEFAULT 0,
  reactivated_at          DATETIME     NULL,
  credit_amount           DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit_expires_at       DATE         NULL,
  frozen_at               DATETIME     NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
  KEY projects_status_idx (status),
  KEY projects_owner_idx (owner_id),
  KEY projects_type_idx (project_type),
  CONSTRAINT projects_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- أعضاء المشروع — أساس فحص الصلاحيات (بديل is_project_member)
-- ---------------------------------------------------------------------------
CREATE TABLE project_members (
  id         CHAR(36) NOT NULL PRIMARY KEY,
  project_id CHAR(36) NOT NULL,
  user_id    CHAR(36) NOT NULL,
  UNIQUE KEY project_members_key (project_id, user_id),
  KEY project_members_user_idx (user_id),
  CONSTRAINT pm_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT pm_user_fk    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- المراحل — القفل هنا نهائي في اتجاه واحد (المنطق في api/lib/rules.php)
-- ---------------------------------------------------------------------------
CREATE TABLE stages (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  project_id          CHAR(36)     NOT NULL,
  stage_index         INT          NOT NULL,
  is_parallel         TINYINT(1)   NOT NULL DEFAULT 0,
  name                VARCHAR(255) NOT NULL,
  gate_name           VARCHAR(255) NULL,
  gate_size           VARCHAR(32)  NOT NULL DEFAULT 'small',
  our_duration_days   INT          NOT NULL DEFAULT 0,
  their_duration_days INT          NOT NULL DEFAULT 0,
  ball_in_court       ENUM('us','them') NOT NULL DEFAULT 'us',
  status              ENUM('pending','active','awaiting_approval','locked','frozen')
                                   NOT NULL DEFAULT 'pending',
  started_at          DATETIME     NULL,
  -- دورة الاعتماد في الاتجاهين: صاحب الدور يقدّم، والطرف الآخر يعتمد أو يرفض
  submitted_at        DATETIME     NULL,
  submitted_by        CHAR(36)     NULL,
  submission_note     TEXT         NULL,
  rejection_reason    TEXT         NULL,
  rejected_at         DATETIME     NULL,
  rejected_by         CHAR(36)     NULL,
  rejection_count     INT          NOT NULL DEFAULT 0,
  due_at              DATETIME     NULL,
  locked_at           DATETIME     NULL,
  locked_by           CHAR(36)     NULL,
  deliverables        JSON         NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY stages_project_index_key (project_id, stage_index),
  CONSTRAINT stages_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT stages_locked_by_fk FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT stages_submitted_by_fk FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT stages_rejected_by_fk  FOREIGN KEY (rejected_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- دعوات المشاريع — ربط العميل بمشروعه عن طريق بريده الإلكتروني
-- ---------------------------------------------------------------------------
CREATE TABLE project_invites (
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
-- اعتمادات البوابات — إضافة فقط، لا تعديل ولا حذف
-- ---------------------------------------------------------------------------
CREATE TABLE gate_approvals (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  project_id          CHAR(36)     NOT NULL,
  stage_id            CHAR(36)     NOT NULL,
  approved_by         CHAR(36)     NULL,
  approver_name       VARCHAR(255) NOT NULL,
  acknowledgement_text TEXT        NOT NULL,
  is_silent           TINYINT(1)   NOT NULL DEFAULT 0,
  approved_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY gate_approvals_project_idx (project_id),
  KEY gate_approvals_stage_idx (stage_id),
  CONSTRAINT ga_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT ga_stage_fk   FOREIGN KEY (stage_id)   REFERENCES stages(id)   ON DELETE CASCADE,
  CONSTRAINT ga_user_fk    FOREIGN KEY (approved_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- سجل التدقيق — إضافة فقط (يُحمى بـ trigger في db/triggers.sql)
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  project_id  CHAR(36)     NOT NULL,
  actor_id    CHAR(36)     NULL,
  actor_name  VARCHAR(255) NOT NULL DEFAULT '',
  event_type  VARCHAR(64)  NOT NULL,
  description TEXT         NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY audit_log_project_idx (project_id, created_at),
  CONSTRAINT audit_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT audit_actor_fk   FOREIGN KEY (actor_id)   REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- قائمة الوصول والحسابات
-- ---------------------------------------------------------------------------
CREATE TABLE access_items (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  project_id  CHAR(36)     NOT NULL,
  item_order  INT          NOT NULL DEFAULT 0,
  name        VARCHAR(255) NOT NULL,
  note        TEXT         NULL,
  is_slow     TINYINT(1)   NOT NULL DEFAULT 0,
  is_done     TINYINT(1)   NOT NULL DEFAULT 0,
  provided_by CHAR(36)     NULL,
  provided_at DATETIME     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY access_items_project_idx (project_id, item_order),
  CONSTRAINT access_project_fk  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT access_provider_fk FOREIGN KEY (provided_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- قائمة المحتوى والأصول
-- ---------------------------------------------------------------------------
CREATE TABLE content_items (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  project_id          CHAR(36)     NOT NULL,
  item_group          ENUM('blocking','non_blocking') NOT NULL,
  item_order          INT          NOT NULL DEFAULT 0,
  name                VARCHAR(255) NOT NULL,
  acceptance_criteria TEXT         NULL,
  status              ENUM('pending','submitted','accepted','rejected') NOT NULL DEFAULT 'pending',
  value               TEXT         NULL,
  due_at              DATETIME     NULL,
  submitted_at        DATETIME     NULL,
  submitted_by        CHAR(36)     NULL,
  reviewed_at         DATETIME     NULL,
  reviewed_by         CHAR(36)     NULL,
  rejection_reason    TEXT         NULL,
  auto_accepted       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY content_items_project_idx (project_id, item_group, item_order),
  CONSTRAINT content_project_fk  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT content_submitter_fk FOREIGN KEY (submitted_by) REFERENCES users(id)  ON DELETE SET NULL,
  CONSTRAINT content_reviewer_fk  FOREIGN KEY (reviewed_by)  REFERENCES users(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- جولات الملاحظات — الحالة تتحرك في اتجاه واحد ولا تُعاد للفتح
-- ---------------------------------------------------------------------------
CREATE TABLE feedback_rounds (
  id           CHAR(36)   NOT NULL PRIMARY KEY,
  project_id   CHAR(36)   NOT NULL,
  stage_id     CHAR(36)   NULL,
  round_number INT        NOT NULL DEFAULT 1,
  status       ENUM('open','submitted','classified','closed') NOT NULL DEFAULT 'open',
  is_paid      TINYINT(1) NOT NULL DEFAULT 0,
  opened_at    DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME   NULL,
  closed_at    DATETIME   NULL,
  created_at   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY feedback_rounds_project_idx (project_id, round_number),
  CONSTRAINT fr_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fr_stage_fk   FOREIGN KEY (stage_id)   REFERENCES stages(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedback_items (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  round_id        CHAR(36)     NOT NULL,
  project_id      CHAR(36)     NOT NULL,
  description     TEXT         NOT NULL,
  page_or_section VARCHAR(255) NOT NULL DEFAULT '',
  classification  ENUM('defect','enhancement','new_scope') NULL,
  classified_at   DATETIME     NULL,
  classified_by   CHAR(36)     NULL,
  objection_text  TEXT         NULL,
  objection_at    DATETIME     NULL,
  resolution      ENUM('fixed','converted_to_cr','goodwill_fix') NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY feedback_items_round_idx (round_id),
  KEY feedback_items_project_idx (project_id),
  CONSTRAINT fi_round_fk    FOREIGN KEY (round_id)      REFERENCES feedback_rounds(id) ON DELETE CASCADE,
  CONSTRAINT fi_project_fk  FOREIGN KEY (project_id)    REFERENCES projects(id)        ON DELETE CASCADE,
  CONSTRAINT fi_classifier_fk FOREIGN KEY (classified_by) REFERENCES users(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- طلبات التغيير — يُعاد تقديم الطلب مرة واحدة فقط (المنطق في rules.php)
-- ---------------------------------------------------------------------------
CREATE TABLE change_requests (
  id                      CHAR(36)      NOT NULL PRIMARY KEY,
  project_id              CHAR(36)      NOT NULL,
  -- غير فارغ = طلب سجّله العميل ويحتاج تسعيرًا من فريق أرقام
  requested_by            CHAR(36)      NULL,
  source_feedback_item_id CHAR(36)      NULL,
  title                   VARCHAR(255)  NOT NULL,
  description             TEXT          NULL,
  price                   DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency                VARCHAR(8)    NOT NULL DEFAULT 'SAR',
  duration_days           INT           NOT NULL DEFAULT 0,
  delivery_impact_days    INT           NOT NULL DEFAULT 0,
  status                  ENUM('draft','sent','approved','rejected','expired','withdrawn')
                                        NOT NULL DEFAULT 'draft',
  sent_at                 DATETIME      NULL,
  quote_valid_until       DATE          NULL,
  decision_deadline       DATE          NULL,
  decided_at              DATETIME      NULL,
  decided_by              CHAR(36)      NULL,
  decision_note           TEXT          NULL,
  resubmitted_from        CHAR(36)      NULL,
  created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY change_requests_project_idx (project_id, status),
  KEY change_requests_resubmit_idx (resubmitted_from),
  CONSTRAINT cr_project_fk  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT cr_source_fk   FOREIGN KEY (source_feedback_item_id) REFERENCES feedback_items(id) ON DELETE SET NULL,
  CONSTRAINT cr_decider_fk   FOREIGN KEY (decided_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT cr_requester_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT cr_resubmit_fk FOREIGN KEY (resubmitted_from) REFERENCES change_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- إعدادات النظام — صف واحد فقط (id = 1)
-- ---------------------------------------------------------------------------
CREATE TABLE app_settings (
  id                      TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  warning_threshold_days  INT           NOT NULL DEFAULT 5,
  freeze_threshold_days   INT           NOT NULL DEFAULT 10,
  reactivation_fee        DECIMAL(12,2) NOT NULL DEFAULT 1500,
  warranty_days           INT           NOT NULL DEFAULT 14,
  revision_rounds_allowed INT           NOT NULL DEFAULT 2,
  stage_defaults          JSON          NULL,
  updated_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- بنود التسعير الجاهزة لطلبات التغيير
-- ---------------------------------------------------------------------------
CREATE TABLE cr_price_items (
  id            CHAR(36)      NOT NULL PRIMARY KEY,
  name          VARCHAR(255)  NOT NULL,
  price         DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency      VARCHAR(8)    NOT NULL DEFAULT 'SAR',
  duration_days INT           NOT NULL DEFAULT 0,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- الملفات المرفوعة — البيانات هنا والملف نفسه في api/storage/uploads
-- (خارج متناول المتصفح، ولا يُقدَّم إلا عبر /api/files/{id})
-- ---------------------------------------------------------------------------
CREATE TABLE uploads (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  -- يبقى NULL أثناء ملء النموذج ويُضبط عند حفظ الطلب عبر /api/files/claim
  project_id    CHAR(36)     NULL,
  user_id       CHAR(36)     NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(255) NOT NULL,
  mime          VARCHAR(127) NOT NULL DEFAULT '',
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY uploads_project_idx (project_id),
  KEY uploads_user_idx (user_id),
  CONSTRAINT uploads_project_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT uploads_user_fk    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
