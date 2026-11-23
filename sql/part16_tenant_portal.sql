-- ============================================================
-- FIS Real Estate — Part 16: Tenant Portal Migration
-- Adds portal_password_reset columns to tenants table
-- ============================================================

-- Add portal password reset columns if not exists
ALTER TABLE `tenants`
    ADD COLUMN IF NOT EXISTS `portal_reset_token` VARCHAR(100) DEFAULT NULL AFTER `portal_password_hash`,
    ADD COLUMN IF NOT EXISTS `portal_reset_expires` DATETIME DEFAULT NULL AFTER `portal_reset_token`,
    ADD COLUMN IF NOT EXISTS `portal_last_login` DATETIME DEFAULT NULL AFTER `portal_reset_expires`;

-- Add index on email for portal login lookup
ALTER TABLE `tenants`
    ADD INDEX IF NOT EXISTS `idx_email` (`email`);

-- Ensure tenant_portal_enabled setting exists
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`)
VALUES ('tenant_portal_enabled', '0', 'modules', 'Enable tenant self-service portal')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
