-- Module 22: Currency sweep
-- Nigerian customers pay in Naira (NGN); foreign customers pay in US dollars.
-- Every sale stores its own currency, the exchange rate used, and the Naira
-- (base) value posted to the single company ledger.
-- Additive only; no existing data is removed.

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NULL
        COMMENT 'ISO 4217 code. NULL = derive from country. NGN = Nigerian Naira.'
        AFTER country;

ALTER TABLE sales_invoices
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER due_date,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency,
    ADD COLUMN IF NOT EXISTS base_subtotal DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER exchange_rate,
    ADD COLUMN IF NOT EXISTS base_discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_subtotal,
    ADD COLUMN IF NOT EXISTS base_tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_discount_amount,
    ADD COLUMN IF NOT EXISTS base_total_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_tax_amount,
    ADD COLUMN IF NOT EXISTS base_amount_paid DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_total_amount,
    ADD COLUMN IF NOT EXISTS base_amount_owed DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_amount_paid;

ALTER TABLE customer_payments
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER amount,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency,
    ADD COLUMN IF NOT EXISTS base_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER exchange_rate;

ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER customer_id,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency;

-- Back-fill existing Naira rows.
UPDATE sales_invoices
SET currency = 'NGN', exchange_rate = 1,
    base_subtotal = subtotal, base_discount_amount = discount_amount,
    base_tax_amount = tax_amount, base_total_amount = total_amount,
    base_amount_paid = amount_paid, base_amount_owed = amount_owed
WHERE base_total_amount = 0 AND total_amount <> 0;

UPDATE customer_payments
SET currency = 'NGN', exchange_rate = 1, base_amount = amount
WHERE base_amount = 0 AND amount <> 0;

UPDATE quotes SET currency = 'NGN', exchange_rate = 1 WHERE exchange_rate IS NULL OR exchange_rate = 0;

UPDATE customers SET currency = 'NGN'
WHERE currency IS NULL AND LOWER(TRIM(COALESCE(country, ''))) IN ('', 'nigeria', 'ng');

UPDATE customers SET currency = 'USD'
WHERE currency IS NULL AND TRIM(COALESCE(country, '')) <> ''
  AND LOWER(TRIM(country)) NOT IN ('nigeria', 'ng');

UPDATE companies SET currency = 'NGN';

ALTER TABLE companies
    MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

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
