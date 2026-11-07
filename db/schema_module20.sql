-- Module 20: Dashboard & Analytics
-- Extends Modules 1-19 with dashboard widgets and analytics data

-- --------------------------------------------------------
-- Table: dashboard_widgets
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_widgets (
    widget_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    widget_name VARCHAR(80) NOT NULL,
    widget_type ENUM('summary', 'chart', 'table', 'gauge', 'metric') NOT NULL DEFAULT 'summary',
    config_data TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (widget_id),
    INDEX idx_widgets_company (company_id),
    INDEX idx_widgets_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Dashboard widgets';

-- --------------------------------------------------------
-- Table: dashboard_config
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_config (
    config_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    user_id BIGINT UNSIGNED NOT NULL,
    layout_config TEXT NULL,
    widget_order TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (config_id),
    INDEX idx_config_user (user_id),
    INDEX idx_config_company (company_id),
    CONSTRAINT fk_config_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Dashboard user configurations';

-- --------------------------------------------------------
-- End of Module 20 schema
-- --------------------------------------------------------