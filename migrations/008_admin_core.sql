-- ============================================================
-- 008: Administration foundations
-- Roles table, user/role/payment-method permissions,
-- configurable payment methods. Additive only; no data dropped.
-- ============================================================

-- 1. Configurable roles (existing enum values become system roles)
CREATE TABLE IF NOT EXISTS roles (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff';

INSERT IGNORE INTO roles (role_key, name, description, is_system) VALUES
('admin',             'Super Administrator',  'Full access to all modules and administration', 1),
('owner',             'Business Owner / Director', 'Business-wide access excluding user deletion and role configuration', 0),
('manager',           'General Manager',      'Inventory, sales, expenses, income and approvals', 1),
('inventory_manager', 'Inventory Manager',    'Inventory, purchasing and stock reporting', 0),
('storekeeper',       'Storekeeper',          'Stock receiving, issuing and adjustments', 0),
('cashier',           'Cashier',              'POS sales and payment entry', 0),
('sales_manager',     'Sales Manager',        'Sales, customers, quotes and sales analytics', 0),
('accountant',        'Accountant',           'Ledger, tax, banking, reports and reconciliation', 0),
('procurement',       'Procurement Officer',  'Suppliers, purchase orders and requisitions', 0),
('approver',          'Approving Officer',    'Approval queues and decisions', 0),
('auditor',           'Auditor',              'Read-only review of records, reports and audit trail', 0),
('staff',             'Staff',                'Day-to-day inventory and sales entry', 1);

-- 2. Configurable payment methods
CREATE TABLE IF NOT EXISTS payment_methods (
    payment_method_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    is_cash TINYINT(1) NOT NULL DEFAULT 0,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_method_id),
    UNIQUE KEY uq_payment_methods_company_code (company_id, code),
    KEY idx_payment_methods_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payment_methods (company_id, code, name, is_cash, is_online, sort_order) VALUES
(1, 'cash',          'Cash',               1, 0, 10),
(1, 'bank_transfer', 'Bank Transfer',      0, 0, 20),
(1, 'credit_card',   'Card (POS terminal)', 0, 0, 30),
(1, 'check',         'Cheque',             0, 0, 40),
(1, 'credit_sale',   'Credit Sale (on account)', 0, 0, 50);

-- 3. Administration permissions
INSERT IGNORE INTO permissions (name, description) VALUES
('users.view',           'View users and their role/branch assignments'),
('users.create',         'Create user accounts'),
('users.update',         'Edit users, roles, branch access and permission overrides'),
('users.delete',         'Deactivate user accounts'),
('users.reset_password', 'Reset another user''s password'),
('roles.view',           'View roles and permission grants'),
('roles.update',         'Create roles and change role permission grants'),
('payment_methods.view', 'View configured payment methods'),
('payment_methods.update', 'Create, edit and deactivate payment methods');

-- 4. Grants for the new administration permissions
INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'manager', name FROM permissions
WHERE name IN ('users.view','users.create','users.update','roles.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'staff', name FROM permissions WHERE name = 'payment_methods.view';

-- 5. Sensible starting grants for the new operational roles
INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'cashier', name FROM permissions WHERE name IN (
 'dashboard.view','inventory.view','sales.view','sales.create','payments.view','payments.create',
 'receivables.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'storekeeper', name FROM permissions WHERE name IN (
 'dashboard.view','inventory.view','inventory.create','inventory.update','payables.view',
 'approvals.view','approvals.create','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'inventory_manager', name FROM permissions WHERE name IN (
 'dashboard.view','inventory.view','inventory.create','inventory.update','inventory.delete',
 'payables.view','payables.create','payables.update','reports.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'sales_manager', name FROM permissions WHERE name IN (
 'dashboard.view','sales.view','sales.create','sales.update','sales.delete',
 'receivables.view','receivables.create','receivables.update','payments.view','payments.create',
 'inventory.view','reports.view','reports.export','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'accountant', name FROM permissions WHERE name IN (
 'dashboard.view','ledger.view','ledger.create','ledger.update','tax.view','tax.create','tax.update',
 'bank.view','bank.create','bank.update','reports.view','reports.create','reports.export',
 'expenses.view','income.view','payments.view','payments.create','payroll.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'procurement', name FROM permissions WHERE name IN (
 'dashboard.view','payables.view','payables.create','payables.update','inventory.view',
 'approvals.view','approvals.create','expenses.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'approver', name FROM permissions WHERE name IN (
 'dashboard.view','approvals.view','approvals.approve','inventory.view','sales.view',
 'expenses.view','payables.view','payment_methods.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'auditor', name FROM permissions WHERE name LIKE '%.view'
   OR name IN ('reports.export','audit.view');

INSERT IGNORE INTO role_permissions (role, permission)
SELECT 'owner', name FROM permissions WHERE name NOT IN ('users.delete','roles.update','settings.delete');
