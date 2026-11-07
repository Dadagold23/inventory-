-- Module 14: Payments Made / Accounts Payable
-- Extends Modules 1-13 with vendor payment processing

-- --------------------------------------------------------
-- Table: payment_made
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_made (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    vendor_id BIGINT UNSIGNED NOT NULL,
    purchase_order_id BIGINT UNSIGNED NULL,
    payment_date DATE NOT NULL DEFAULT CURRENT_DATE,
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    payment_method ENUM('cash', 'check', 'bank_transfer', 'credit_card') NOT NULL DEFAULT 'bank_transfer',
    reference_number VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    INDEX idx_payments_vendor (vendor_id),
    INDEX idx_payments_po (purchase_order_id),
    INDEX idx_payments_date (payment_date),
    CONSTRAINT fk_payments_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(vendor_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payments_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Payments made to vendors';

-- --------------------------------------------------------
-- End of Module 14 schema
-- --------------------------------------------------------