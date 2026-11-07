# Database Schema and Migration Status

**RDBMS:** MariaDB 10.4.32 (XAMPP)
**Database:** `inventory`
**Character set:** `utf8mb4` / `utf8mb4_unicode_ci`
**Engine:** InnoDB
**Updated:** 2026-11-07

---

## 1. Canonical table inventory

### Identity, company and access

| Table | Purpose |
|---|---|
| `companies` | Company profile, currency, fiscal year start |
| `users` | Accounts (`password_hash`, role, company, lockout fields) |
| `user_sessions` | Optional server-side session records |
| `permissions`, `role_permissions`, `user_permissions` | RBAC definitions and overrides |
| `user_branch_access` | Branch scoping per user |
| `branches`, `departments` | Organisational structure (`name`, `code`, `is_active`, `sort_order`) |
| `settings` | Authoritative per-company key/value settings (`key`, `value`, `company_id`); application settings and sales settings read/write here |
| `zz_deprecated_company_settings` | Former `company_settings`, renamed 2026-11-05 after it was found to be empty; kept for rollback, safe to drop once no release reads it |

### Inventory and purchasing

| Table | Purpose |
|---|---|
| `categories` | Product categories |
| `products` | Product master data and valuation flags, incl. the unique `barcode` (primary scannable code) |
| `product_barcodes` | Alternate codes per product: `code`, `symbology` (ean13/ean8/upc_a/code128/qr/other), `source` (system = in-store 2xx, gs1 = supplier-allocated, manual, import); unique `(code, company_id)` |
| `product_locations` | On-hand, reserved and available quantity/value per branch |
| `inventory_transactions` | Append-only stock movement audit |
| `vendors` | Suppliers (this is the canonical supplier table; there is no `suppliers` table) |
| `purchase_orders`, `purchase_order_items` | Purchase orders and lines |
| `invoice_receipts` | Vendor invoices/receipts for received purchases (`invoice_receipt_id` PK) |

### Sales and receivables

| Table | Purpose |
|---|---|
| `customers` | Customer master data and credit limits |
| `sales_invoices`, `sales_invoice_items` | Sales invoices and lines. `sales_invoices` also carries `shift_id` (drawer the sale was taken on, NULL for counter sales) and `idempotency_key` with unique `(company_id, idempotency_key)` so a retried POS POST cannot create a second sale |
| `customer_payments` | Payments against sales invoices; `shift_id` attributes each tender to a drawer, `amount` is the amount **applied** to the invoice, never the cash handed over |
| `quotes`, `quote_lines` | Quotations |
| `customer_statements` | Generated customer statements |
| `customer_communications` | Customer contact log |

### Cash drawer (migration 011)

| Table | Purpose |
|---|---|
| `cashier_shifts` | One register session per `(company_id, branch_id, user_id)`: `status` enum `open`/`closed`, `opening_float`, `expected_cash`, `closing_count`, `variance`, notes, `opened_at`/`closed_at`, `force_closed`. Indexed by `idx_shifts_scope` |
| `shift_movements` | Append-only drawer movements: `movement_type` enum `float_adjust`/`paid_in`/`paid_out`/`cash_refund`, `amount`, `reason`, `reference_invoice_id`, `created_by`. The opening float lives on the shift row, not here, so it is counted once |

Expected cash is never trusted from storage: `shiftTotals()` rebuilds it on every read as
`Float + Cash Receipts + Paid In − Cash Refunds − Paid Out`, where cash receipts are
`customer_payments` rows on a method flagged `payment_methods.is_cash`.

### Payment gateways (migrations 012–013)

| Table | Purpose |
|---|---|
| `payment_gateways` | One row per `(company_id, gateway)` — `gateway` enum `paystack`/`flutterwave`, `mode` enum `test`/`live`, `is_enabled`, `sandbox_allowed`, six credential columns, `last_checked_at`/`last_check_result`, `updated_by`. Unique `uq_gateway_company` |
| `gateway_transactions` | The payment attempt: `reference` (unique per company), `gateway_reference`, `provider_id`, `checkout_url`, `invoice_id`, `branch_id`, `shift_id`, `user_id`, `customer_email`, `amount`/`currency`, `status` enum `pending`/`paid`/`failed`/`expired`/`refunded`/`partial_refund`, `channel`, `authorization_code`, `failure_reason`, `simulates_payment`, `payload` (cart snapshot), `provider_response`, `verified_at`/`finalised_at`. `mode` also admits `sandbox` |
| `gateway_events` | Verified provider callbacks: `provider_event_id`, `event_type`, `transaction_ref`, `signature_valid`, `processed`, `result`, `payload`, and `dedupe_key CHAR(64)` with `uq_gateway_event_dedupe`, which is what makes a redelivery a no-op. A body that fails the signature check is answered 401 and **never stored**, so `signature_valid` is written as `1` by `gatewayHandleWebhook()` — the column exists to make that fact explicit per row rather than an assumption about the table |
| `gateway_refunds` | A refund is a document of its own: `transaction_id`, `refund_reference` (unique per company), `provider_refund_reference`, `amount`, `currency`, `status` enum `pending`/`successful`/`failed`, `reason`, `simulates_payment`, `provider_response`, `created_by` |

`payment_methods` gained `gateway ENUM('paystack','flutterwave') NULL` so an online tender
names the provider that will actually take the money; a method flagged online with no
enabled provider is not offered to the till (`gatewayOnlineMethods()` drops it).

Nothing in `payment_gateways` is a secret in the plain: `test_*` and `live_*` columns hold
`v2:<iv>:<tag>:<ciphertext>` produced by `secretEncrypt()` (AES-256-GCM under `APP_KEY`, or a
minted key under `storage/keys/` which the web server is told to refuse). Switching `mode`
selects the other triple, so moving between sandbox and production does not retype keys.

`gateway_transactions.invoice_id` is NULL until a server-side verification says the money
arrived, and `uq_gateway_reference` plus an invoice `idempotency_key` of `gw:<reference>`
mean the same payment cannot post twice. The FK is `ON DELETE SET NULL`: deleting a sale leaves
the payment record telling the story, and the reverse — deleting the payment — never takes a
posted invoice with it.

### QR scan-to-pay points (migration 016)

| Table | Purpose |
|---|---|
| `qr_payment_points` | One printable/displayable QR pay point per row: `company_id`, optional `branch_id` and `payment_method_id`, `name`, unique-per-company `code`, `merchant_reference`, `scan_url` (the destination encoded in the QR), `amount_type` enum `open`/`fixed`, `amount` (only for fixed), `ecc` enum `L/M/Q/H`, `description`, `is_active`, `sort_order`, `created_by`/`updated_by`, timestamps |

A pay point is **not** a payment record. It describes the sign a customer scans; the payment itself is taken by whatever provider or bank sits behind `scan_url`. No QR image bytes are stored: `includes/barcode_lib.php` renders the SVG on demand from the stored destination, so a reprint can never disagree with the row.

### Income, expenses and payments

| Table | Purpose |
|---|---|
| `income_records` | Income entries (the application table is `income_records`, not `income`) |
| `income_sources` | Income categories (`income_source_id` PK) |
| `expenses`, `expense_categories` | Expenses and categories |
| `payment_made` | Standalone payments to vendors |

Customer payments received through the `payment_received` module are stored in `customer_payments`; there is no `payment_received` table.

### Banking, accounting and reporting

| Table | Purpose |
|---|---|
| `banks`, `bank_transactions` | Bank accounts and transaction ledger with `balance_after` |
| `chart_of_accounts` | Account types: `asset`, `liability`, `equity`, `income`, `expense` |
| `journal_entries`, `journal_entry_lines` | Double-entry journal (`posted_date`, `debit_amount`, `credit_amount`) |
| `financial_reports`, `report_columns` | Saved report definitions and columns |
| `tax_rates`, `tax_transactions` | Tax configuration and transactions |
| `employees`, `pay_rates`, `payroll_runs`, `payroll_run_lines` | Payroll |
| `approval_workflows`, `approval_workflow_steps`, `approval_requests`, `approval_actions` | Approval workflow |
| `settings`, `system_settings`, `dashboard_widgets`, `dashboard_config` | Configuration |
| `schema_migrations` | Applied migration tracking |
| `audit_log` | Security and change audit trail |

## 2. Key relationships

- `users.company_id → companies.company_id`; `users.branch_id → branches.branch_id`.
- `branches.company_id → companies.company_id`; `departments.branch_id → branches.branch_id`; `departments.manager_id → users.user_id`.
- `products.company_id → companies.company_id`; `categories.company_id → companies.company_id`.
- `product_locations.product_id → products.product_id`; `product_locations.branch_id → branches.branch_id`.
- `sales_invoices.customer_id → customers.customer_id`; `sales_invoice_items.invoice_id → sales_invoices.invoice_id`.
- `customer_payments.invoice_id → sales_invoices.invoice_id`.
- `cashier_shifts.(company_id, branch_id, user_id)` → `companies`, `branches`, `users`; `shift_movements.shift_id → cashier_shifts.shift_id` (CASCADE — movements belong to the drawer, not to history), `shift_movements.created_by → users.user_id`.
- `sales_invoices.shift_id → cashier_shifts.shift_id` (SET NULL) and `customer_payments.shift_id → cashier_shifts.shift_id` (SET NULL): closing or voiding a drawer never deletes a sale.
- `payment_gateways.(company_id, updated_by)` → `companies`, `users` (RESTRICT — a credential row keeps the account that wrote it).
- `gateway_transactions.company_id → companies`; `invoice_id → sales_invoices` (SET NULL), `branch_id → branches` (SET NULL), `shift_id → cashier_shifts` (SET NULL), `user_id → users` (SET NULL): a payment attempt outlives every one of those rows.
- `gateway_refunds.transaction_id → gateway_transactions.transaction_id` (RESTRICT) and `created_by → users.user_id` (SET NULL); `gateway_events.company_id → companies.company_id`.
- `qr_payment_points.company_id → companies.company_id` (CASCADE); `branch_id → branches.branch_id` (SET NULL), `payment_method_id → payment_methods.payment_method_id` (SET NULL), `created_by`/`updated_by → users.user_id` (SET NULL). A point outlives a retired branch or tender rather than disappearing with it.
- `journal_entry_lines.entry_id → journal_entries.entry_id`; `journal_entry_lines.account_id → chart_of_accounts.account_id`.
- `bank_transactions.bank_id → banks.bank_id`.
- `approval_requests.workflow_id → approval_workflows.workflow_id`; `approval_requests.requester_id → users.user_id`; `approval_actions.request_id → approval_requests.request_id`.
- `audit_log.user_id → users.user_id`.

## 3. Important column notes

- `users` uses `password_hash` (migration 001 renamed the legacy `password` column).
- `companies` stores `currency`; reporting formats amounts through `formatPrice()`.
- `journal_entries` uses `posted_date`; `journal_entry_lines` uses `debit_amount` / `credit_amount`.
- `financial_reports` uses `report_name`, `report_type`, `period_start`, `period_end`, `generated_by`, `generated_at`, and status `draft|generated|exported`. There is no `updated_at` column. `report_type` is an ENUM; migration 015 added `sales_analytics` beside the four accounting statements. That report stores no figures of its own - every number is recomputed from `sales_invoices`, `sales_invoice_items` and `customer_payments` when it is opened, so it cannot drift from the ledger.
- `customer_payments.amount` is the money applied to the bill; `tendered_amount` (migration 014) is what the customer handed over, which is how a reprinted receipt still shows the change. `tendered_amount` may be NULL for rows written before the column existed.
- Sales analytics treats `pending`, `partial` and `paid` as revenue (`salesRevenueStatusSql()`); `draft` and `cancelled` are excluded from both the billing and payment sides. Invoice-level discount and tax are allocated across lines pro rata so product/category/day rows add back up to the invoice totals.
- `invoice_receipts` primary key is `invoice_receipt_id`.
- `income_sources` primary key is `income_source_id`.
- Boolean columns are `tinyint(1)`; controllers normalise checkbox input with `!empty(...)`.
- `role_permissions` primary key is the junk autoincrement `role_id`; the meaningful key is the UNIQUE `(role, permission)` pair, where `role` holds the **slug** that `users.role` is matched against (`admin`, `cashier`, `staff`, …), not a display label. Inserts must therefore supply only `(role, permission)`.
- `payment_methods.is_cash` decides which tenders move the drawer; `shiftCashMethodCodes()` falls back to `['cash']` if the registry is unavailable.
- `sales_invoices.idempotency_key` is nullable and unique per company (`uq_sales_idempotency`); counter sales and pre-Phase-C rows keep it NULL, and MySQL allows unlimited NULLs in a unique index.
- `gateway_transactions.simulates_payment` is the difference between money and pretend money: a sandbox row keeps it at `1` forever, and `gatewayReconcile()` reports `simulated_captured` beside `captured` so a demonstration can never be read as takings.
- `gateway_transactions.status` and `payment_gateways.mode` are enums that include values the *other* tables do not (`sandbox` in `mode`) — a session records the runtime it was actually opened under, which may no longer be configurable.
- `payment_gateways.*_key` / `*_webhook_secret` columns are `VARCHAR(1000)` because they hold ciphertext envelopes, and are never selected into a page: `gatewayConfigViews()` returns `set`/`hint` flags instead.
- `settings` is company-scoped through `uk_settings_company_key`. The bare UNIQUE on `settings.key` that migration 012 dropped would otherwise make the second company to save any setting fail with a duplicate-key error.
- `qr_payment_points.code` is unique per company (`uq_qr_point_company_code`) and is the stable handle printed on the sign; it is validated as `^[a-z][a-z0-9_-]{1,39}$`. `amount` is `NULL` for an open-amount point and a positive `DECIMAL` for a fixed one. The QR text is built at read time: an open point encodes `scan_url` unchanged, a fixed point appends `amount` and `reference` query parameters to an http(s) link (other URI schemes are left alone because they do not share the URL query convention). The destination must fit a QR version 1–10 symbol (~213 bytes at ECC M) or the save is refused before storage.
- Monetary and quantity columns are `DECIMAL`; PHP code casts explicitly before arithmetic.

## 4. Migration status

Applied migrations (recorded in `schema_migrations`):

| Migration | Applied | Purpose |
|---|---|---|
| `001_auth_core.sql` | 2026-10-30 07:21:28 | Align the live `users` table with the authentication service |
| `002_core_inventory.sql` | 2026-10-30 07:21:29 | Create core structures: branches, departments, branch access, product locations, inventory transactions, invoice/PO line items |
| `003_company_branch_departments.sql` | 2026-11-02 05:34:08 | Additive company/branch/department compatibility fields |
| `004_sales_receivables.sql` | 2026-11-02 05:38:06 | Additive sales/customer credit fields; create quotes, quote lines and customer statements |
| `005_ap_expense_income.sql` | 2026-11-02 05:58:15 | Additive AP, expense and income compatibility fields with backfill |
| `006_expense_vendor_nullable.sql` | 2026-11-02 06:08:17 | Allow expenses without a vendor |
| `007_rbac_permissions.sql` | 2026-11-02 19:56:27 | Permission catalogue, role grants and per-user override plumbing |
| `008_admin_core.sql` | 2026-11-05 16:56:26 | `roles` and `payment_methods` tables (12 roles, 5 payment methods seeded), `users.role` enum widened to `VARCHAR(50)`, `users.*`/`roles.*`/`payment_methods.*` permissions |
| `009_payment_method_widen.sql` | 2026-11-05 20:14:23 | `customer_payments.payment_method` and `payment_made.payment_method` enum → `VARCHAR(50)` so registry codes survive |
| `010_barcodes.sql` | 2026-11-05 20:33:16 | `products.barcode` (unique) and the `product_barcodes` alternate-code table |
| `011_cashier_shifts.sql` | 2026-11-05 22:02:57 | `cashier_shifts`, `shift_movements`, `sales_invoices.shift_id` + `idempotency_key` (unique per company), `customer_payments.shift_id`, `shifts.view/create/update/delete` permissions (4 permissions and 20 role grants; 75 permissions and 269 grants in total) |
| `012_payment_gateways.sql` | 2026-11-06 07:17:13 | `payment_gateways`, `gateway_transactions`, `gateway_events`, `gateway_refunds`, `payment_methods.gateway`, `gateways.view/update/verify/refund` permissions with 12 role grants (79 permissions, 281 grants in total), and the `settings.key` duplicate-index repair |
| `013_gateway_provider_id.sql` | 2026-11-06 07:22:11 | `gateway_transactions.provider_id` for the provider's own transaction id, which a refund must name |
| `014_receipt_printing.sql` | 2026-11-06 12:48:55 | `customer_payments.tendered_amount` (the note handed over, so a reprint shows change); `PRINT` added to `audit_log.action` |
| `015_sales_analytics.sql` | 2026-11-06 15:48:06 | `financial_reports.report_type` widened with `sales_analytics`; the report is derived at read time, so no new table |
| `016_qr_payments.sql` | 2026-11-07 09:03:33 | `qr_payment_points` table; `qr_payments.view/create/update/delete` permissions and 18 role grants (83 permissions, 299 grants in total) |

No pending migrations. Checksums are stored per file; do not edit an applied migration — add a new one instead. (Migrations 008 and 009 were extended once after applying — the collation fix on 008 and the `payment_made` widening on 009 — and their stored checksums were re-synced to the final file contents.)

### Backups taken before schema changes

| File | Taken before |
|---|---|
| `backups/inventory_pre_005_20261102_055429.sql` | Migration 005 |
| `backups/inventory_pre_006_20261102_060807.sql` | Migration 006 |
| `backups/inventory_pre_007_20261102_194234.sql` | Migration 007 |
| `backups/inventory_pre_008_20261105_165622.sql` | Migration 008 |
| `backups/inventory_pre_009_20261105_201304.sql` | Migration 009 |
| `backups/inventory_pre_009b_20261105_202004.sql` | Migration 009 extension |
| `backups/inventory_phaseA_complete_20261105_202813.sql` | Phase A close-out snapshot |
| `backups/inventory_pre_010_20261105_203311.sql` | Migration 010 |
| `backups/inventory_pre_011_20261105_220221.sql` | Migration 011 |
| `backups/inventory_pre_012_20261106_071630.sql` | Migrations 012 and 013 (013 added one nullable column minutes later, from the same dump) |
| `backups/inventory_pre_014_20261106_124840.sql` | Migration 014 |
| `backups/inventory_pre_015_20261106_154237.sql` | Migration 015 |
| `backups/inventory_pre_016_20261107_090322.sql` | Migration 016 |

Migrations 003 and 004 were applied before the pre-migration backup routine was established, so dumps for those two steps do not exist. This is an operational gap recorded in `OUTSTANDING_ISSUES.md`; every subsequent migration has a dump.

See `MIGRATIONS.md` for runner behaviour, backup commands and failure recovery.

## 5. Repaired database dependencies

The following mismatches between code and schema were found and corrected in code or additive migrations:

| Original assumption | Actual structure | Resolution |
|---|---|---|
| `suppliers` table | `vendors` | All AP queries now use `vendors` |
| `invoice_receipts.ir_id` | `invoice_receipt_id` | Corrected in queries and views |
| `income_sources.source_id` | `income_source_id` | Corrected |
| `incomes` table | `income_records` | Corrected |
| `payments_made` table | `payment_made` | Corrected |
| `journal_entries.posted_at` | `posted_date` | Corrected |
| `journal_entry_lines.debit/credit` | `debit_amount` / `credit_amount` | Corrected |
| Account type `revenue` | `income` | Corrected validation |
| `financial_reports.updated_at` | `generated_at` | Corrected regeneration logic |
| `products.allow_backorder`, `list_price`, `weight`, `width`, `height`, `length` | Columns do not exist | Removed from product insert/update |
| `product_locations.bin_code`, `is_active` | Columns do not exist | Removed from initial-location insert |
| `banks.balance_after` in UPDATE | Column does not exist on `banks` | Update now sets `banks.balance = <new balance>` |
| `users.password` | `users.password_hash` | Migration 001 and auth service |
| `expenses.vendor_id NOT NULL` | Expenses may have no vendor | Migration 006 makes it nullable |
| `UNIQUE (key)` on `settings` | Settings are scoped per company | Migration 012 dropped the bare unique index; `uk_settings_company_key (company_id, key)` is the rule and `idx_settings_key` still covers the lookup |

## 6. Data preservation rules

- No production table was dropped, renamed or truncated during repairs.
- No financial record was silently replaced; migrations are additive and backfill only from existing values.
- Temporary verification records were created only to exercise workflows and were removed afterwards. Seed data (workflows, settings, banks, products, admin user) is intact.
