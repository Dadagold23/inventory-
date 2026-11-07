-- ============================================================
-- 011: Cashier shifts + POS hardening (Phase C)
-- Register sessions with an opening float, cash movements and a
-- reconciliation trail; idempotency keys so a retried POS checkout
-- can never create a second sale; shift attribution on sales and
-- payments so cash can be proved against the drawer. Additive only.
-- All string columns use utf8mb4_unicode_ci (server convention).
-- ============================================================

CREATE TABLE IF NOT EXISTS cashier_shifts (
    shift_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    opening_float DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    expected_cash DECIMAL(19,4) NULL DEFAULT NULL,
    closing_count DECIMAL(19,4) NULL DEFAULT NULL,
    variance DECIMAL(19,4) NULL DEFAULT NULL,
    opening_note VARCHAR(255) NULL DEFAULT NULL,
    closing_note VARCHAR(255) NULL DEFAULT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    force_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shift_id),
    KEY idx_shifts_scope (company_id, branch_id, user_id, status),
    KEY idx_shifts_opened (company_id, opened_at),
    CONSTRAINT fk_shifts_company FOREIGN KEY (company_id) REFERENCES companies (company_id),
    CONSTRAINT fk_shifts_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_shifts_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Non-sales cash that moves the drawer: owner top-ups, cash refunds given
-- at the counter and payouts. Float adjustments are recorded here too so
-- Expected = Float + Cash Receipts + Paid In - Cash Refunds - Paid Out
-- can always be rebuilt from rows, never from a stored summary alone.
CREATE TABLE IF NOT EXISTS shift_movements (
    movement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('float_adjust','paid_in','paid_out','cash_refund') NOT NULL,
    amount DECIMAL(19,4) NOT NULL,
    reason VARCHAR(255) NULL DEFAULT NULL,
    reference_invoice_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (movement_id),
    KEY idx_movements_shift (shift_id, movement_type),
    CONSTRAINT fk_movements_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id) ON DELETE CASCADE,
    CONSTRAINT fk_movements_company FOREIGN KEY (company_id) REFERENCES companies (company_id),
    CONSTRAINT fk_movements_user FOREIGN KEY (created_by) REFERENCES users (user_id),
    CONSTRAINT fk_movements_invoice FOREIGN KEY (reference_invoice_id) REFERENCES sales_invoices (invoice_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales_invoices
    ADD COLUMN shift_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER branch_id,
    ADD COLUMN idempotency_key VARCHAR(64) NULL DEFAULT NULL AFTER created_by,
    ADD KEY idx_sales_shift (shift_id),
    ADD UNIQUE KEY uq_sales_idempotency (company_id, idempotency_key),
    ADD CONSTRAINT fk_sales_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id) ON DELETE SET NULL;

ALTER TABLE customer_payments
    ADD COLUMN shift_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER invoice_id,
    ADD KEY idx_payments_shift (shift_id),
    ADD CONSTRAINT fk_payments_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id) ON DELETE SET NULL;

-- role_permissions.role holds the slug matched against users.role, matching
-- the 007_rbac_permissions.sql convention (it is not the roles.name label).
INSERT IGNORE INTO permissions (name, description) VALUES
    ('shifts.view', 'View cashier shifts and reconciliation reports'),
    ('shifts.create', 'Open a cashier shift'),
    ('shifts.update', 'Record drawer movements and close a shift'),
    ('shifts.delete', 'Void a shift that has no activity');

INSERT IGNORE INTO role_permissions (role, permission) VALUES
('cashier', 'shifts.view'), ('cashier', 'shifts.create'), ('cashier', 'shifts.update'),
('staff', 'shifts.view'), ('staff', 'shifts.create'), ('staff', 'shifts.update'),
('manager', 'shifts.view'), ('manager', 'shifts.create'), ('manager', 'shifts.update'), ('manager', 'shifts.delete'),
('owner', 'shifts.view'), ('owner', 'shifts.create'), ('owner', 'shifts.update'), ('owner', 'shifts.delete'),
('sales_manager', 'shifts.view'), ('sales_manager', 'shifts.create'), ('sales_manager', 'shifts.update'),
('auditor', 'shifts.view'), ('accountant', 'shifts.view'), ('approver', 'shifts.view');
