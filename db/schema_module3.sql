-- Module 3: Company Management
-- Extends foundation with company configuration and settings

-- --------------------------------------------------------
-- Table: company_settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_settings (
    setting_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `key` VARCHAR(80) NOT NULL UNIQUE,
    value TEXT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_id),
    INDEX idx_settings_company (company_id),
    INDEX idx_settings_key (`key`),
    UNIQUE KEY uk_settings_company_key (company_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Company configuration settings';

-- --------------------------------------------------------
-- End of Module 3 schema
-- -------------------------------------------------------