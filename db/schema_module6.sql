-- Module 6: Accounts Payable
-- Extends foundation with vendor and purchase order tracking

-- --------------------------------------------------------
-- Table: vendors
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS vendors (
    vendor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    tax_id VARCHAR(20) NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (vendor_id),
    INDEX idx_vendors_company (company_id),
    INDEX idx_vendors_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Vendor catalogue';

-- --------------------------------------------------------
-- Table: purchase_orders
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    purchase_order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    vendor_id BIGINT UNSIGNED NOT NULL,
    po_number VARCHAR(50) NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    total_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    status ENUM('pending', 'partial', 'received', 'completed') NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (purchase_order_id),
    INDEX idx_purchase_orders_vendor (vendor_id),
    INDEX idx_purchase_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Purchase orders';

-- --------------------------------------------------------
-- End of Module 6 schema
-- -------------------------------------------------------