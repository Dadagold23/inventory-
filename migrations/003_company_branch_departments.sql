-- 003: Company, branch and department compatibility fields.
-- Additive only: no data is removed or rewritten.

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER phone,
    ADD COLUMN IF NOT EXISTS fiscal_year_start TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER currency,
    ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL AFTER phone;

ALTER TABLE branches
    ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE departments
    ADD COLUMN IF NOT EXISTS branch_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN IF NOT EXISTS manager_id BIGINT UNSIGNED NULL AFTER branch_id,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER code,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

ALTER TABLE departments
    ADD KEY IF NOT EXISTS idx_departments_branch (branch_id),
    ADD KEY IF NOT EXISTS idx_departments_manager (manager_id);
