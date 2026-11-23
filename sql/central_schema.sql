-- ============================================================
-- Fabonic Real Estate — Central Database Schema
-- Import this INTO an already-created & selected database
-- (e.g. realcotz_propmanage) via phpMyAdmin → Import.
-- No CREATE DATABASE / USE here on purpose, so it works with
-- any cPanel database name/prefix.
-- ============================================================

-- ============================================================
-- Trial Requests
-- ============================================================
CREATE TABLE IF NOT EXISTS `trial_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `company_name` VARCHAR(200) DEFAULT NULL,
    `property_count` INT UNSIGNED DEFAULT 1,
    `unit_count` INT UNSIGNED DEFAULT 1,
    `city` VARCHAR(100) DEFAULT NULL,
    `referral_source` VARCHAR(100) DEFAULT NULL COMMENT 'How they heard about us',
    `sales_agent_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    `trial_start_date` DATE DEFAULT NULL,
    `trial_end_date` DATE DEFAULT NULL,
    `tenant_slug` VARCHAR(50) DEFAULT NULL COMMENT 'Assigned on approval',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_phone` (`phone`),
    INDEX `idx_agent` (`sales_agent_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Subscriptions (one per tenant instance)
-- ============================================================
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tenant_slug` VARCHAR(50) NOT NULL,
    `tenant_db_name` VARCHAR(100) NOT NULL,
    `company_name` VARCHAR(200) NOT NULL,
    `owner_name` VARCHAR(150) NOT NULL,
    `owner_phone` VARCHAR(20) NOT NULL,
    `owner_email` VARCHAR(150) DEFAULT NULL,
    `tier` ENUM('basic','professional','enterprise') NOT NULL DEFAULT 'basic',
    `unit_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `rate_per_unit` DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `discount_type` ENUM('none','percentage','fixed') NOT NULL DEFAULT 'none',
    `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'unit_count * rate_per_unit',
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'gross - discount',
    `billing_cycle` ENUM('monthly') NOT NULL DEFAULT 'monthly',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `next_billing_date` DATE DEFAULT NULL,
    `status` ENUM('trial','active','suspended','cancelled','expired') NOT NULL DEFAULT 'trial',
    `trial_request_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_tenant_slug` (`tenant_slug`),
    INDEX `idx_status` (`status`),
    INDEX `idx_tier` (`tier`),
    INDEX `idx_end_date` (`end_date`),
    CONSTRAINT `fk_sub_trial` FOREIGN KEY (`trial_request_id`) REFERENCES `trial_requests`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Subscription Payments
-- ============================================================
CREATE TABLE IF NOT EXISTS `subscription_payments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `subscription_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `payment_date` DATE NOT NULL,
    `payment_method` ENUM('airtel_money','bank','cash','gateway') NOT NULL DEFAULT 'airtel_money',
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `period_from` DATE DEFAULT NULL,
    `period_to` DATE DEFAULT NULL,
    `status` ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    `confirmed_by` VARCHAR(100) DEFAULT NULL,
    `confirmed_at` DATETIME DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_subscription` (`subscription_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_payment_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Sales Agents (shared table with product_type support)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sales_agents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `parent_agent_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('active','inactive','pending') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_phone` (`phone`),
    INDEX `idx_parent` (`parent_agent_id`),
    CONSTRAINT `fk_agent_parent` FOREIGN KEY (`parent_agent_id`) REFERENCES `sales_agents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Agent Referrals (Real Estate specific)
-- ============================================================
CREATE TABLE IF NOT EXISTS `agent_referrals_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `trial_request_id` INT UNSIGNED NOT NULL,
    `subscription_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('referred','trial','converted','churned') NOT NULL DEFAULT 'referred',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_agent` (`agent_id`),
    CONSTRAINT `fk_ref_agent` FOREIGN KEY (`agent_id`) REFERENCES `sales_agents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_trial` FOREIGN KEY (`trial_request_id`) REFERENCES `trial_requests`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- Agent Commissions (Real Estate specific)
-- ============================================================
CREATE TABLE IF NOT EXISTS `agent_commissions_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `referral_id` INT UNSIGNED NOT NULL,
    `subscription_id` INT UNSIGNED NOT NULL,
    `commission_type` ENUM('direct','override') NOT NULL DEFAULT 'direct',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `month_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    `paid_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_agent` (`agent_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_comm_agent` FOREIGN KEY (`agent_id`) REFERENCES `sales_agents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comm_ref` FOREIGN KEY (`referral_id`) REFERENCES `agent_referrals_re`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comm_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Drip Campaigns (Real Estate)
-- ============================================================
CREATE TABLE IF NOT EXISTS `drip_campaigns_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `trial_request_id` INT UNSIGNED NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `status` ENUM('active','completed','stopped') NOT NULL DEFAULT 'active',
    `current_step` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_send_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_next_send` (`next_send_at`),
    CONSTRAINT `fk_drip_trial` FOREIGN KEY (`trial_request_id`) REFERENCES `trial_requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Drip Messages
-- ============================================================
CREATE TABLE IF NOT EXISTS `drip_messages_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT UNSIGNED NOT NULL,
    `step_number` INT UNSIGNED NOT NULL,
    `message_template` TEXT NOT NULL,
    `send_after_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('pending','sent','delivered','failed') NOT NULL DEFAULT 'pending',
    `sent_at` DATETIME DEFAULT NULL,
    `delivery_status` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_campaign` (`campaign_id`),
    CONSTRAINT `fk_msg_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `drip_campaigns_re`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- System Settings (central, key-value)
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `setting_value` TEXT DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('default_currency', 'TZS', 'Default currency code'),
('trial_days', '14', 'Number of trial days'),
('trial_tier', 'professional', 'Tier given during trial period'),
('tier_basic_rate', '1000', 'Basic tier rate per unit per month (TZS)'),
('tier_professional_rate', '14999', 'Professional tier rate per unit per month (TZS)'),
('tier_enterprise_rate', '19999', 'Enterprise tier rate per unit per month (TZS)'),
('commission_direct_rate', '0', 'Direct agent commission (JSON or flat amount)'),
('commission_override_rate', '0', 'Override commission for parent agent'),
('maintenance_mode', '0', 'Enable maintenance mode (1=on, 0=off)'),
('whatsapp_api_enabled', '1', 'Enable WhatsApp drip campaigns'),
('admin_email', 'admin@fis.co.tz', 'Admin notification email'),
('admin_phone', '', 'Admin notification phone')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ============================================================
-- Admin Users (for central SaaS admin panel)
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('super_admin','admin','support') NOT NULL DEFAULT 'admin',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_username` (`username`)
) ENGINE=InnoDB;

-- Default admin user (password: FIS@2026#Admin)
INSERT INTO `admin_users` (`username`, `full_name`, `email`, `password_hash`, `role`) VALUES
('admin', 'System Administrator', 'admin@fis.co.tz',
 '$2y$10$h0EoqrJ2cYVI3A.yHSF/yuNcjy/X4Fot3rdfLRjraxw5HfOehOy/y', 'super_admin')
ON DUPLICATE KEY UPDATE `username` = `username`;
