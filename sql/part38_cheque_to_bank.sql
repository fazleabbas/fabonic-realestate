-- ============================================================
-- FIS Real Estate — Part 38: a cheque is not cash
-- ============================================================
--
-- part31 seeded float_payment_methods as 'cash,cheque', which meant a cheque
-- was treated as money in the collector's hands: it sat in their float until a
-- remittance was filed. That is not what happens to a cheque — it is paid into
-- a bank, and until it clears nobody is holding anything.
--
-- This moves cheques to the bank side. It only touches rows still holding the
-- seeded value, so a tenant that deliberately chose something else keeps it.
-- Running it twice matches nothing the second time.
--
-- To put cheques back on the float side for one tenant:
--   UPDATE settings SET setting_value = 'cash,cheque'
--    WHERE setting_key = 'float_payment_methods';

UPDATE `settings`
   SET `setting_value` = 'cash'
 WHERE `setting_key`   = 'float_payment_methods'
   AND `setting_value` = 'cash,cheque';

-- New tenants get the corrected default from the start.
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`)
SELECT 'float_payment_methods', 'cash', 'modules',
       'Payment methods that increase the collector float'
 WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `setting_key` = 'float_payment_methods');
