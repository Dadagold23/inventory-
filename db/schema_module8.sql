-- Module 8: Expenses
-- Extends foundation with expense tracking and categorization

-- --------------------------------------------------------
-- Table: expenses
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    expense_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    expense_date DATE NOT NULL,
    description TEXT NOT NULL,
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    category VARCHAR(50) NULL,
    receipt_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (expense_id),
    INDEX idx_expenses_vendor (vendor_id),
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Expenses tracking';

-- --------------------------------------------------------
-- End of Module 8 schema
-- -------------------------------------------------------