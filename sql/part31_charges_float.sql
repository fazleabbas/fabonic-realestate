-- FIS Real Estate — Part 31: Charges, allocation, floats, remittance (Phase 2)
--
-- Turns the ledger from a manual book into the system of record for money:
--   * rent, utilities and late fees become CHARGES — what a tenant owes
--   * payments are ALLOCATED against those charges
--   * cash collected by a person raises their FLOAT
--   * banking that cash is a REMITTANCE, which needs approval
--   * deposits become a liability rather than an unexplained asset
--
-- Arrears move from "expected rent minus payments received" to "unsettled
-- charges", which is why the charge generator must run before the crons do.
--
-- Safe to re-run.

-- ============================================================
-- Charges — what a tenant owes, and when
-- ============================================================
CREATE TABLE IF NOT EXISTS `charges` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('rent','utility','late_fee','other') NOT NULL DEFAULT 'rent',
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED NOT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `property_id` INT UNSIGNED DEFAULT NULL,
    `period_from` DATE DEFAULT NULL,
    `period_to` DATE DEFAULT NULL,
    `due_date` DATE NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `amount_settled` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('open','part','settled','waived','cancelled') NOT NULL DEFAULT 'open',
    `description` VARCHAR(255) DEFAULT NULL,
    -- Where this charge came from, so utility_charges and late_fees are never
    -- double-counted when their own rows already exist.
    `source_type` VARCHAR(30) NOT NULL DEFAULT 'rent_generator',
    `source_id` INT UNSIGNED DEFAULT NULL,
    `entry_id` INT UNSIGNED DEFAULT NULL COMMENT 'Journal entry that recognised the income',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_lease` (`lease_id`),
    INDEX `idx_due` (`due_date`),
    INDEX `idx_status` (`status`),
    -- One rent charge per lease per period. This is what makes the generator
    -- safe to run as often as you like.
    UNIQUE INDEX `idx_period` (`lease_id`, `type`, `period_from`, `source_type`)
) ENGINE=InnoDB;

-- ============================================================
-- Which payment settled which charge
-- ============================================================
CREATE TABLE IF NOT EXISTS `payment_allocations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT UNSIGNED NOT NULL,
    `charge_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payment` (`payment_id`),
    INDEX `idx_charge` (`charge_id`),
    CONSTRAINT `fk_alloc_charge` FOREIGN KEY (`charge_id`) REFERENCES `charges`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- Remittance — a collector banking the cash they hold
-- ============================================================
CREATE TABLE IF NOT EXISTS `remittances` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference_no` VARCHAR(20) NOT NULL,
    `float_account_id` INT UNSIGNED NOT NULL,
    `bank_account_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(14,2) NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `remitted_date` DATE NOT NULL,
    `bank_reference` VARCHAR(100) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `entry_id` INT UNSIGNED DEFAULT NULL,
    `requested_by` INT UNSIGNED DEFAULT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejection_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_ref` (`reference_no`),
    INDEX `idx_float` (`float_account_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- ============================================================
-- Settings. Defaults chosen so the module behaves sensibly untouched.
-- ============================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('ledger_auto_post', '1', 'modules', 'Post payments, deposits and charges to the ledger automatically'),
('charge_advance_days', '0', 'modules', 'Raise rent charges this many days before they fall due'),
('float_payment_methods', 'cash', 'modules', 'Payment methods that increase the collector float'),
('float_limit', '0', 'modules', 'Warn when a collector float exceeds this amount (0 = no limit)')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Deposits need to know who collected them, or the money cannot reach a float.
-- Added by the migration runner where the column is missing.

-- ============================================================
-- Where bank transfers and gateway payments land. Left unmapped on purpose:
-- only the client knows which of their bank accounts is the default, and an
-- unmapped key fails loudly in the ledger rather than posting to the wrong one.
-- ============================================================
INSERT INTO `account_mappings` (`system_key`, `account_id`) VALUES ('bank_default', NULL)
ON DUPLICATE KEY UPDATE `system_key` = `system_key`;
