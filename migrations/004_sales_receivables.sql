-- 004: Sales, customer credit and receivables compatibility.
-- Additive only. Quotes/statements have no canonical table yet and are new features.

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS address TEXT NULL,
    ADD COLUMN IF NOT EXISTS city VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS state VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS country VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS credit_used DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(40) NOT NULL DEFAULT 'Net 30',
    ADD COLUMN IF NOT EXISTS is_tax_exempt TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'current';

ALTER TABLE sales_invoices
    ADD COLUMN IF NOT EXISTS branch_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD COLUMN IF NOT EXISTS subtotal DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER total_amount,
    ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER subtotal,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER discount_percent,
    ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER tax_amount,
    ADD COLUMN IF NOT EXISTS amount_owed DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER amount_paid,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(30) NULL AFTER amount_owed,
    ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS posted_to_accounting TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by,
    ADD COLUMN IF NOT EXISTS paid_date DATE NULL AFTER posted_to_accounting;

ALTER TABLE sales_invoices
    MODIFY COLUMN status ENUM('draft','pending','partial','paid','cancelled') NOT NULL DEFAULT 'pending',
    ADD KEY IF NOT EXISTS idx_sales_invoices_issue (company_id, issue_date),
    ADD KEY IF NOT EXISTS idx_sales_invoices_branch (branch_id),
    ADD KEY IF NOT EXISTS idx_sales_invoices_customer (customer_id);

ALTER TABLE sales_invoice_items
    ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER unit_price,
    ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER unit_cost,
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER discount_percent;

CREATE TABLE IF NOT EXISTS quotes (
    quote_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    customer_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    quote_number VARCHAR(50) NOT NULL,
    status ENUM('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
    issue_date DATE NOT NULL,
    expiry_date DATE NULL,
    subtotal DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    tax_rate DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    total_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    notes TEXT NULL,
    converted_invoice_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (quote_id),
    UNIQUE KEY uk_quotes_number (quote_number),
    KEY idx_quotes_company_status (company_id, status),
    KEY idx_quotes_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_lines (
    line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    quote_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(19,4) NOT NULL DEFAULT 1.0000,
    unit_price DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
    discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    line_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (line_id),
    KEY idx_quote_lines_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_statements (
    statement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    customer_id BIGINT UNSIGNED NOT NULL,
    statement_date DATE NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    opening_balance DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    sales_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    payments_received DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    ending_balance DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    status ENUM('draft','sent') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (statement_id),
    KEY idx_statements_customer (customer_id, statement_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
