-- FIS Real Estate — Part 27: Tenant TIN + Next of Kin
--
-- Adds the taxpayer number and next-of-kin block to the tenants table.
-- Next of kin is kept separate from emergency_contact_* on purpose: the
-- emergency contact is who to call in a crisis, the next of kin is the relative
-- declared on the lease.
--
-- Run once against EVERY existing tenant database. New tenants get these
-- columns from sql/tenant_schema.sql, so this migration is only for databases
-- provisioned before it.
--
-- Safe to re-run: each ALTER is guarded, so a second run reports no change
-- rather than failing with "Duplicate column name".

DELIMITER $$

DROP PROCEDURE IF EXISTS `re_add_tenant_tin_nok`$$

CREATE PROCEDURE `re_add_tenant_tin_nok`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'tin') THEN
        ALTER TABLE `tenants`
            ADD COLUMN `tin` VARCHAR(20) DEFAULT NULL
                COMMENT 'Taxpayer Identification Number, stored NNN-NNN-NNN when 9 digits'
                AFTER `emergency_contact_relation`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'next_of_kin_name') THEN
        ALTER TABLE `tenants`
            ADD COLUMN `next_of_kin_name` VARCHAR(150) DEFAULT NULL AFTER `tin`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'next_of_kin_relationship') THEN
        ALTER TABLE `tenants`
            ADD COLUMN `next_of_kin_relationship` VARCHAR(50) DEFAULT NULL AFTER `next_of_kin_name`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'next_of_kin_phone') THEN
        ALTER TABLE `tenants`
            ADD COLUMN `next_of_kin_phone` VARCHAR(20) DEFAULT NULL AFTER `next_of_kin_relationship`;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'next_of_kin_address') THEN
        ALTER TABLE `tenants`
            ADD COLUMN `next_of_kin_address` VARCHAR(255) DEFAULT NULL AFTER `next_of_kin_phone`;
    END IF;
END$$

DELIMITER ;

CALL `re_add_tenant_tin_nok`();
DROP PROCEDURE IF EXISTS `re_add_tenant_tin_nok`;
