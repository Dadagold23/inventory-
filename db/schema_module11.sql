-- Module 11: Cash, Bank, and Payment Management
-- Extends Modules 1-10 with cash and bank transaction tracking system

-- --------------------------------------------------------
-- Table: banks
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS banks (
    bank_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(80) NOT NULL,
    account_number VARCHAR(50) NULL,
    account_type VARCHAR(20) NULL DEFAULT 'checking',
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    balance DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bank_id),
    INDEX idx_banks_company (company_id),
    INDEX idx_banks_active (is_active),
    UNIQUE KEY uk_banks_account (account_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Bank account catalogue';

-- --------------------------------------------------------
-- Table: bank_transactions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS bank_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    bank_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    transaction_type ENUM('deposit', 'withdrawal', 'transfer', 'fee', 'interest') NOT NULL,
    transaction_number VARCHAR(50) NOT NULL UNIQUE,
    transaction_date DATE NOT NULL DEFAULT CURRENT_DATE,
    description TEXT NULL,
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    balance_after DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    reference_number VARCHAR(100) NULL,
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'completed',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uk_transaction_number (transaction_number),
    INDEX idx_bank_transactions_bank (bank_id),
    INDEX idx_bank_transactions_date (transaction_date),
    INDEX idx_bank_transactions_type (transaction_type),
    CONSTRAINT fk_bank_transactions_bank FOREIGN KEY (bank_id) REFERENCES banks(bank_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_bank_transactions_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Bank transaction audit trail';

-- --------------------------------------------------------
-- Insert default banks
-- --------------------------------------------------------
INSERT IGNORE INTO banks (company_id, name, account_number, account_type, currency, balance, is_active, sort_order) VALUES
(1, 'Checking Account', '123456789', 'checking', 'USD', 0.0000, 1, 0),
(1, 'Savings Account', '987654321', 'savings', 'USD', 0.0000, 1, 1);

-- -------------------------------------------------------
-- End of Module 11 schema
-- -------------------------------------------------------