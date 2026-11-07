-- Module 9: Income Recording
-- Extends foundation with income tracking and recognition

-- --------------------------------------------------------
-- Table: income_records
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS income_records (
    income_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    source VARCHAR(100) NOT NULL,
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    source_id BIGINT UNSIGNED NULL,
    income_date DATE NOT NULL,
    description TEXT NULL,
    recognized TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (income_id),
    INDEX idx_income_company (company_id),
    INDEX idx_income_date (income_date),
    INDEX idx_income_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Income recording';

-- --------------------------------------------------------
-- End of Module 9 schema
-- -------------------------------------------------------