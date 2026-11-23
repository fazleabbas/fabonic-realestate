-- ============================================================
-- Fabonic Real Estate — Part 25: SMS Gateway + Overdue Nag Cap
-- Run against each tenant DB (safe to re-run).
--
-- Adds the settings used by includes/sms.php (Sprint Marketing & Technology
-- SMS API v3.0) and the cap on repeated overdue reminders.
--
-- Apply to every tenant with:
--   php api/apply-tenant-sql.php --file=sql/part25_sms_gateway.sql
-- ============================================================

SET NAMES utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('sms_api_url', '', 'notifications', 'SMS gateway base URL, e.g. https://sms.example.com'),
('sms_api_id', '', 'notifications', 'SMS API ID'),
('sms_api_password', '', 'notifications', 'SMS API password'),
('sms_sender_id', '', 'notifications', 'Registered SMS sender ID'),
('sms_type', 'T', 'notifications', 'T = transactional, P = promotional'),
('sms_country_code', '255', 'notifications', 'Country code prefixed to local numbers'),
('sms_callback_url', '', 'notifications', 'Optional delivery-report callback URL'),
('reminder_max_overdue_nags', '4', 'notifications', 'Max repeated overdue reminders per period')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
