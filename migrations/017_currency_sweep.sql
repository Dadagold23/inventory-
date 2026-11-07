-- ============================================================
-- 017: Currency sweep — Naira home ledger, dollar foreign sales,
--      whole-number money.
--
-- Every sale records the currency it was priced in and the rate
-- used to translate it into the company's Naira ledger. Naira
-- rows keep rate = 1, so existing figures do not move.
-- Additive and idempotent.
-- ============================================================

-- 1. Per-customer currency (folded from db/schema_module22.sql).
--    NULL means "derive from country"; NGN is the Nigerian default.
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NULL
        COMMENT 'ISO 4217 code. NULL = derive from country. NGN = Nigerian Naira.'
        AFTER country;

-- 2. Sales invoices carry their own currency, the rate used, and the
--    Naira (base) value of every money column.
ALTER TABLE sales_invoices
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER due_date,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency,
    ADD COLUMN IF NOT EXISTS base_subtotal DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER exchange_rate,
    ADD COLUMN IF NOT EXISTS base_discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_subtotal,
    ADD COLUMN IF NOT EXISTS base_tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_discount_amount,
    ADD COLUMN IF NOT EXISTS base_total_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_tax_amount,
    ADD COLUMN IF NOT EXISTS base_amount_paid DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_total_amount,
    ADD COLUMN IF NOT EXISTS base_amount_owed DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER base_amount_paid;

-- 3. Customer payments mirror the invoice currency and keep the Naira value.
ALTER TABLE customer_payments
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER amount,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency,
    ADD COLUMN IF NOT EXISTS base_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER exchange_rate;

-- 4. Quotes are priced in the customer's currency too.
ALTER TABLE quotes
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'NGN' AFTER customer_id,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(19,6) NOT NULL DEFAULT 1.000000 AFTER currency;

-- 5. Backfill: the books were Naira, so rate = 1 and base = face value.
UPDATE sales_invoices
SET currency = 'NGN',
    exchange_rate = 1,
    base_subtotal = subtotal,
    base_discount_amount = discount_amount,
    base_tax_amount = tax_amount,
    base_total_amount = total_amount,
    base_amount_paid = amount_paid,
    base_amount_owed = amount_owed
WHERE base_total_amount = 0 AND total_amount <> 0;

UPDATE customer_payments
SET currency = 'NGN', exchange_rate = 1, base_amount = amount
WHERE base_amount = 0 AND amount <> 0;

UPDATE quotes SET currency = 'NGN', exchange_rate = 1 WHERE exchange_rate IS NULL OR exchange_rate = 0;

-- 6. Customer currency from country: blank / Nigeria / NG = Naira,
--    any other named country = US dollar. An explicit currency always wins.
UPDATE customers SET currency = 'NGN'
WHERE currency IS NULL AND LOWER(TRIM(COALESCE(country, ''))) IN ('', 'nigeria', 'ng');

UPDATE customers SET currency = 'USD'
WHERE currency IS NULL AND TRIM(COALESCE(country, '')) <> ''
  AND LOWER(TRIM(country)) NOT IN ('nigeria', 'ng');

-- 7. The company ledger is Naira.
ALTER TABLE companies
    MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

UPDATE companies SET currency = 'NGN';

-- 8. Money is whole Naira. The USD→NGN rate is configurable per company.
UPDATE settings SET value = 'NGN' WHERE `key` = 'currency_default';
UPDATE settings SET value = '0' WHERE `key` = 'decimal_places';

INSERT IGNORE INTO settings (company_id, `key`, value, category, description)
SELECT company_id, 'currency_default', 'NGN', 'general', 'Default currency for the company ledger'
FROM companies;

INSERT IGNORE INTO settings (company_id, `key`, value, category, description)
SELECT company_id, 'usd_to_ngn', '1500', 'general', 'Naira value of one US dollar for foreign-customer sales'
FROM companies;

-- Legacy system_settings mirror (its key is globally unique, so company 1).
UPDATE system_settings SET value = 'NGN' WHERE `key` = 'currency_default';
UPDATE system_settings SET value = '0' WHERE `key` = 'decimal_places';
INSERT IGNORE INTO system_settings (company_id, `key`, value, value_type, description)
VALUES (1, 'usd_to_ngn', '1500', 'number', 'Naira value of one US dollar for foreign-customer sales');
