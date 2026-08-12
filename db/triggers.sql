-- ============================================================================
--  Arqam Flow Manager — حماية إضافية على مستوى قاعدة البيانات (اختياري)
--
--  كل القواعد هنا مطبَّقة أصلًا في api/lib/rules.php. هذه نسخة ثانية داخل
--  قاعدة البيانات نفسها، بحيث لو حصل خطأ برمجي في الـ API أو دخل أحد على
--  phpMyAdmin مباشرة، القواعد الحرجة تفضل محمية.
--
--  لو الاستضافة ما سمحتش بصلاحية TRIGGER، تجاهل هذا الملف — النظام هيشتغل عادي.
--
--  التشغيل:
--    من الترمنال:  mysql -u root -p arqam_flow < db/triggers.sql
--    من phpMyAdmin: الصق المحتوى وغيّر خانة Delimiter إلى $$
-- ============================================================================

DELIMITER $$

-- ---------------------------------------------------------------------------
-- سجل التدقيق: إضافة فقط
-- ملاحظة: حذف مشروع يحذف سجلاته عبر ON DELETE CASCADE، وقيود المفاتيح
-- الأجنبية في MySQL لا تُفعّل الـ triggers — فالحذف الشرعي يظل يعمل.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS audit_log_block_update$$
CREATE TRIGGER audit_log_block_update BEFORE UPDATE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'سجل التدقيق غير قابل للتعديل.'$$

DROP TRIGGER IF EXISTS audit_log_block_delete$$
CREATE TRIGGER audit_log_block_delete BEFORE DELETE ON audit_log FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'سجل التدقيق غير قابل للحذف.'$$

-- ---------------------------------------------------------------------------
-- المراحل: المرحلة المقفولة لا تُعدَّل ولا تُحذف، وضبط الحالة تلقائيًا عند القفل
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS stages_enforce_lock_update$$
CREATE TRIGGER stages_enforce_lock_update BEFORE UPDATE ON stages FOR EACH ROW
BEGIN
  IF OLD.locked_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'هذه المرحلة مقفولة. أي تعديل عليها يتطلب طلب تغيير.';
  END IF;
  IF NEW.locked_at IS NOT NULL THEN
    SET NEW.status = 'locked';
  END IF;
END$$

DROP TRIGGER IF EXISTS stages_enforce_lock_delete$$
CREATE TRIGGER stages_enforce_lock_delete BEFORE DELETE ON stages FOR EACH ROW
BEGIN
  IF OLD.locked_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'هذه المرحلة مقفولة ولا يمكن حذفها.';
  END IF;
END$$

-- ---------------------------------------------------------------------------
-- جولات الملاحظات: الجولة المُرسلة لا تُعاد للفتح
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS feedback_rounds_one_way$$
CREATE TRIGGER feedback_rounds_one_way BEFORE UPDATE ON feedback_rounds FOR EACH ROW
BEGIN
  IF OLD.status <> 'open' AND NEW.status = 'open' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'لا يمكن إعادة فتح جولة ملاحظات مُرسلة.';
  END IF;
END$$

-- ---------------------------------------------------------------------------
-- الملاحظات: لا تُضاف ملاحظة لجولة مقفولة
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS feedback_items_window$$
CREATE TRIGGER feedback_items_window BEFORE INSERT ON feedback_items FOR EACH ROW
BEGIN
  DECLARE r_status VARCHAR(32);
  SELECT status INTO r_status FROM feedback_rounds WHERE id = NEW.round_id;
  IF r_status IS NOT NULL AND r_status <> 'open' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'نافذة الملاحظات لهذه الجولة مقفولة.';
  END IF;
END$$

DELIMITER ;
