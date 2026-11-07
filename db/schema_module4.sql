-- Module 4: Inventory and Sales
-- Extends foundation schema with product and sales tracking

-- --------------------------------------------------------
-- Table: products
-- --------------------------------------------------------
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

-- --------------------------------------------------------
-- Insert default products
-- --------------------------------------------------------
INSERT IGNORE INTO products (company_id, category_id, name, sku, description, track_inventory, manage_stock, sales_price, cost_price, reorder_level, maximum_level, target_level, is_active, sort_order) VALUES
(1, 1, 'Widget Standard', 'WID-001', 'Standard widget for general use', 1, 1, 99.9000, 45.0000, 10.0000, 100.0000, 50.0000, 1, 0),
(1, 1, 'Widget Premium', 'WID-002', 'Premium widget with extended features', 1, 1, 149.9000, 85.0000, 10.0000, 50.0000, 25.0000, 1, 1);