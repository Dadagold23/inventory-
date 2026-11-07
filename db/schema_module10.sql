-- Module 10: Approval Workflow Engine
-- Extends Modules 1-9 with configurable multi-stage approval workflows

-- --------------------------------------------------------
-- Table: approval_workflows
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_workflows (
    workflow_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workflow_id),
    INDEX idx_workflows_company (company_id),
    INDEX idx_workflows_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Approval workflow definitions';

-- --------------------------------------------------------
-- Table: approval_workflow_steps
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_workflow_steps (
    step_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workflow_id BIGINT UNSIGNED NOT NULL,
    step_number SMALLINT UNSIGNED NOT NULL,
    role_required VARCHAR(50) NOT NULL,
    action ENUM('approve', 'reject', 'review') NOT NULL DEFAULT 'approve',
    notes TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (step_id),
    INDEX idx_steps_workflow (workflow_id),
    CONSTRAINT fk_steps_workflow FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Approval workflow steps';

-- --------------------------------------------------------
-- Table: approval_requests
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_requests (
    request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workflow_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    requester_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    submitted_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_date TIMESTAMP NULL,
    approval_count INT UNSIGNED NOT NULL DEFAULT 0,
    required_count INT UNSIGNED NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    INDEX idx_requests_workflow (workflow_id),
    INDEX idx_requests_entity (entity_type, entity_id),
    INDEX idx_requests_requester (requester_id),
    CONSTRAINT fk_requests_workflow FOREIGN KEY (workflow_id) REFERENCES approval_workflows(workflow_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_requests_requester FOREIGN KEY (requester_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Approval requests';

-- --------------------------------------------------------
-- Table: approval_actions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_actions (
    action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('approve', 'reject', 'review') NOT NULL,
    comments TEXT NULL,
    action_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (action_id),
    INDEX idx_actions_request (request_id),
    INDEX idx_actions_user (user_id),
    CONSTRAINT fk_actions_request FOREIGN KEY (request_id) REFERENCES approval_requests(request_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_actions_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Approval action history';

-- --------------------------------------------------------
-- Insert default workflows
-- --------------------------------------------------------
INSERT IGNORE INTO approval_workflows (company_id, name, description, is_active, sort_order) VALUES
(1, 'Expense Approval', 'Approval workflow for expenses', 1, 0),
(1, 'Purchase Order Approval', 'Approval workflow for purchase orders', 1, 1),
(1, 'Invoice Approval', 'Approval workflow for invoices', 1, 2),
(1, 'Sales Discount Approval', 'Approval workflow for sales discounts', 1, 3);

-- --------------------------------------------------------
-- Insert default workflow steps
-- --------------------------------------------------------
INSERT IGNORE INTO approval_workflow_steps (workflow_id, step_number, role_required, action, notes, sort_order) VALUES
(1, 1, 'manager', 'approve', 'Manager approval for expenses', 0),
(1, 2, 'admin', 'approve', 'Admin approval for amounts over threshold', 1),
(2, 1, 'manager', 'approve', 'Manager approval for POs', 0),
(3, 1, 'admin', 'approve', 'Admin approval for invoices', 0);

-- -------------------------------------------------------
-- End of Module 10 schema
-- -------------------------------------------------------