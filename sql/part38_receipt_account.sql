-- ============================================================
-- FIS Real Estate — Part 38: choosing where a receipt lands
-- ============================================================
--
-- Until now the destination was derived, never chosen: cash and cheque went to
-- whoever recorded the payment (their personal float), everything else went to
-- the single account mapped as bank_default. That works for one cash tin and
-- one bank account, and not otherwise.
--
-- These columns record the account the user actually picked. They are nullable
-- on purpose: a row with NULL falls back to exactly the old behaviour, so
-- nothing already recorded changes meaning and no backfill is needed.
--
-- No foreign key. An account may be archived while historical rows still point
-- at it, and the posting code already validates the account and its currency
-- before using it — a constraint here would only turn a soft fallback into a
-- hard failure.
--
-- Safe to run more than once (the runner checks information_schema first).
-- For manual use, run against a tenant database:
--   mysql -u <user> -p <tenant_db> < sql/part38_receipt_account.sql

ALTER TABLE `payments`
    ADD COLUMN `ledger_account_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Cash box or bank account chosen on receipt (part 38)'
    AFTER `recorded_by`;

ALTER TABLE `deposits`
    ADD COLUMN `ledger_account_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Cash box or bank account chosen on receipt (part 38)'
    AFTER `reference_number`;
