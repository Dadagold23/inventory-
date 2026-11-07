-- 001_auth_core.sql
-- Align the live users table with the authentication service.
-- The live column is `password`; the application and docs use `password_hash`.

ALTER TABLE users
    CHANGE COLUMN password password_hash VARCHAR(255) NOT NULL,
    ADD COLUMN full_name VARCHAR(100) NULL AFTER email,
    ADD COLUMN company_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN last_login DATETIME NULL,
    ADD COLUMN last_ip_address VARCHAR(45) NULL,
    ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN lock_until DATETIME NULL,
    ADD COLUMN password_changed_at DATETIME NULL,
    ADD COLUMN password_reset_token VARCHAR(64) NULL,
    ADD COLUMN password_reset_expires DATETIME NULL,
    ADD INDEX idx_users_company (company_id);

UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = '';
