-- ============================================================
-- 013: Gateway provider record id (Phase D)
-- A refund has to name the provider's own numeric transaction id,
-- not our merchant reference, so the verification response's id is
-- kept beside the reference instead of being re-fetched per refund.
-- ============================================================

ALTER TABLE gateway_transactions
    ADD COLUMN provider_id VARCHAR(64) NULL DEFAULT NULL AFTER gateway_reference;
