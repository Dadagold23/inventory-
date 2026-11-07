-- ============================================================
-- 009: Payment methods administration (Phase A) support
-- customer_payments.payment_method is a legacy ENUM of the four
-- hardcoded codes. Widen it to VARCHAR(50) so the configurable
-- payment methods introduced in migration 008 (payment_methods
-- table) can actually be stored by the sales module. Existing
-- ENUM values map 1:1 onto the same strings; no data changes.
-- Append-only: nothing dropped, column keeps its default.
-- ============================================================

ALTER TABLE customer_payments
    MODIFY COLUMN payment_method VARCHAR(50)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'bank_transfer';

ALTER TABLE payment_made
    MODIFY COLUMN payment_method VARCHAR(50)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'bank_transfer';
