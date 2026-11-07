-- Module 19: System Settings
-- Extends Modules 1-18 with system configuration and preferences

-- --------------------------------------------------------
-- Create system_settings table
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `key` VARCHAR(80) NOT NULL UNIQUE,
    value TEXT NULL,
    value_type ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_id),
    INDEX idx_settings_company (company_id),
    INDEX idx_settings_key (`key`),
    UNIQUE KEY uk_settings_company_key (company_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'System configuration settings';

-- --------------------------------------------------------
-- Insert sample system settings
-- --------------------------------------------------------
INSERT IGNORE INTO system_settings (company_id, `key`, value, value_type, description, is_active, created_at) VALUES
(1, 'company_name', 'Inventory Management Co.', 'string', 'Company name for reports and documents', 1, NOW()),
(1, 'company_address', '123 Business Ave, City, State', 'string', 'Company address', 1, NOW()),
(1, 'company_phone', '555-0100', 'string', 'Company phone number', 1, NOW()),
(1, 'currency_default', 'USD', 'string', 'Default currency for all transactions', 1, NOW()),
(1, 'decimal_places', '4', 'number', 'Default decimal places for monetary values', 1, NOW()),
(1, 'enable_inventory', '1', 'boolean', 'Enable inventory management', 1, NOW()),
(1, 'enable_approvals', '1', 'boolean', 'Enable approval workflows', 1, NOW()),
(1, 'tax_enabled', '1', 'boolean', 'Tax management enabled', 1, NOW()),
(1, 'backup_interval', '24', 'number', 'Automatic backup interval in hours', 1, NOW());

-- -------------------------------------------------------
-- End of Module 19 schema
-- -------------------------------------------------------