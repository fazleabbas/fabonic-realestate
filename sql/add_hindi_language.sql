-- Add Hindi language support to users table
-- Run this on each tenant database

ALTER TABLE `users`
    MODIFY COLUMN `language` ENUM('en','sw','ar','hi') NOT NULL DEFAULT 'en';
