-- ============================================================
-- FIS Real Estate — Add Gujarati (gu) Language Support
-- Run against each tenant database.
-- ============================================================

-- Update users table ENUM to include 'gu'
ALTER TABLE `users`
    MODIFY COLUMN `language` ENUM('en','sw','ar','hi','ur','gu') NOT NULL DEFAULT 'en';
