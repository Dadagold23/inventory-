-- Module 18: Financial Reporting
-- Extends Modules 1-17 with financial reports and analytics

-- --------------------------------------------------------
-- Table: financial_reports
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS financial_reports (
    report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    report_name VARCHAR(100) NOT NULL,
    report_type ENUM('income_statement', 'balance_sheet', 'cash_flow', 'trial_balance', 'general_ledger') NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(255) NULL,
    status ENUM('draft', 'generated', 'exported') NOT NULL DEFAULT 'draft',
    description TEXT NULL,
    PRIMARY KEY (report_id),
    INDEX idx_reports_company (company_id),
    INDEX idx_reports_type (report_type),
    INDEX idx_reports_period (period_start, period_end),
    CONSTRAINT fk_reports_generated_by FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Financial reports catalogue';

-- --------------------------------------------------------
-- Table: report_columns
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS report_columns (
    column_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    column_name VARCHAR(100) NOT NULL,
    data_source VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    format_type VARCHAR(50) NULL,
    PRIMARY KEY (column_id),
    INDEX idx_columns_report (report_id),
    CONSTRAINT fk_columns_report FOREIGN KEY (report_id) REFERENCES financial_reports(report_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Financial report columns';

-- --------------------------------------------------------
-- End of Module 18 schema
-- --------------------------------------------------------