-- ============================================================
-- FIS Real Estate — Part 23: SaaS Admin Panel
-- Migration: Adds system_announcements table and admin settings
-- to central DB (fiscotz_propmanage).
-- Run against CENTRAL DB (fiscotz_propmanage).
-- ============================================================

-- -----------------------------------------------
-- 1. Create system_announcements table
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `system_announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
    `target` ENUM('all','tier_basic','tier_professional','tier_enterprise') NOT NULL DEFAULT 'all',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `created_by` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`),
    INDEX `idx_target` (`target`),
    INDEX `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB;

-- -----------------------------------------------
-- 2. ALTER subscriptions — add admin notes + suspended metadata
-- -----------------------------------------------
ALTER TABLE `subscriptions`
    ADD COLUMN IF NOT EXISTS `admin_notes` TEXT DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `suspended_at` DATETIME DEFAULT NULL AFTER `admin_notes`,
    ADD COLUMN IF NOT EXISTS `suspended_reason` VARCHAR(255) DEFAULT NULL AFTER `suspended_at`;

-- -----------------------------------------------
-- 3. ALTER subscription_payments — add admin metadata
-- -----------------------------------------------
ALTER TABLE `subscription_payments`
    ADD COLUMN IF NOT EXISTS `verified_at` DATETIME DEFAULT NULL AFTER `confirmed_at`,
    ADD COLUMN IF NOT EXISTS `verification_notes` TEXT DEFAULT NULL AFTER `verified_at`;

-- -----------------------------------------------
-- 4. Insert SaaS admin settings
-- -----------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('maintenance_mode', '0', 'Enable maintenance mode for all tenants'),
('maintenance_message', 'System is under maintenance. Please try again later.', 'Message shown during maintenance mode'),
('feature_new_registrations', '1', 'Allow new trial registrations'),
('feature_payment_gateway', '1', 'Enable payment gateway features globally'),
('feature_whatsapp_api', '1', 'Enable WhatsApp API features globally'),
('admin_email', '', 'Admin notification email address'),
('admin_whatsapp', '', 'Admin notification WhatsApp number'),
('pricing_basic_rate', '1000', 'Basic tier rate per unit per month (TZS)'),
('pricing_professional_rate', '14999', 'Professional tier rate per unit per month (TZS)'),
('pricing_enterprise_rate', '19999', 'Enterprise tier rate per unit per month (TZS)'),
('pricing_trial_days', '14', 'Number of trial days for new subscribers'),
('system_version', '1.0.0', 'Current system version')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
