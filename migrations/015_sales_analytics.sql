-- ============================================================
-- 015: Sales analytics (Phase E)
--
-- The report catalogue's report_type is an ENUM, so a new statement has to be
-- allowed to exist before it can be saved. 'sales_analytics' is the operational
-- counterpart to 'income_statement': the income statement answers "what did the
-- business earn" from income records and expenses, while this answers "what sold,
-- at what margin, by whom and where" from invoice lines and payment rows. Same
-- period columns, same status, no new table: every number is derived at read
-- time from records that already exist, so nothing here can drift from them.
-- ============================================================

ALTER TABLE financial_reports
    MODIFY COLUMN report_type ENUM('income_statement','balance_sheet','cash_flow','trial_balance','general_ledger','sales_analytics')
        NOT NULL;
