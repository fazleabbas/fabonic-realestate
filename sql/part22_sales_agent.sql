-- ============================================================
-- FIS Real Estate — Part 22: Sales Agent Integration
-- Migration: Adds RE-specific commission settings to central DB,
-- wallet columns for RE, and referral tracking enhancements.
-- Run against CENTRAL DB (fiscotz_propmanage).
-- ============================================================

-- -----------------------------------------------
-- 1. ALTER sales_agents — add RE wallet + referral code
-- -----------------------------------------------
ALTER TABLE `sales_agents`
    ADD COLUMN IF NOT EXISTS `referral_code` VARCHAR(20) DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `wallet_balance_re` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `referral_code`,
    ADD COLUMN IF NOT EXISTS `total_earned_re` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `wallet_balance_re`,
    ADD COLUMN IF NOT EXISTS `total_withdrawn_re` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_earned_re`;

-- Index on referral_code (ignore error if already exists)
ALTER TABLE `sales_agents`
    ADD UNIQUE INDEX `idx_referral_code` (`referral_code`);

-- -----------------------------------------------
-- 2. ALTER agent_referrals_re — add tracking columns
-- -----------------------------------------------
ALTER TABLE `agent_referrals_re`
    ADD COLUMN `subscription_month_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`,
    ADD COLUMN `last_commission_at` DATETIME DEFAULT NULL AFTER `subscription_month_count`,
    ADD COLUMN `customer_name` VARCHAR(150) DEFAULT NULL AFTER `trial_request_id`,
    ADD COLUMN `customer_phone` VARCHAR(20) DEFAULT NULL AFTER `customer_name`,
    ADD COLUMN `customer_company` VARCHAR(200) DEFAULT NULL AFTER `customer_phone`;

-- -----------------------------------------------
-- 3. ALTER agent_commissions_re — add wallet tracking
-- -----------------------------------------------
ALTER TABLE `agent_commissions_re`
    ADD COLUMN `base_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `commission_type`,
    ADD COLUMN `subscription_billing` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `base_amount`,
    ADD COLUMN `payment_reference` VARCHAR(100) DEFAULT NULL AFTER `paid_at`,
    ADD COLUMN `credited_at` DATETIME DEFAULT CURRENT_TIMESTAMP AFTER `payment_reference`;

-- -----------------------------------------------
-- 4. Create wallet transactions table for RE
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_wallet_transactions_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `type` ENUM('credit','debit') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reference_type` ENUM('commission','payout','bonus','adjustment') NOT NULL DEFAULT 'commission',
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_agent` (`agent_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_wallet_re_agent` FOREIGN KEY (`agent_id`) REFERENCES `sales_agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- 5. Create payouts table for RE
-- -----------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_payouts_re` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agent_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `payment_reference` VARCHAR(100) DEFAULT NULL,
    `phone_or_account` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    `processed_at` DATETIME DEFAULT NULL,
    `processed_by` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_agent` (`agent_id`),
    INDEX `idx_status` (`status`),
    CONSTRAINT `fk_payout_re_agent` FOREIGN KEY (`agent_id`) REFERENCES `sales_agents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------
-- 6. Insert RE-specific commission settings
-- -----------------------------------------------
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('re_commission_enabled', '1', 'Enable sales agent commissions for Real Estate'),
('re_commission_type', 'fixed', 'Commission type: fixed (TZS amount) or percentage (% of billing)'),
('re_commission_direct_m1', '8000', 'Direct commission for month 1 (TZS or %)'),
('re_commission_direct_m2', '5000', 'Direct commission for month 2'),
('re_commission_direct_m3', '2000', 'Direct commission for month 3'),
('re_commission_direct_recurring', '1000', 'Direct commission for month 4+ (recurring)'),
('re_commission_override_m1', '5000', 'Override/parent commission for month 1'),
('re_commission_override_m2', '3000', 'Override/parent commission for month 2'),
('re_commission_override_m3', '1000', 'Override/parent commission for month 3'),
('re_commission_override_recurring', '500', 'Override/parent commission for month 4+'),
('re_min_payout', '10000', 'Minimum payout threshold for RE commissions (TZS)'),
('re_max_commission_months', '12', 'Maximum months to pay commissions (0 = unlimited)')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
