-- 005: Accounts payable, expenses and income compatibility.
-- Additive only: existing rows are preserved and new columns are backfilled
-- from the values that already exist in each table.

-- ------------------------------------------------------------
-- Vendors (canonical supplier table)
-- ------------------------------------------------------------
ALTER TABLE vendors
    ADD COLUMN IF NOT EXISTS contact_name VARCHAR(100) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS city VARCHAR(80) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS state VARCHAR(80) NULL AFTER city,
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NULL AFTER state,
    ADD COLUMN IF NOT EXISTS country VARCHAR(80) NULL AFTER postal_code,
    ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(40) NOT NULL DEFAULT 'Net 30' AFTER country,
    ADD COLUMN IF NOT EXISTS is_tax_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_terms,
    ADD COLUMN IF NOT EXISTS default_currency VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER is_tax_exempt,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER default_currency;

-- ------------------------------------------------------------
-- Purchase orders
-- ------------------------------------------------------------
ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS branch_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN IF NOT EXISTS required_date DATE NULL AFTER due_date,
    ADD COLUMN IF NOT EXISTS subtotal DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER total_amount,
    ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER subtotal,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER discount_percent,
    ADD COLUMN IF NOT EXISTS tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER tax_rate,
    ADD COLUMN IF NOT EXISTS amount_owed DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS amount_received DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER amount_owed,
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS approved_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS approved_date DATETIME NULL AFTER approved_by;

ALTER TABLE purchase_orders
    MODIFY COLUMN status ENUM('draft','sent','pending','partial','partial_received','received','completed','cancelled')
        NOT NULL DEFAULT 'pending',
    ADD KEY IF NOT EXISTS idx_purchase_orders_company (company_id),
    ADD KEY IF NOT EXISTS idx_purchase_orders_branch (branch_id),
    ADD KEY IF NOT EXISTS idx_purchase_orders_created_by (created_by);

UPDATE purchase_orders
SET required_date = due_date
WHERE required_date IS NULL AND due_date IS NOT NULL;

UPDATE purchase_orders
SET amount_owed = total_amount
WHERE amount_owed = 0 AND total_amount > 0;

ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER unit_price,
    ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER unit_cost,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER discount_percent,
    ADD COLUMN IF NOT EXISTS total_price DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER line_total,
    ADD COLUMN IF NOT EXISTS total_cost DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER total_price;

UPDATE purchase_order_items
SET total_price = line_total
WHERE total_price = 0 AND line_total <> 0;

UPDATE purchase_order_items
SET total_cost = unit_cost * quantity
WHERE total_cost = 0 AND unit_cost > 0;

-- ------------------------------------------------------------
-- Invoice receipts (three-way match)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_receipts (
    invoice_receipt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    purchase_order_id BIGINT UNSIGNED NULL,
    vendor_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    receipt_number VARCHAR(50) NULL,
    invoice_date DATE NOT NULL,
    receipt_date DATE NULL,
    status ENUM('matching','full_match','partial_match','mismatch','paid') NOT NULL DEFAULT 'matching',
    invoice_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    receipt_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    invoiced_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    received_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    quantity_variance DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    amount_due DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    paid TINYINT(1) NOT NULL DEFAULT 0,
    payment_date DATE NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (invoice_receipt_id),
    KEY idx_invoice_receipts_company (company_id),
    KEY idx_invoice_receipts_po (purchase_order_id),
    KEY idx_invoice_receipts_vendor (vendor_id),
    KEY idx_invoice_receipts_status (status),
    CONSTRAINT fk_invoice_receipts_po FOREIGN KEY (purchase_order_id)
        REFERENCES purchase_orders (purchase_order_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_invoice_receipts_vendor FOREIGN KEY (vendor_id)
        REFERENCES vendors (vendor_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payment_made
    ADD COLUMN IF NOT EXISTS invoice_receipt_id BIGINT UNSIGNED NULL AFTER purchase_order_id,
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER notes,
    ADD KEY IF NOT EXISTS idx_payment_made_ir (invoice_receipt_id),
    ADD CONSTRAINT fk_payment_made_ir FOREIGN KEY (invoice_receipt_id)
        REFERENCES invoice_receipts (invoice_receipt_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE payment_made
    MODIFY COLUMN payment_method ENUM('cash','check','bank_transfer','credit_card','debit_card')
        NOT NULL DEFAULT 'bank_transfer';

-- ------------------------------------------------------------
-- Expense categories and expenses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expense_categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    KEY idx_expense_categories_company (company_id),
    KEY idx_expense_categories_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE expenses
    ADD COLUMN IF NOT EXISTS category_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN IF NOT EXISTS employee_id BIGINT UNSIGNED NULL AFTER vendor_id,
    ADD COLUMN IF NOT EXISTS expense_number VARCHAR(50) NULL AFTER employee_id,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER amount,
    ADD COLUMN IF NOT EXISTS total_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS receipt_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER receipt_path,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER receipt_status,
    ADD COLUMN IF NOT EXISTS payment_date DATE NULL AFTER payment_status,
    ADD COLUMN IF NOT EXISTS approved_by BIGINT UNSIGNED NULL AFTER payment_date,
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER approved_by,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER created_by,
    ADD KEY IF NOT EXISTS idx_expenses_category (category_id),
    ADD KEY IF NOT EXISTS idx_expenses_employee (employee_id),
    ADD KEY IF NOT EXISTS idx_expenses_status (payment_status),
    ADD KEY IF NOT EXISTS idx_expenses_number (expense_number);

-- Preserve the legacy free-text category labels as category rows.
INSERT INTO expense_categories (company_id, name)
SELECT DISTINCT e.company_id, TRIM(e.category)
FROM expenses e
LEFT JOIN expense_categories ec
    ON ec.company_id = e.company_id AND ec.name = TRIM(e.category)
WHERE e.category IS NOT NULL
  AND TRIM(e.category) <> ''
  AND ec.category_id IS NULL;

UPDATE expenses e
INNER JOIN expense_categories ec
    ON ec.company_id = e.company_id AND ec.name = TRIM(e.category)
SET e.category_id = ec.category_id
WHERE e.category_id IS NULL;

UPDATE expenses
SET expense_number = CONCAT('EXP-', DATE_FORMAT(expense_date, '%Y%m%d'), '-', LPAD(expense_id, 4, '0'))
WHERE expense_number IS NULL OR expense_number = '';

UPDATE expenses
SET total_amount = amount + tax_amount
WHERE total_amount = 0 AND amount <> 0;

-- ------------------------------------------------------------
-- Income sources and income records
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS income_sources (
    income_source_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (income_source_id),
    KEY idx_income_sources_company (company_id),
    KEY idx_income_sources_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE income_records
    ADD COLUMN IF NOT EXISTS transaction_number VARCHAR(50) NULL AFTER income_id,
    ADD COLUMN IF NOT EXISTS branch_id BIGINT UNSIGNED NULL AFTER source_id,
    ADD COLUMN IF NOT EXISTS customer_id BIGINT UNSIGNED NULL AFTER branch_id,
    ADD COLUMN IF NOT EXISTS sales_invoice_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER amount,
    ADD COLUMN IF NOT EXISTS gross_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER payment_status,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER gross_amount,
    ADD COLUMN IF NOT EXISTS net_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER net_amount,
    ADD COLUMN IF NOT EXISTS total_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS recognition_date DATE NULL AFTER recognized,
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER recognition_date,
    ADD KEY IF NOT EXISTS idx_income_status (payment_status),
    ADD KEY IF NOT EXISTS idx_income_customer (customer_id),
    ADD KEY IF NOT EXISTS idx_income_source_id (source_id),
    ADD KEY IF NOT EXISTS idx_income_number (transaction_number);

-- Preserve the legacy free-text source labels as source rows.
INSERT INTO income_sources (company_id, name)
SELECT DISTINCT ir.company_id, TRIM(ir.source)
FROM income_records ir
LEFT JOIN income_sources s
    ON s.company_id = ir.company_id AND s.name = TRIM(ir.source)
WHERE ir.source IS NOT NULL
  AND TRIM(ir.source) <> ''
  AND s.income_source_id IS NULL;

UPDATE income_records ir
INNER JOIN income_sources s
    ON s.company_id = ir.company_id AND s.name = TRIM(ir.source)
SET ir.source_id = s.income_source_id
WHERE ir.source_id IS NULL;

UPDATE income_records
SET transaction_number = CONCAT('INC-', DATE_FORMAT(income_date, '%Y%m%d'), '-', LPAD(income_id, 4, '0'))
WHERE transaction_number IS NULL OR transaction_number = '';

UPDATE income_records
SET gross_amount = amount,
    net_amount = amount,
    total_amount = amount
WHERE gross_amount = 0 AND amount <> 0;
