-- ============================================================
-- Fabonic Real Estate — Part 26: Utility Billing goes live
-- Run against each tenant DB (safe to re-run).
--
-- The utility_types / utility_readings / utility_charges tables already exist
-- in tenant_schema.sql but had no UI. This adds the two things the new
-- modules/utilities/ screens need:
--   1. a `utilities` permission on the non-Admin roles (Admin is {"all":true})
--   2. starter utility types so the module is usable immediately
--
-- Apply with:
--   php api/apply-tenant-sql.php --file=sql/part26_utility_billing.sql
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. Role permissions
-- Manager runs billing; Caretaker is on-site so records readings but does not
-- price or settle. JSON_MERGE_PATCH is a no-op if the key is already present.
-- ------------------------------------------------------------
UPDATE `roles`
SET `permissions` = JSON_MERGE_PATCH(`permissions`, '{"utilities": ["view","create","edit"]}')
WHERE `name` = 'Manager' AND JSON_EXTRACT(`permissions`, '$.utilities') IS NULL;

UPDATE `roles`
SET `permissions` = JSON_MERGE_PATCH(`permissions`, '{"utilities": ["view","create"]}')
WHERE `name` = 'Caretaker' AND JSON_EXTRACT(`permissions`, '$.utilities') IS NULL;

UPDATE `roles`
SET `permissions` = JSON_MERGE_PATCH(`permissions`, '{"utilities": ["view"]}')
WHERE `name` = 'Accountant' AND JSON_EXTRACT(`permissions`, '$.utilities') IS NULL;

-- ------------------------------------------------------------
-- 2. Starter utility types
-- Rates are 0 on purpose — every landlord's tariff differs, so they must be
-- set before charges mean anything. Guarded by NOT EXISTS because
-- utility_types has no unique index on `name`.
-- ------------------------------------------------------------
INSERT INTO `utility_types` (`name`, `unit_of_measure`, `rate_type`, `rate_amount`, `status`)
SELECT 'Water', 'litres', 'per_unit', 0.00, 'active'
WHERE NOT EXISTS (SELECT 1 FROM `utility_types` WHERE `name` = 'Water');

INSERT INTO `utility_types` (`name`, `unit_of_measure`, `rate_type`, `rate_amount`, `status`)
SELECT 'Electricity', 'kWh', 'per_unit', 0.00, 'active'
WHERE NOT EXISTS (SELECT 1 FROM `utility_types` WHERE `name` = 'Electricity');

INSERT INTO `utility_types` (`name`, `unit_of_measure`, `rate_type`, `rate_amount`, `status`)
SELECT 'Garbage Collection', 'month', 'flat', 0.00, 'active'
WHERE NOT EXISTS (SELECT 1 FROM `utility_types` WHERE `name` = 'Garbage Collection');
