-- ============================================================
-- 014: Receipt printing (Phase E)
--
-- 1. What a customer handed over has never been stored. customer_payments.amount
--    is the amount *applied* to the bill - the rule that keeps a drawer balanced
--    when change is handed back - so the overpay and therefore the change existed
--    only in $_SESSION['pos_change_due'] for the seconds after a sale. A reprinted
--    slip could not show it. tendered_amount records the note that came across the
--    counter; the change is derived from it, never stored twice.
-- 2. 'PRINT' joins the audit action enum. Printing a receipt is an act with an
--    evidence trail (who reprinted which slip, in what format, on what paper),
--    which 'READ' cannot be queried for and 'EXPORT' does not describe.
-- ============================================================

ALTER TABLE customer_payments
    ADD COLUMN tendered_amount DECIMAL(19,4) NULL DEFAULT NULL
        COMMENT 'Cash or tender handed over for this payment row; NULL when unknown. amount stays the sum applied to the bill'
        AFTER amount;

ALTER TABLE audit_log
    MODIFY COLUMN action ENUM('CREATE','READ','UPDATE','DELETE','LOGIN','LOGOUT','EXPORT','IMPORT','PRINT')
        NOT NULL;
