-- Module 7: Customer Receivables
-- Extends foundation with customer payment tracking

-- --------------------------------------------------------
-- Table: customer_payments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    customer_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'check', 'bank_transfer', 'credit_card') NOT NULL DEFAULT 'bank_transfer',
    reference_number VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    INDEX idx_payments_customer (customer_id),
    INDEX idx_payments_invoice (invoice_id),
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES sales_invoices(invoice_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Customer payments received';

-- --------------------------------------------------------
-- End of Module 7 schema
-- -------------------------------------------------------