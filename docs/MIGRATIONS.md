# Migration Instructions

**Updated:** 2026-11-06

The application uses a small tracked migration runner in `scripts/migrate.php`. Legacy setup scripts in the project root are blocked over HTTP and must not be used.

---

## 1. Rules

1. Migrations live in `migrations/` and run in filename order.
2. Applied migrations are recorded in `schema_migrations` with a SHA-256 checksum.
3. **Never edit an applied migration.** The runner aborts on checksum mismatch. Add a new numbered file.
4. Migrations must be additive and preserve existing rows. Do not drop or truncate production tables as part of a routine repair.
5. Take a database dump before every migration.
6. The runner executes statements one by one. MariaDB DDL auto-commits, so a failure leaves earlier statements applied. Restore the pre-migration dump, fix the file, and rerun.

## 2. Commands

```bash
# Show applied and pending files
php scripts/migrate.php --status

# Apply all pending migrations
php scripts/migrate.php

# Backup first (recommended)
C:/xampp/mysql/bin/mysqldump.exe -u root inventory \
  > backups/inventory_pre_upgrade_$(date +%Y%m%d_%H%M%S).sql
```

The runner refuses to execute over HTTP (`PHP_SAPI !== 'cli'`). `scripts/.htaccess` also denies web access.

## 3. Adding a migration

1. Choose the next number, for example `008_example_change.sql`.
2. Write idempotent, additive DDL where possible:
   - `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...`
   - `CREATE TABLE IF NOT EXISTS ...`
   - `ADD KEY IF NOT EXISTS ...`
3. Backfill only from existing columns. Never overwrite financial values with invented data.
4. Take a fresh dump.
5. Run `php scripts/migrate.php --status`, then `php scripts/migrate.php`.
6. Verify with `--status` (zero pending) and inspect `storage/logs/app.log`.

## 4. Applied history

| # | File | Applied | Purpose |
|---|---|---|---|
| 1 | `001_auth_core.sql` | 2026-10-30 07:21:28 | Align `users` with authentication (`password_hash`) |
| 2 | `002_core_inventory.sql` | 2026-10-30 07:21:29 | Core branches, departments, branch access, product locations, inventory transactions, line-item fields |
| 3 | `003_company_branch_departments.sql` | 2026-11-02 05:34:08 | Company currency/fiscal fields; branch/department compatibility fields |
| 4 | `004_sales_receivables.sql` | 2026-11-02 05:38:06 | Customer credit fields; invoice discount/payment fields; quotes, quote lines, customer statements |
| 5 | `005_ap_expense_income.sql` | 2026-11-02 05:58:15 | Vendor/PO fields; `invoice_receipts`; `expense_categories`; income sources and income compatibility; backfills |
| 6 | `006_expense_vendor_nullable.sql` | 2026-11-02 06:08:17 | Allow expenses without a vendor |
| 7 | `007_rbac_permissions.sql` | 2026-11-02 19:56:27 | Seed 62 RBAC permissions and role grants (manager 58, staff 20); admin bypasses |
| 8 | `008_admin_core.sql` | 2026-11-05 16:56:26 | `roles` (12 seeded) and `payment_methods` (5 seeded); `users.role` enum → `VARCHAR(50)`; `users.*`, `roles.*`, `payment_methods.*` permissions and grants |
| 9 | `009_payment_method_widen.sql` | 2026-11-05 20:14:23 | `customer_payments.payment_method` and `payment_made.payment_method` enum → `VARCHAR(50) utf8mb4_unicode_ci` |
| 10 | `010_barcodes.sql` | 2026-11-05 20:33:16 | `products.barcode` (unique) and `product_barcodes` alternate-code table; data backfill is done by `scripts/backfill_barcodes.php`, not in SQL |
| 11 | `011_cashier_shifts.sql` | 2026-11-05 22:02:57 | `cashier_shifts` and `shift_movements`; `sales_invoices.shift_id`, `sales_invoices.idempotency_key` + `uq_sales_idempotency (company_id, idempotency_key)`, `customer_payments.shift_id`; `shifts.*` permissions and 20 role grants |
| 12 | `012_payment_gateways.sql` | 2026-11-06 07:17:13 | `payment_gateways`, `gateway_transactions`, `gateway_events`, `gateway_refunds`; `payment_methods.gateway`; `gateways.*` permissions and 12 role grants; drops the legacy `settings.key` UNIQUE index that blocked a second company from saving any application setting |
| 13 | `013_gateway_provider_id.sql` | 2026-11-06 07:22:11 | `gateway_transactions.provider_id` — the provider's own numeric id, which a refund must name |
| 14 | `014_receipt_printing.sql` | 2026-11-06 12:48:55 | `customer_payments.tendered_amount` (what was handed over, so change survives a reprint); `PRINT` added to `audit_log.action` |
| 15 | `015_sales_analytics.sql` | 2026-11-06 15:48:06 | `financial_reports.report_type` widened with `sales_analytics`; the report itself is derived at read time from invoices, lines and payment rows, so no new table |
| 16 | `016_qr_payments.sql` | 2026-11-07 09:03:33 | `qr_payment_points` (company-scoped scan-to-pay points, optional branch and tender, `scan_url`, `amount_type` open/fixed, `ecc`); `qr_payments.view/create/update/delete` permissions and 18 role grants |

No migrations are pending as of 2026-11-07 (`php scripts/migrate.php` reports `Nothing to migrate.`).

> Exception to the append-only rule: `008` was edited after applying to add `COLLATE=utf8mb4_unicode_ci` (its tables had inherited `utf8mb4_general_ci` and produced error 1267 on joins), and `009` was extended to cover `payment_made`. Both live tables were altered to match first, then the stored checksums were re-synced. Do not repeat this — issue a new migration file instead.

## 5. Backups

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

Migrations 003 and 004 were applied before the pre-migration backup routine was established, so no dumps exist for those two. This is recorded as an outstanding operational gap. Do not reconstruct or overwrite history; ensure every future migration has a dump.

## 6. If a migration fails

1. Stop application writes.
2. Read the failing statement number and error printed by the runner.
3. Restore the dump taken immediately before the migration.
4. Correct the SQL **in a new or unapplied file only**.
5. Run `--status`, then apply again.
6. If an applied file is reported as modified, restore the original file contents; do not delete its `schema_migrations` row to “force” a rerun.

## 7. Rollback policy

There are no automatic `down` migrations. Rollback means restoring the pre-migration dump after taking a dump of the current state. This is deliberate: reverse DDL can destroy records that were created after the forward migration.
