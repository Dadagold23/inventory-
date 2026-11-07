-- Repair currency-sweep effects missing from the recorded 017 state, then
-- add department assignment and shift-opening attribution for staff shifts.

ALTER TABLE companies
    MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

UPDATE companies SET currency = 'NGN' WHERE currency <> 'NGN';
UPDATE settings SET value = 'NGN' WHERE `key` = 'currency_default';
UPDATE settings SET value = '0' WHERE `key` = 'decimal_places';

INSERT IGNORE INTO settings (company_id, `key`, value, category, description)
SELECT company_id, 'currency_default', 'NGN', 'general', 'Default currency for the company ledger'
FROM companies;

INSERT IGNORE INTO settings (company_id, `key`, value, category, description)
SELECT company_id, 'usd_to_ngn', '1500', 'general', 'Naira value of one US dollar for foreign-customer sales'
FROM companies;

UPDATE system_settings SET value = 'NGN' WHERE `key` = 'currency_default';
UPDATE system_settings SET value = '0' WHERE `key` = 'decimal_places';
INSERT IGNORE INTO system_settings (company_id, `key`, value, value_type, description)
VALUES (1, 'usd_to_ngn', '1500', 'number', 'Naira value of one US dollar for foreign-customer sales');

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS department_id BIGINT UNSIGNED NULL
        COMMENT 'Primary department assignment for shift access';

ALTER TABLE cashier_shifts
    ADD COLUMN IF NOT EXISTS department_id BIGINT UNSIGNED NULL
        COMMENT 'Department assignment captured when the shift was opened',
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL
        COMMENT 'User who opened the shift for its cashier';
