-- Module 12: General Ledger / Double-Entry Accounting
-- Extends Modules 1-11 with general ledger and accounting entries

-- --------------------------------------------------------
-- Table: chart_of_accounts
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    account_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    account_code VARCHAR(20) NOT NULL,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('asset', 'liability', 'equity', 'income', 'expense') NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    UNIQUE KEY uk_account_code (account_code),
    INDEX idx_chart_company (company_id),
    INDEX idx_chart_parent (parent_id),
    CONSTRAINT fk_chart_parent FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(account_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Chart of accounts';

-- --------------------------------------------------------
-- Table: journal_entries
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_entries (
    entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    entry_number VARCHAR(50) NOT NULL UNIQUE,
    entry_date DATE NOT NULL DEFAULT CURRENT_DATE,
    description TEXT NULL,
    reference_number VARCHAR(50) NULL,
    reference_type VARCHAR(50) NULL,
    status ENUM('draft', 'posted') NOT NULL DEFAULT 'draft',
    posted_date TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id),
    INDEX idx_journals_company (company_id),
    INDEX idx_journals_date (entry_date),
    INDEX idx_journals_status (status),
    CONSTRAINT fk_journals_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Journal entries';

-- --------------------------------------------------------
-- Table: journal_entry_lines
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_entry_lines (
    line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    debit_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    credit_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    description TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (line_id),
    INDEX idx_lines_entry (entry_id),
    INDEX idx_lines_account (account_id),
    CONSTRAINT fk_lines_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(entry_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_lines_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts(account_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Journal entry lines';

-- --------------------------------------------------------
-- End of Module 12 schema
-- --------------------------------------------------------