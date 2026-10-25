-- ============================================================
-- FIS Real Estate — Part 17: Automated Reminders & WhatsApp Receipts
-- Run against tenant DB after Part 16
-- ============================================================

-- Add repeat_interval_days to reminder_templates (for recurring overdue reminders)
ALTER TABLE `reminder_templates`
    ADD COLUMN IF NOT EXISTS `repeat_interval_days` INT UNSIGNED DEFAULT 0 COMMENT '0=no repeat, N=repeat every N days for overdue' AFTER `days_offset`;

-- Add message_body and recipient_phone to reminder_schedule for delivery tracking
ALTER TABLE `reminder_schedule`
    ADD COLUMN IF NOT EXISTS `message_body` TEXT DEFAULT NULL COMMENT 'Resolved message text that was sent' AFTER `channel`,
    ADD COLUMN IF NOT EXISTS `recipient_phone` VARCHAR(20) DEFAULT NULL AFTER `message_body`,
    ADD COLUMN IF NOT EXISTS `recipient_email` VARCHAR(150) DEFAULT NULL AFTER `recipient_phone`,
    ADD COLUMN IF NOT EXISTS `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `error_message`,
    ADD COLUMN IF NOT EXISTS `period_reference` VARCHAR(20) DEFAULT NULL COMMENT 'e.g. 2026-03' AFTER `scheduled_date`;

-- Receipt messages table — tracks WhatsApp receipt auto-sends
CREATE TABLE IF NOT EXISTS `receipt_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NOT NULL,
    `receipt_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('whatsapp','sms','email') NOT NULL DEFAULT 'whatsapp',
    `recipient_phone` VARCHAR(20) DEFAULT NULL,
    `recipient_email` VARCHAR(150) DEFAULT NULL,
    `message_body` TEXT DEFAULT NULL,
    `status` ENUM('pending','sent','delivered','failed') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payment` (`payment_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_rcptmsg_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rcptmsg_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Default reminder templates.
-- Inserted one at a time, guarded by NOT EXISTS on the name. `reminder_templates`
-- has NO unique index on `name`, so an ON DUPLICATE KEY clause would never fire
-- and every re-run would add another copy of all four.
INSERT INTO `reminder_templates` (`name`, `trigger_type`, `days_offset`, `repeat_interval_days`, `channel`, `message_template`, `is_active`)
SELECT '3-Day Reminder', 'before_due', 3, 0, 'whatsapp',
 'Hello {tenant_name}, this is a friendly reminder that your rent of {currency} {amount} for unit {unit_number} at {property_name} is due on {due_date}. Please ensure timely payment. Thank you! - {company_name}', 1
WHERE NOT EXISTS (SELECT 1 FROM `reminder_templates` WHERE `name` = '3-Day Reminder');

INSERT INTO `reminder_templates` (`name`, `trigger_type`, `days_offset`, `repeat_interval_days`, `channel`, `message_template`, `is_active`)
SELECT '1-Day Reminder', 'before_due', 1, 0, 'whatsapp',
 'Hi {tenant_name}, your rent of {currency} {amount} for {unit_number} is due tomorrow ({due_date}). Please make your payment to avoid late fees. - {company_name}', 1
WHERE NOT EXISTS (SELECT 1 FROM `reminder_templates` WHERE `name` = '1-Day Reminder');

INSERT INTO `reminder_templates` (`name`, `trigger_type`, `days_offset`, `repeat_interval_days`, `channel`, `message_template`, `is_active`)
SELECT 'Due Date Notice', 'on_due', 0, 0, 'whatsapp',
 'Dear {tenant_name}, your rent of {currency} {amount} for unit {unit_number} at {property_name} is due today. Please complete your payment. Thank you! - {company_name}', 1
WHERE NOT EXISTS (SELECT 1 FROM `reminder_templates` WHERE `name` = 'Due Date Notice');

INSERT INTO `reminder_templates` (`name`, `trigger_type`, `days_offset`, `repeat_interval_days`, `channel`, `message_template`, `is_active`)
SELECT 'Overdue Notice', 'overdue', 3, 7, 'whatsapp',
 'Dear {tenant_name}, your rent of {currency} {amount} for unit {unit_number} at {property_name} is now overdue. Your outstanding balance is {currency} {balance}. Please make payment immediately to avoid additional late fees. - {company_name}', 1
WHERE NOT EXISTS (SELECT 1 FROM `reminder_templates` WHERE `name` = 'Overdue Notice');

-- Settings for reminders
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('receipt_auto_send', '1', 'notifications', 'Auto-send receipt via WhatsApp after payment recording'),
('reminder_wa_enabled', '1', 'notifications', 'Enable WhatsApp for reminders'),
('reminder_sms_enabled', '0', 'notifications', 'Enable SMS for reminders'),
('reminder_email_enabled', '1', 'notifications', 'Enable email for reminders'),
('cron_key', '', 'general', 'Secret key for cron job HTTP access')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
