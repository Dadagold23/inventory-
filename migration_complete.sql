-- ============================================================
-- INVENTORY MANAGEMENT SYSTEM - COMPLETE SCHEMA MIGRATION
-- MySQL 8.0+ / MariaDB 10.5+
-- Apply in strict order: schema.sql → module1 → ... → module21
-- ============================================================

-- ============================================================
-- STEP 1: Foundation Schema (schema.sql)
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    INDEX idx_users_role (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'System users';

CREATE TABLE IF NOT EXISTS companies (
    company_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    tax_id VARCHAR(20) NULL,
    address TEXT NULL,
    phone VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    INDEX idx_companies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Company catalogue';

CREATE TABLE IF NOT EXISTS categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(80) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    INDEX idx_categories_company (company_id),
    INDEX idx_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(category_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Product/service categories';

INSERT IGNORE INTO companies (name, tax_id, address, phone) VALUES
('Inventory Management Co.', '123456789', '123 Business Ave, City, State', '555-0100');

INSERT IGNORE INTO users (username, email, password, role, is_active) VALUES
('admin', 'admin@inventory.com', 'hashed_password', 'admin', 1);

INSERT IGNORE INTO categories (name, parent_id, sort_order, is_active) VALUES
('General', NULL, 0, 1);

-- ============================================================
-- STEP 2: Module 4 - Products Table (with fixed foreign key)
-- ============================================================

CREATE TABLE IF NOT EXISTS products (
    product_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    category_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    track_inventory TINYINT(1) NOT NULL DEFAULT 1,
    manage_stock TINYINT(1) NOT NULL DEFAULT 1,
    sales_price DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    cost_price DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    reorder_level DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    maximum_level DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    target_level DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    UNIQUE KEY uk_products_sku (sku),
    INDEX idx_products_category (category_id),
    INDEX idx_products_active (is_active),
    INDEX idx_products_sku (sku),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_products_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Product catalogue';

INSERT IGNORE INTO products (company_id, category_id, name, sku, description, track_inventory, manage_stock, sales_price, cost_price, reorder_level, maximum_level, target_level, is_active, sort_order) VALUES
(1, 1, 'Widget Standard', 'WID-001', 'Standard widget for general use', 1, 1, 99.9000, 45.0000, 10.0000, 100.0000, 50.0000, 1, 0),
(1, 1, 'Widget Premium', 'WID-002', 'Premium widget with extended features', 1, 1, 149.9000, 85.0000, 10.0000, 50.0000, 25.0000, 1, 1);

-- ============================================================
-- STEP 3: Module 19 - System Settings Table
-- ============================================================

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

-- ============================================================
-- STEP 4: Module 21 - Customer Communications History
-- ============================================================

CREATE TABLE IF NOT EXISTS customer_communications (
    communication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    customer_id BIGINT UNSIGNED NOT NULL,
    contact_name VARCHAR(100) NULL,
    communication_type ENUM('email', 'phone', 'letter', 'sms') NOT NULL DEFAULT 'email',
    subject VARCHAR(200) NULL,
    body TEXT NULL,
    sent_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (communication_id),
    INDEX idx_communications_customer (customer_id),
    INDEX idx_communications_date (sent_date),
    CONSTRAINT fk_communications_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_communications_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Customer communication history';

INSERT IGNORE INTO customer_communications (company_id, customer_id, contact_name, communication_type, subject, body, sent_date, read, created_by) VALUES
(1, 1, 'John Doe', 'email', 'Order Confirmation', 'Your order has been shipped.', NOW(), 1, 1),
(1, 2, 'Jane Smith', 'phone', 'Payment Reminder', 'Payment overdue for invoice #123', NOW(), 0, 1);

-- ============================================================
-- MIGRATION COMPLETE - All critical schemas applied
-- ============================================================

-- Verify migration
SELECT 'Migration Complete' AS Status;
SELECT COUNT(*) AS TotalTables FROM information_schema.tables WHERE table_schema = 'inventory';
SELECT COUNT(*) AS ForeignKeys FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='inventory' AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Verify critical tables exist
SELECT 
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='inventory' AND table_name='users') AS UsersTable,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='inventory' AND table_name='companies') AS CompaniesTable,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='inventory' AND table_name='products') AS ProductsTable,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='inventory' AND table_name='system_settings') AS SettingsTable,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='inventory' AND table_name='customer_communications') AS CommunicationsTable;