-- ============================================================
-- 016: QR Code Scan-to-Pay (Phase F)
--
-- A QR pay point is a printable/displayable code that a customer
-- scans to reach the merchant's payment destination. It belongs to
-- one company, may be limited to one branch and one tender, and is
-- either an open-amount code (the customer enters the amount) or a
-- fixed-amount code (the amount is embedded in the QR payload).
--
-- The QR itself is rendered on demand from `scan_url` by
-- includes/barcode_lib.php; no image bytes are stored, so a point
-- can never drift from the link it points at. Points are never
-- silently rewritten: an inactive point stays in the list so a
-- printed code can still be identified later.
-- Additive only.
-- ============================================================

CREATE TABLE IF NOT EXISTS qr_payment_points (
    qr_point_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    payment_method_id BIGINT UNSIGNED NULL DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(40) NOT NULL,
    merchant_reference VARCHAR(120) NULL DEFAULT NULL,
    scan_url VARCHAR(500) NOT NULL
        COMMENT 'Destination encoded in the QR: an https payment link or another URI scheme',
    amount_type ENUM('open','fixed') NOT NULL DEFAULT 'open',
    amount DECIMAL(19,4) NULL DEFAULT NULL
        COMMENT 'Amount embedded in the QR for a fixed-amount point; NULL for an open-amount point',
    ecc ENUM('L','M','Q','H') NOT NULL DEFAULT 'M'
        COMMENT 'QR error-correction level',
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (qr_point_id),
    UNIQUE KEY uq_qr_point_company_code (company_id, code),
    KEY idx_qr_point_company_active (company_id, is_active, sort_order),
    KEY idx_qr_point_branch (branch_id),
    KEY idx_qr_point_method (payment_method_id),
    CONSTRAINT fk_qr_point_company FOREIGN KEY (company_id) REFERENCES companies (company_id) ON DELETE CASCADE,
    CONSTRAINT fk_qr_point_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id) ON DELETE SET NULL,
    CONSTRAINT fk_qr_point_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id) ON DELETE SET NULL,
    CONSTRAINT fk_qr_point_created_by FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_qr_point_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission catalogue. `view` is granted broadly because the list
-- doubles as the register's reference sheet; only administration
-- roles may create or change a point.
INSERT IGNORE INTO permissions (name, description) VALUES
    ('qr_payments.view', 'View QR scan-to-pay points and their codes'),
    ('qr_payments.create', 'Create QR scan-to-pay points'),
    ('qr_payments.update', 'Edit, activate and deactivate QR scan-to-pay points'),
    ('qr_payments.delete', 'Delete QR scan-to-pay points');

INSERT IGNORE INTO role_permissions (role, permission) VALUES
    ('owner', 'qr_payments.view'),
    ('owner', 'qr_payments.create'),
    ('owner', 'qr_payments.update'),
    ('owner', 'qr_payments.delete'),
    ('manager', 'qr_payments.view'),
    ('manager', 'qr_payments.create'),
    ('manager', 'qr_payments.update'),
    ('accountant', 'qr_payments.view'),
    ('sales_manager', 'qr_payments.view'),
    ('sales_manager', 'qr_payments.create'),
    ('sales_manager', 'qr_payments.update'),
    ('cashier', 'qr_payments.view'),
    ('staff', 'qr_payments.view'),
    ('storekeeper', 'qr_payments.view'),
    ('inventory_manager', 'qr_payments.view'),
    ('procurement', 'qr_payments.view'),
    ('approver', 'qr_payments.view'),
    ('auditor', 'qr_payments.view');
