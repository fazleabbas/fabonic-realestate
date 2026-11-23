-- ============================================================
-- FIS Real Estate — Part 21: Payment Gateway Integration
-- Migration: Adds columns to gateway_config + gateway_transactions,
-- inserts default gateway rows and settings.
-- Run against tenant DB AFTER tenant_schema.sql is already applied.
-- ============================================================

-- -----------------------------------------------
-- 1. ALTER gateway_config — add extra fields
-- -----------------------------------------------
ALTER TABLE `gateway_config`
    ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `gateway_name`,
    ADD COLUMN `description` TEXT DEFAULT NULL AFTER `display_name`,
    ADD COLUMN `config_json` JSON DEFAULT NULL COMMENT 'Extra gateway-specific config (shortcode, business_number, etc.)' AFTER `callback_url`,
    ADD COLUMN `logo_icon` VARCHAR(50) DEFAULT NULL AFTER `config_json`;

-- -----------------------------------------------
-- 2. ALTER gateway_transactions — add linking columns
-- -----------------------------------------------
ALTER TABLE `gateway_transactions`
    ADD COLUMN `tenant_id` INT UNSIGNED DEFAULT NULL AFTER `payment_id`,
    ADD COLUMN `lease_id` INT UNSIGNED DEFAULT NULL AFTER `tenant_id`,
    ADD COLUMN `unit_id` INT UNSIGNED DEFAULT NULL AFTER `lease_id`,
    ADD COLUMN `error_message` TEXT DEFAULT NULL AFTER `callback_data`,
    ADD COLUMN `reconciled_by` INT UNSIGNED DEFAULT NULL AFTER `reconciled_at`,
    ADD COLUMN `description` VARCHAR(255) DEFAULT NULL AFTER `reconciled_by`,
    ADD INDEX `idx_tenant` (`tenant_id`),
    ADD INDEX `idx_gateway_name` (`gateway_name`),
    ADD INDEX `idx_transaction_id` (`transaction_id`),
    ADD INDEX `idx_reconciled` (`reconciled`);

-- -----------------------------------------------
-- 3. Insert default gateway configurations
-- -----------------------------------------------
INSERT INTO `gateway_config` (`gateway_name`, `display_name`, `description`, `environment`, `is_active`, `logo_icon`) VALUES
('selcom', 'Selcom', 'Selcom payment gateway — supports M-Pesa, Airtel Money, Tigo Pesa, Halotel', 'sandbox', 0, 'bi-phone'),
('mpesa', 'Vodacom M-Pesa', 'Vodacom M-Pesa C2B collection (direct integration)', 'sandbox', 0, 'bi-phone-vibrate'),
('airtel_money', 'Airtel Money', 'Airtel Money collection API', 'sandbox', 0, 'bi-telephone-outbound'),
('tigo_pesa', 'Tigo Pesa', 'Tigo Pesa / MiX by Yas collection', 'sandbox', 0, 'bi-telephone-forward')
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`), `description` = VALUES(`description`), `logo_icon` = VALUES(`logo_icon`);

-- -----------------------------------------------
-- 4. Insert gateway-related settings
-- -----------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('gateway_callback_secret', '', 'payments', 'Secret key for validating gateway callbacks'),
('gateway_auto_reconcile', '1', 'payments', 'Auto-reconcile completed gateway transactions to payments'),
('gateway_auto_receipt', '1', 'payments', 'Auto-generate receipt after successful gateway payment')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
