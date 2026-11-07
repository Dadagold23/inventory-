-- Module 1: Foundation and Authentication
-- Core database tables for user authentication and system access

-- --------------------------------------------------------
-- Table: permissions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    permission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT NULL,
    PRIMARY KEY (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Permission catalogue';

-- --------------------------------------------------------
-- Table: role_permissions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role VARCHAR(50) NOT NULL,
    permission VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uk_role_permission (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Role-permission mappings';

-- --------------------------------------------------------
-- Table: user_sessions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'User session tracking';

-- --------------------------------------------------------
-- End of Module 1 schema
-- -------------------------------------------------------