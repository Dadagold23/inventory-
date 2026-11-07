-- Expense supplier link is optional: expenses can be recorded without a vendor.
ALTER TABLE expenses
    MODIFY COLUMN vendor_id BIGINT UNSIGNED NULL DEFAULT NULL;
