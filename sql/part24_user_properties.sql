-- ============================================================
-- FIS Real Estate — Part 24: User ↔ Property Mapping (Access Scoping)
-- Migration SQL — Run against tenant DB
-- Foundation only: enforcement is gated by the 'property_scoping_enabled' flag.
-- ============================================================

-- Many-to-many: which properties each user may access.
CREATE TABLE IF NOT EXISTS `user_properties` (
    `user_id` INT UNSIGNED NOT NULL,
    `property_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `property_id`),
    INDEX `idx_up_property` (`property_id`),
    CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_up_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Master switch. '0' = scoping OFF (behaves exactly as before). Flip to '1' only
-- AFTER users have been mapped, otherwise every non-Admin user is locked out (strict mode).
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('property_scoping_enabled', '0', 'general', 'Restrict non-admin users to their assigned properties')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
