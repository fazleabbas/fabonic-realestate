-- ============================================================
-- Part 40 — Opening data import
--
-- Every commit of an import is one batch. The batch remembers each record it
-- created, so it can be undone while nothing has been built on top of it.
--
-- Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `import_batches` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(30) NOT NULL COMMENT 'rent_roll, arrears, deposits, advances, trial_balance',
    `file_name` VARCHAR(255) DEFAULT NULL,
    `go_live_date` DATE NOT NULL COMMENT 'Go-live date in force when committed',
    `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `summary` TEXT DEFAULT NULL COMMENT 'JSON: counts and totals shown on the setup page',
    `status` ENUM('committed','undone') NOT NULL DEFAULT 'committed',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `undone_by` INT UNSIGNED DEFAULT NULL,
    `undone_at` DATETIME DEFAULT NULL,
    KEY `idx_type_status` (`type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_batch_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT UNSIGNED NOT NULL,
    `table_name` VARCHAR(40) NOT NULL,
    `record_id` INT UNSIGNED NOT NULL,
    `action` ENUM('created','updated') NOT NULL DEFAULT 'created',
    `before_json` TEXT DEFAULT NULL COMMENT 'Prior values of an updated record, for undo',
    KEY `idx_batch` (`batch_id`),
    KEY `idx_record` (`table_name`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('go_live_date', '', 'modules', 'First day this system is the books of record; opening figures are dated the day before'),
('setup_finished_at', '', 'modules', 'When Finish setup locked the opening figures'),
('setup_finished_by', '', 'modules', 'Who clicked Finish setup')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
