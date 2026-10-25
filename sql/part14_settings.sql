-- ============================================================
-- FIS Real Estate — Part 14: Settings & Configuration
-- Additional settings keys needed by Part 14
-- ============================================================

-- Add new settings keys that don't exist yet
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_tax_id', '', 'general', 'Company Tax ID / TIN'),
('company_website', '', 'general', 'Company website URL'),
('date_format_pref', 'd-m-Y', 'display', 'Preferred date display format'),
('fiscal_year_start', '01', 'general', 'Fiscal year start month (01-12)'),
('payment_instructions', '', 'payments', 'Payment instructions text for tenants')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
