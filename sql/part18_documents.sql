-- ============================================================
-- FIS Real Estate — Part 18: Document Management & Storage
-- Migration SQL — Run against tenant DB
-- ============================================================

-- Document Categories
CREATE TABLE IF NOT EXISTS `document_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `icon` VARCHAR(50) NOT NULL DEFAULT 'bi-folder',
    `color` VARCHAR(20) NOT NULL DEFAULT '#6c757d',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX `idx_name` (`name`)
) ENGINE=InnoDB;

-- Documents
CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL COMMENT 'Original file name',
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `entity_type` ENUM('general','property','unit','tenant','lease','payment') NOT NULL DEFAULT 'general',
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `tags` JSON DEFAULT NULL COMMENT 'Array of tag strings',
    `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
    `expiry_date` DATE DEFAULT NULL,
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category_id`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_uploaded_by` (`uploaded_by`),
    INDEX `idx_expiry` (`expiry_date`),
    CONSTRAINT `fk_doc_category` FOREIGN KEY (`category_id`) REFERENCES `document_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Default categories
INSERT INTO `document_categories` (`name`, `description`, `icon`, `color`, `sort_order`, `is_system`) VALUES
('Lease Agreements', 'Signed lease contracts and amendments', 'bi-file-earmark-text', '#0d6efd', 1, 1),
('ID Documents', 'Tenant identification copies', 'bi-person-badge', '#6f42c1', 2, 1),
('Contracts', 'Vendor and service contracts', 'bi-file-earmark-ruled', '#198754', 3, 1),
('Financial Records', 'Invoices, statements, tax documents', 'bi-cash-stack', '#e8a838', 4, 1),
('Insurance', 'Insurance policies and claims', 'bi-shield-check', '#dc3545', 5, 1),
('Photos & Media', 'Property photos, videos, floor plans', 'bi-image', '#20c997', 6, 1),
('Receipts', 'Payment receipts and proofs', 'bi-receipt', '#fd7e14', 7, 1),
('Correspondence', 'Letters, notices, emails', 'bi-envelope', '#0dcaf0', 8, 1),
('Other', 'Miscellaneous documents', 'bi-folder', '#6c757d', 9, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Settings for document module
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('documents_enabled', '0', 'modules', 'Enable document management module'),
('storage_quota_mb', '500', 'modules', 'Storage quota in MB for documents')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
