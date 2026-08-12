-- ============================================================================
--  ترقية: طلبات التغيير من العميل
--
--  العميل يسجّل طلب تعديل بدون سعر، فيصل لفريق أرقام كمسودة تحتاج تسعيرًا
--  ثم تُرسل له للاعتماد. العمود ده يميّز طلب العميل عن مسودة فريق أرقام.
--
--  التشغيل:  mysql -u root -p arqam_flow < db/migrations/006_client_change_requests.sql
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE change_requests
  ADD COLUMN requested_by CHAR(36) NULL AFTER project_id,
  ADD CONSTRAINT cr_requester_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL;
