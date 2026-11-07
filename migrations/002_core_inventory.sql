-- 002_core_inventory.sql
-- Add the missing core structures required by inventory, stock valuation,
-- branch scoping, and sales/purchase line items.

CREATE TABLE IF NOT EXISTS branches (
    branch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(30) NULL,
    address TEXT NULL,
    phone VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_id),
    INDEX idx_branches_company (company_id),
    INDEX idx_branches_active (is_active),
    CONSTRAINT fk_branches_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Company branches';

INSERT INTO branches (company_id, name, code, is_active)
SELECT 1, 'Main Branch', 'MAIN', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE company_id = 1);

CREATE TABLE IF NOT EXISTS departments (
    department_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (department_id),
    INDEX idx_departments_company (company_id),
    CONSTRAINT fk_departments_company FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Company departments';

CREATE TABLE IF NOT EXISTS user_branch_access (
    user_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, branch_id),
    INDEX idx_user_branch_branch (branch_id),
    CONSTRAINT fk_uba_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_uba_branch FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Per-user branch access';

CREATE TABLE IF NOT EXISTS product_locations (
    location_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    product_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    on_hand_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    reserved_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    available_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    on_hand_value DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    last_counted DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (location_id),
    UNIQUE KEY uk_product_location (product_id, branch_id),
    INDEX idx_pl_branch (branch_id),
    INDEX idx_pl_company (company_id),
    CONSTRAINT fk_pl_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pl_branch FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Per-branch stock levels';

CREATE TABLE IF NOT EXISTS inventory_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    product_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    transaction_type ENUM('receiving','sale','adjustment','transfer_in','transfer_out') NOT NULL,
    quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    unit_cost DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    total_value DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    INDEX idx_invtx_product (product_id),
    INDEX idx_invtx_branch (branch_id),
    INDEX idx_invtx_type (transaction_type),
    INDEX idx_invtx_date (created_at),
    INDEX idx_invtx_reference (reference_type, reference_id),
    CONSTRAINT fk_invtx_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_invtx_branch FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_invtx_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Stock movement ledger';

CREATE TABLE IF NOT EXISTS sales_invoice_items (
    item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(19,4) NOT NULL DEFAULT 1.0000,
    unit_price DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    line_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    INDEX idx_sii_invoice (invoice_id),
    INDEX idx_sii_product (product_id),
    CONSTRAINT fk_sii_invoice FOREIGN KEY (invoice_id) REFERENCES sales_invoices(invoice_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sii_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Sales invoice line items';

CREATE TABLE IF NOT EXISTS purchase_order_items (
    item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(19,4) NOT NULL DEFAULT 1.0000,
    unit_price DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    received_quantity DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    line_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    INDEX idx_poi_order (purchase_order_id),
    INDEX idx_poi_product (product_id),
    CONSTRAINT fk_poi_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(purchase_order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Purchase order line items';
