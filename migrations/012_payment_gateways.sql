-- ============================================================
-- 012: Payment gateways (Phase D)
-- Paystack + Flutterwave configuration, the online payment
-- record that stands between "customer paid" and "sale posted",
-- the webhook journal and the refund trail. Credentials are
-- stored encrypted (see includes/crypto_functions.php) and are
-- never rendered to a browser. Additive only, plus one index
-- repair that finally lets non-default companies keep their own
-- application settings.
-- All string columns use utf8mb4_unicode_ci (server convention).
-- ============================================================

-- One configuration row per company per gateway. test_* and live_*
-- hold ciphertext; switching mode selects the other pair, so an
-- administrator can move a company between sandbox and production
-- without retyping keys.
CREATE TABLE IF NOT EXISTS payment_gateways (
    gateway_config_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('paystack','flutterwave') NOT NULL,
    mode ENUM('test','live') NOT NULL DEFAULT 'test',
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sandbox_allowed TINYINT(1) NOT NULL DEFAULT 1,
    test_public_key VARCHAR(1000) NULL DEFAULT NULL,
    test_secret_key VARCHAR(1000) NULL DEFAULT NULL,
    test_webhook_secret VARCHAR(1000) NULL DEFAULT NULL,
    live_public_key VARCHAR(1000) NULL DEFAULT NULL,
    live_secret_key VARCHAR(1000) NULL DEFAULT NULL,
    live_webhook_secret VARCHAR(1000) NULL DEFAULT NULL,
    last_checked_at DATETIME NULL DEFAULT NULL,
    last_check_result VARCHAR(255) NULL DEFAULT NULL,
    updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (gateway_config_id),
    UNIQUE KEY uq_gateway_company (company_id, gateway),
    CONSTRAINT fk_gateway_company FOREIGN KEY (company_id) REFERENCES companies (company_id),
    CONSTRAINT fk_gateway_user FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The payment attempt. A row is created when the cashier/customer is
-- sent to the gateway and only ever gains an invoice_id after a
-- server-side verification says the money arrived, which is what makes
-- "a redirect is not proof of payment" a structural rule rather than a
-- convention. payload holds the cart snapshot the sale is rebuilt from,
-- provider_response the last verification body for audit.
CREATE TABLE IF NOT EXISTS gateway_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('paystack','flutterwave') NOT NULL,
    mode ENUM('test','live','sandbox') NOT NULL DEFAULT 'test',
    reference VARCHAR(64) NOT NULL,
    gateway_reference VARCHAR(128) NULL DEFAULT NULL,
    checkout_url VARCHAR(255) NULL DEFAULT NULL,
    invoice_id BIGINT UNSIGNED NULL DEFAULT NULL,
    branch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    shift_id BIGINT UNSIGNED NULL DEFAULT NULL,
    user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    customer_email VARCHAR(120) NULL DEFAULT NULL,
    amount DECIMAL(19,4) NOT NULL DEFAULT 0.0000,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    status ENUM('pending','paid','failed','expired','refunded','partial_refund') NOT NULL DEFAULT 'pending',
    channel VARCHAR(40) NULL DEFAULT NULL,
    authorization_code VARCHAR(100) NULL DEFAULT NULL,
    failure_reason VARCHAR(255) NULL DEFAULT NULL,
    simulates_payment TINYINT(1) NOT NULL DEFAULT 0,
    payload TEXT NULL,
    provider_response TEXT NULL,
    verified_at DATETIME NULL DEFAULT NULL,
    finalised_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uq_gateway_reference (company_id, reference),
    KEY idx_gateway_txn_status (company_id, status, created_at),
    KEY idx_gateway_txn_gateway_ref (gateway, gateway_reference),
    KEY idx_gateway_txn_invoice (invoice_id),
    CONSTRAINT fk_gateway_txn_company FOREIGN KEY (company_id) REFERENCES companies (company_id),
    CONSTRAINT fk_gateway_txn_invoice FOREIGN KEY (invoice_id) REFERENCES sales_invoices (invoice_id) ON DELETE SET NULL,
    CONSTRAINT fk_gateway_txn_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id) ON DELETE SET NULL,
    CONSTRAINT fk_gateway_txn_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id) ON DELETE SET NULL,
    CONSTRAINT fk_gateway_txn_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook journal. Every callback is stored exactly as it arrived,
-- signed or not, and dedupe_key makes a redelivered event a no-op:
-- UNIQUE prevents the same event from finalising a sale twice.
CREATE TABLE IF NOT EXISTS gateway_events (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('paystack','flutterwave') NOT NULL,
    provider_event_id VARCHAR(80) NULL DEFAULT NULL,
    dedupe_key CHAR(64) NOT NULL,
    event_type VARCHAR(60) NOT NULL DEFAULT '',
    transaction_ref VARCHAR(64) NULL DEFAULT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    result VARCHAR(255) NULL DEFAULT NULL,
    payload TEXT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    UNIQUE KEY uq_gateway_event_dedupe (dedupe_key),
    KEY idx_gateway_events_company (company_id, gateway, received_at),
    CONSTRAINT fk_gateway_events_company FOREIGN KEY (company_id) REFERENCES companies (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Refunds are documents of their own: the invoice stays as paid, the
-- money leaving is recorded here and never rewritten into history.
CREATE TABLE IF NOT EXISTS gateway_refunds (
    refund_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    gateway ENUM('paystack','flutterwave') NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    refund_reference VARCHAR(64) NOT NULL,
    provider_refund_reference VARCHAR(128) NULL DEFAULT NULL,
    amount DECIMAL(19,4) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    status ENUM('pending','successful','failed') NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) NULL DEFAULT NULL,
    simulates_payment TINYINT(1) NOT NULL DEFAULT 0,
    provider_response TEXT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (refund_id),
    UNIQUE KEY uq_gateway_refund_reference (company_id, refund_reference),
    KEY idx_gateway_refunds_txn (transaction_id, status),
    CONSTRAINT fk_gateway_refunds_company FOREIGN KEY (company_id) REFERENCES companies (company_id),
    CONSTRAINT fk_gateway_refunds_txn FOREIGN KEY (transaction_id) REFERENCES gateway_transactions (transaction_id),
    CONSTRAINT fk_gateway_refunds_user FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An online payment method must name the gateway that will actually
-- take the money, so the POS never offers "Card (online)" with no
-- provider behind it.
ALTER TABLE payment_methods
    ADD COLUMN gateway ENUM('paystack','flutterwave') NULL DEFAULT NULL AFTER is_online;

-- Repair: settings.key carried its own UNIQUE index from the original
-- schema while the company-scoped uk_settings_company_key index is the
-- intended rule. With the bare UNIQUE in place, the second company to
-- save any application setting failed with "Duplicate entry ... for key
-- 'key'". idx_settings_key still covers the lookup.
ALTER TABLE settings DROP INDEX `key`;

-- role_permissions.role holds the slug matched against users.role.
INSERT IGNORE INTO permissions (name, description) VALUES
    ('gateways.view', 'View payment gateway configuration, transactions and reconciliation'),
    ('gateways.update', 'Configure gateway credentials, mode and enablement'),
    ('gateways.verify', 'Re-verify a payment against the gateway and run reconciliation'),
    ('gateways.refund', 'Issue gateway refunds');

INSERT IGNORE INTO role_permissions (role, permission) VALUES
('owner', 'gateways.view'), ('owner', 'gateways.update'), ('owner', 'gateways.verify'), ('owner', 'gateways.refund'),
('manager', 'gateways.view'), ('manager', 'gateways.verify'), ('manager', 'gateways.refund'),
('accountant', 'gateways.view'), ('accountant', 'gateways.verify'), ('accountant', 'gateways.refund'),
('approver', 'gateways.view'), ('auditor', 'gateways.view');
