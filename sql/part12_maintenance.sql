-- ============================================================
-- FIS Real Estate — Part 12: Maintenance & Work Orders
-- Additional schema for time logging on work orders.
-- Core tables (maintenance_requests, maintenance_photos,
-- maintenance_assignments, contractors, maintenance_categories)
-- already exist in tenant_schema.sql.
-- ============================================================

-- Maintenance Time Logs (track hours worked per assignment)
CREATE TABLE IF NOT EXISTS `maintenance_time_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT UNSIGNED NOT NULL,
    `log_date` DATE NOT NULL,
    `hours_worked` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `description` VARCHAR(255) DEFAULT NULL,
    `logged_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_assignment` (`assignment_id`),
    CONSTRAINT `fk_timelog_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `maintenance_assignments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Add completion_notes column to maintenance_requests if not exists
-- (for storing final notes when closing a request)
ALTER TABLE `maintenance_requests`
    ADD COLUMN IF NOT EXISTS `completed_date` DATE DEFAULT NULL AFTER `updated_at`,
    ADD COLUMN IF NOT EXISTS `completion_notes` TEXT DEFAULT NULL AFTER `completed_date`;
