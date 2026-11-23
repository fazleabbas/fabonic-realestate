-- FIS Real Estate — Add Urdu (ur) language support
-- Run this on each tenant database to add 'ur' to the users.language ENUM

ALTER TABLE `users`
    MODIFY COLUMN `language` ENUM('en','sw','ar','hi','ur') NOT NULL DEFAULT 'en';
