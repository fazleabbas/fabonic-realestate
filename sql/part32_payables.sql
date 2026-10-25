-- FIS Real Estate — Part 32: Payables, refunds and forfeitures (Phase 3)
--
-- Money going OUT. These are the events that need approval, because they are
-- where funds leave the business or a tenant loses their deposit:
--
--   expense incurred   Dr Expense              Cr Accounts Payable   (no approval)
--   expense paid       Dr Accounts Payable     Cr Bank or Float      (approval)
--   deposit refunded   Dr Tenant Deposits Held Cr Bank or Float      (approval)
--   deposit forfeited  Dr Tenant Deposits Held Cr Forfeit Income     (approval)
--
-- Recording an expense is never blocked — only paying it is, so a caretaker can
-- log the electricity bill the moment it arrives.
--
-- COLUMNS ARE NOT ADDED HERE. `ADD COLUMN IF NOT EXISTS` is MariaDB-only and
-- would fail on MySQL, and the `expenses` table has drifted between tenants
-- (some have a `status` column, some do not), so an AFTER clause cannot be
-- relied on either. deploy/migrate-tenants.php adds them, guarded by
-- information_schema. This file carries only the seed data.
--
-- Safe to re-run.

-- ============================================================
-- A default expense account, so a category with no account of its own still
-- posts somewhere sensible instead of failing.
-- ============================================================
INSERT INTO `accounts` (`code`, `name`, `type`, `subtype`, `currency`, `is_system`, `system_key`, `description`) VALUES
('5000', 'General Expenses', 'expense', NULL, 'TZS', 1, 'expense_default', 'Fallback for categories with no account of their own')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `is_system` = 1;

INSERT INTO `account_mappings` (`system_key`, `account_id`)
SELECT 'expense_default', `id` FROM `accounts`
 WHERE `system_key` = 'expense_default' AND `user_id` = 0
ON DUPLICATE KEY UPDATE `account_id` = COALESCE(`account_mappings`.`account_id`, VALUES(`account_id`));
