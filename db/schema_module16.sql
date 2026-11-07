-- Module 16: Tax Management
-- Extends Modules 1-15 with tax calculation and reporting

-- --------------------------------------------------------
-- Table: tax_rates
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_rates (
    tax_rate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(80) NOT NULL,
    rate DECIMAL(5, 4) NOT NULL DEFAULT 0.0000,
    tax_type ENUM('gst', 'vat', 'sales_tax', 'use_tax') NOT NULL DEFAULT 'sales_tax',
    jurisdiction VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    effective_date DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date DATE NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tax_rate_id),
    INDEX idx_taxrates_company (company_id),
    INDEX idx_taxrates_active (is_active),
    UNIQUE KEY uk_taxrates_jurisdiction (jurisdiction, tax_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Tax rates catalogue';

-- --------------------------------------------------------
-- Table: tax_transactions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    tax_rate_id BIGINT UNSIGNED NOT NULL,
    taxable_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    tax_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    transaction_type ENUM('sale', 'purchase', 'adjustment') NOT NULL DEFAULT 'sale',
    reference_id BIGINT UNSIGNED NULL,
    reference_type VARCHAR(50) NULL,
    transaction_date DATE NOT NULL DEFAULT CURRENT_DATE,
    description TEXT NULL,
    is_reconciled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    INDEX idx_taxtransactions_rate (tax_rate_id),
    INDEX idx_taxtransactions_date (transaction_date),
    INDEX idx_taxtransactions_type (transaction_type),
    CONSTRAINT fk_taxtransactions_rate FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(tax_rate_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Tax transaction audit trail';

-- --------------------------------------------------------
-- End of Module 16 schema
-- --------------------------------------------------------