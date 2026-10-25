-- ============================================================
-- FIS Real Estate — Part 19: Enterprise Tier — Maintenance Management
-- Enterprise tier gating for maintenance module.
-- Adds notification triggers, cost summary view, CSV export support.
-- Core maintenance tables already exist in tenant_schema.sql + part12.
-- ============================================================

-- Maintenance notifications tracking (who was notified on status changes)
CREATE TABLE IF NOT EXISTS `maintenance_notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT UNSIGNED NOT NULL,
    `recipient_type` ENUM('staff','tenant','contractor') NOT NULL DEFAULT 'staff',
    `recipient_id` INT UNSIGNED DEFAULT NULL,
    `recipient_name` VARCHAR(100) DEFAULT NULL,
    `channel` ENUM('system','whatsapp','email') NOT NULL DEFAULT 'system',
    `event_type` VARCHAR(50) NOT NULL COMMENT 'status_change, assignment, completion, etc.',
    `message` TEXT DEFAULT NULL,
    `status` ENUM('sent','pending','failed') NOT NULL DEFAULT 'sent',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_request` (`request_id`),
    INDEX `idx_recipient` (`recipient_type`, `recipient_id`),
    CONSTRAINT `fk_maintnoti_request` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Cost summary columns on maintenance_requests (for faster reporting)
ALTER TABLE `maintenance_requests`
    ADD COLUMN IF NOT EXISTS `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `completion_notes`,
    ADD COLUMN IF NOT EXISTS `total_hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `total_cost`;

-- New settings for enterprise maintenance features
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('maintenance_notify_on_status', '1', 'notifications', 'Notify stakeholders on maintenance status changes'),
('maintenance_notify_on_assign', '1', 'notifications', 'Notify contractor when assigned to maintenance request'),
('maintenance_auto_close_days', '7', 'modules', 'Auto-close completed requests after X days (0 = disabled)')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
