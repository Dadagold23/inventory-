-- Module 15: Payroll Management
-- Extends Modules 1-14 with employee and payroll processing

-- --------------------------------------------------------
-- Table: employees
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    employee_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    user_id BIGINT UNSIGNED NULL,
    employee_code VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    hire_date DATE NOT NULL,
    termination_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    employment_type ENUM('full_time', 'part_time', 'contract') NOT NULL DEFAULT 'full_time',
    hourly_rate DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    salary DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    tax_id VARCHAR(20) NULL,
    address TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id),
    INDEX idx_employees_company (company_id),
    INDEX idx_employees_active (is_active),
    UNIQUE KEY uk_employees_code (employee_code),
    CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Employee catalogue';

-- --------------------------------------------------------
-- Table: pay_rates
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS pay_rates (
    pay_rate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    rate_type ENUM('hourly', 'salary', 'commission') NOT NULL DEFAULT 'hourly',
    rate_amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    effective_date DATE NOT NULL DEFAULT CURRENT_DATE,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pay_rate_id),
    INDEX idx_payrates_employee (employee_id),
    CONSTRAINT fk_payrates_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Employee pay rates history';

-- --------------------------------------------------------
-- Table: payroll_runs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_runs (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    run_date DATE NOT NULL DEFAULT CURRENT_DATE,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('draft', 'processed', 'reversed') NOT NULL DEFAULT 'draft',
    gross_total DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    tax_total DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    net_total DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (run_id),
    INDEX idx_payrolls_company (company_id),
    INDEX idx_payrolls_status (status),
    CONSTRAINT fk_payrolls_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Payroll runs';

-- --------------------------------------------------------
-- Table: payroll_run_lines
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_run_lines (
    line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(100) NOT NULL,
    type ENUM('earning', 'deduction', 'tax') NOT NULL DEFAULT 'earning',
    amount DECIMAL(19, 4) NOT NULL DEFAULT 0.0000,
    PRIMARY KEY (line_id),
    INDEX idx_runlines_run (run_id),
    INDEX idx_runlines_employee (employee_id),
    CONSTRAINT fk_runlines_run FOREIGN KEY (run_id) REFERENCES payroll_runs(run_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_runlines_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Payroll run line items';

-- --------------------------------------------------------
-- End of Module 15 schema
-- --------------------------------------------------------