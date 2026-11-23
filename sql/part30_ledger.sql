-- FIS Real Estate — Part 30: Double-entry ledger (Phase 1)
--
-- Chart of accounts, journal entries with approval, opening balances,
-- immutability via reversing entries, and the mapping table that automatic
-- postings (phase 2+) will use to find their accounts.
--
-- Phase 1 changes nothing in the existing system. Payments, deposits, reports
-- and the dashboard are untouched; this ledger stands alone until phase 2.
--
-- Safe to re-run: every statement is IF NOT EXISTS or an idempotent upsert.

-- ============================================================
-- Chart of accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) DEFAULT NULL COMMENT 'Optional chart code, e.g. 1100',
    `name` VARCHAR(150) NOT NULL,
    `type` ENUM('asset','liability','equity','income','expense') NOT NULL,
    `subtype` VARCHAR(30) DEFAULT NULL COMMENT 'bank, cash, float, receivable, payable, deposit_liability',
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `parent_id` INT UNSIGNED DEFAULT NULL,
    -- 0 rather than NULL: a UNIQUE index treats NULLs as distinct, so a
    -- nullable column here would let the same system account be seeded twice.
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner of a float account (phase 2), 0 = none',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Protected: cannot be deleted',
    `system_key` VARCHAR(50) DEFAULT NULL COMMENT 'ar, ap, deposits_held, rent_income, ...',
    `description` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_type` (`type`),
    INDEX `idx_currency` (`currency`),
    INDEX `idx_status` (`status`),
    INDEX `idx_user` (`user_id`),
    -- One system account per key per currency per user. NULLs repeat freely,
    -- so client-created accounts are unconstrained.
    UNIQUE INDEX `idx_system` (`system_key`, `currency`, `user_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Journal entries — header
-- ============================================================
CREATE TABLE IF NOT EXISTS `journal_entries` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entry_no` VARCHAR(20) NOT NULL,
    `entry_date` DATE NOT NULL,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `memo` VARCHAR(255) DEFAULT NULL,
    `source_type` VARCHAR(30) NOT NULL DEFAULT 'manual'
        COMMENT 'manual, opening_balance, reversal, payment, deposit, remittance, expense',
    `source_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending','posted','rejected') NOT NULL DEFAULT 'pending',
    `total_debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `reverses_entry_id` INT UNSIGNED DEFAULT NULL COMMENT 'This entry reverses that one',
    `reversed_by_entry_id` INT UNSIGNED DEFAULT NULL COMMENT 'That entry reverses this one',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `rejection_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_entry_no` (`entry_no`),
    INDEX `idx_date` (`entry_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_source` (`source_type`, `source_id`)
) ENGINE=InnoDB;

-- ============================================================
-- Journal entries — lines
-- ============================================================
CREATE TABLE IF NOT EXISTS `journal_lines` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `entry_id` INT UNSIGNED NOT NULL,
    `line_no` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `account_id` INT UNSIGNED NOT NULL,
    `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    -- Dimensions, so a property P&L falls out of the ledger (phase 4).
    `property_id` INT UNSIGNED DEFAULT NULL,
    `unit_id` INT UNSIGNED DEFAULT NULL,
    `lease_id` INT UNSIGNED DEFAULT NULL,
    `tenant_id` INT UNSIGNED DEFAULT NULL,
    `memo` VARCHAR(255) DEFAULT NULL,
    INDEX `idx_entry` (`entry_id`),
    INDEX `idx_account` (`account_id`),
    INDEX `idx_property` (`property_id`),
    CONSTRAINT `fk_jl_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_jl_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB;

-- ============================================================
-- Event -> account wiring for automatic postings
-- ============================================================
CREATE TABLE IF NOT EXISTS `account_mappings` (
    `system_key` VARCHAR(50) NOT NULL PRIMARY KEY,
    `account_id` INT UNSIGNED DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- System accounts. Codes follow the usual convention and can be edited;
-- system_key is what the engine looks up and never changes.
-- ============================================================
INSERT INTO `accounts` (`code`, `name`, `type`, `subtype`, `currency`, `is_system`, `system_key`, `description`) VALUES
('1100', 'Accounts Receivable',      'asset',     'receivable',        'TZS', 1, 'ar',                     'What tenants owe'),
('2100', 'Accounts Payable',         'liability', 'payable',           'TZS', 1, 'ap',                     'What we owe suppliers'),
('2200', 'Tenant Deposits Held',     'liability', 'deposit_liability', 'TZS', 1, 'deposits_held',          'Deposits held on behalf of tenants'),
('3000', 'Opening Balance Equity',   'equity',    NULL,                'TZS', 1, 'opening_equity',         'Contra account for opening balances'),
('3100', 'Retained Earnings',        'equity',    NULL,                'TZS', 1, 'retained_earnings',      'Accumulated result'),
('4000', 'Rent Income',              'income',    NULL,                'TZS', 1, 'rent_income',            'Rent earned'),
('4100', 'Late Fee Income',          'income',    NULL,                'TZS', 1, 'late_fee_income',        'Late payment charges'),
('4200', 'Utility Income',           'income',    NULL,                'TZS', 1, 'utility_income',         'Recharged utilities'),
('4300', 'Deposit Forfeited',        'income',    NULL,                'TZS', 1, 'deposit_forfeit_income', 'Deposits retained on forfeit')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `is_system` = 1;

-- Wire each mapping to its system account.
-- Float accounts are excluded on purpose: they are per user, created on demand,
-- and are never the target of a mapping. Including them here would create a
-- bogus 'float' mapping pointing at one arbitrary person's account every time
-- this file is re-run.
INSERT INTO `account_mappings` (`system_key`, `account_id`)
SELECT `system_key`, `id` FROM `accounts`
 WHERE `is_system` = 1 AND `system_key` IS NOT NULL AND `system_key` <> 'float'
ON DUPLICATE KEY UPDATE `account_id` = COALESCE(`account_mappings`.`account_id`, VALUES(`account_id`));

DELETE FROM `account_mappings` WHERE `system_key` = 'float';

-- ============================================================
-- Per-user approval right. Off by default: nobody gains it silently.
-- Added here rather than in a procedure so the migration stays flat SQL.
-- The runner adds it conditionally; this line is for fresh installs.
-- ============================================================
-- Give the finance-facing system roles access. Admin holds {"all": true} and
-- is unaffected. Other roles gain nothing until an administrator grants it.
UPDATE `roles`
   SET `permissions` = JSON_SET(`permissions`, '$.ledger', JSON_ARRAY('view','create','edit'))
 WHERE `name` = 'Accountant' AND `is_system` = 1;

UPDATE `roles`
   SET `permissions` = JSON_SET(`permissions`, '$.ledger', JSON_ARRAY('view'))
 WHERE `name` = 'Manager' AND `is_system` = 1;
