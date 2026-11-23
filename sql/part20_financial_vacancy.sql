-- ============================================================
-- FIS Real Estate — Part 20: Financial Reporting & Vacancy Advertising
-- Migration: Minor additions to existing tables
-- Tables expense_categories, expenses, vacancy_listings, vacancy_inquiries
-- already exist in tenant_schema.sql.
-- ============================================================

-- Add status column to expenses for voiding
ALTER TABLE `expenses`
    ADD COLUMN `status` ENUM('active','voided') NOT NULL DEFAULT 'active' AFTER `maintenance_request_id`;

-- Add contact_name to vacancy_listings for public display
ALTER TABLE `vacancy_listings`
    ADD COLUMN `contact_name` VARCHAR(150) DEFAULT NULL AFTER `contact_email`;

-- Add deposit info to vacancy_listings
ALTER TABLE `vacancy_listings`
    ADD COLUMN `deposit_amount` DECIMAL(12,2) DEFAULT NULL AFTER `rent_amount`;

-- Add follow-up date to vacancy_inquiries
ALTER TABLE `vacancy_inquiries`
    ADD COLUMN `follow_up_date` DATE DEFAULT NULL AFTER `notes`;

-- New settings for Part 20
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('vacancy_contact_phone', '', 'modules', 'Default contact phone for vacancy listings'),
('vacancy_contact_email', '', 'modules', 'Default contact email for vacancy listings'),
('vacancy_company_name', '', 'modules', 'Company name shown on public vacancy page')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
