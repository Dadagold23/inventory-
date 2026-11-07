# Application Architecture

**Application:** Inventory, Sales, Expenses, Income, Approval and Financial Management System
**Version:** 2.0.1
**Stack:** PHP 8.2 (XAMPP), MariaDB 10.4, Apache 2.4 with `mod_rewrite`
**Updated:** 2026-11-07

---

## 1. Request lifecycle

Every browser request follows the same path:

1. `.htaccess` rewrites extensionless URLs (`/dashboard`) to the matching controller (`dashboard.php`).
2. The root controller requires `bootstrap.php`.
3. `bootstrap.php` loads, in order:
   - `config/database.php` — `.env` reader, PDO constants, single connection accessor.
   - `includes/helpers.php` — `url()`, `asset()`, `e()`, `redirect()`, `formatPrice()`, CSRF helpers, flash/input helpers.
   - `includes/logger.php` — `app_log()` and global PHP error/exception handlers.
   - `includes/auth_service.php` — session start, authentication, authorization and audit helpers.
4. The controller calls `auth_require()`, then loads its function library and model logic.
5. The controller prepares data and renders the matching file from `views/`.
6. PHP errors are written to `storage/logs/app.log`; user-facing responses never include SQL text or credentials.

```
.htaccess
   └── <module>.php  (controller)
         ├── bootstrap.php
         │     ├── config/database.php   → db() / get_db_connection()
         │     ├── includes/helpers.php
         │     ├── includes/logger.php
         │     └── includes/auth_service.php
         ├── auth_require()
         ├── includes/<module>_functions.php  (queries + business rules)
         └── views/<module>.html                (Bootstrap 5 presentation)
```

## 2. Directory map

| Path | Purpose |
|---|---|
| `bootstrap.php` | Single application bootstrap; must be required first by every web entry point |
| `config/database.php` | One PDO connection, prepared-statement defaults, `.env` loading |
| `includes/helpers.php` | URL, escaping, formatting, CSRF and flash helpers |
| `includes/logger.php` | File logger, error/exception handlers, debug switch |
| `includes/auth_service.php` | Login, session, role checks, password change, audit |
| `includes/auth_functions.php` | Compatibility wrappers kept for legacy controllers |
| `includes/auth_helpers.php` | Role/permission helpers and CSRF wrappers |
| `includes/*_functions.php` | One library per business module |
| `<module>.php` | Thin controller for each module (routing, POST handling, view data) |
| `views/*.html` | Server-rendered HTML/JavaScript views using Bootstrap 5 |
| `migrations/` | Ordered, additive SQL migrations applied by `scripts/migrate.php` |
| `db/` | Original per-module schema files used for fresh installations |
| `backups/` | Timestamped pre-migration SQL backups |
| `storage/logs/app.log` | Application and PHP error log |
| `storage/keys/app.key` | The AES data key minted for a host that sets no `APP_KEY`; directory is denied to the web server. Losing it makes stored gateway credentials unreadable |
| `assets/css/foundation.css` | Shared layout overrides |
| `assets/js/pos.js` | POS front end: scan, search, cart mutations, split tenders and checkout against the JSON endpoints |
| `docs/` | This documentation set |

## 3. Database access rules

- All code obtains PDO through `db()` (or `get_db_connection()`); no controller creates its own connection.
- `PDO::ATTR_ERRMODE => ERRMODE_EXCEPTION`, `PDO::ATTR_EMULATE_PREPARES => false`.
- All user-supplied values are bound parameters. Identifiers are never interpolated from request input.
- Stock, invoice, bank and payment mutations run inside transactions.
- Row locks (`SELECT ... FOR UPDATE`) protect stock and invoice balances during concurrent sales, payments and cancellations.

## 4. Authentication and authorization

- Sessions are cookie-only (`httponly`, `SameSite=Lax`, strict mode) and the session ID is regenerated on login. The cookie is scoped to the app base path; if a browser still sends duplicate legacy session cookies, bootstrap uses the most-specific one and expires the legacy paths.
- Passwords use `password_hash()` / `password_verify()`.
- Five failed logins lock the account for 30 minutes.
- Roles: `role_permissions.role` and `users.role` both hold a slug — 11 are granted in `role_permissions` (`owner`, `manager`, `sales_manager`, `inventory_manager`, `storekeeper`, `cashier`, `staff`, `accountant`, `procurement`, `approver`, `auditor`) and `admin` needs no grants because it bypasses permission checks. The `roles` table's `name` column holds display labels and is **not** what permissions are matched against. The `admin` role bypasses permission checks. Migration `007_rbac_permissions.sql` seeded the permission catalogue and role grants, extended by `008_admin_core.sql`, `011_cashier_shifts.sql`, `012_payment_gateways.sql` and `016_qr_payments.sql` (83 permissions, 299 grants). Grants live in `role_permissions` as `(role slug, permission slug)` pairs — the table's autoincrement `role_id` primary key carries no meaning. `require_permission()` in `includes/auth_helpers.php` is called by every authenticated controller. Page views require `resource.view`; each POST action is mapped to `create`, `update`, `delete` or `approve`. Direct `user_permissions` grants augment role grants; denied requests render a standard 403 page and are written to `storage/logs/app.log`.
- Every controller calls `auth_require()` before any business logic and `require_permission()` before loading or mutating module data.
- Every state-changing POST validates a CSRF token through `require_post_csrf()`.
- Login, logout, failures and repository changes are recorded in `audit_log`.
- **One route has no session at all:** `gateway_webhook.php` is the provider's callback, and a provider cannot hold a CSRF token. Its authentication is the HMAC signature over the raw request body, checked against the secret that company stored, before a single field of the payload is read. Company identity comes from the reference inside the event, never from a session, and the audit entry for the resulting change is stamped by hand with that company and user (`gatewayAudit()`).

## 5. Layout and navigation

All views share one visual system:

- Bootstrap 5.3 + Font Awesome 6 + `assets/css/foundation.css`.
- The same primary navigation bar and module grouping on every page.
- Extensionless internal links are generated with `url()`, so the app works under `/inventory/` and at a domain root.
- The sidebar is defined once in `views/layout/nav.php` (grouped Operations / Finance / Control / Administration) and included by `views/layout/header.php` and by `views/dashboard.html`, which keeps its own inline shell CSS/JS but must never keep its own link list. Each entry declares the permission `resource.verb` it needs, is rendered only when `can(resource, 'view')` allows it, and a section whose links are all filtered out disappears. `$layoutActive` / `$activeNav` picks the highlighted item.

## 6. Module map

| Module | Controller | Library | View |
|---|---|---|---|
| Authentication | `auth.php` | `auth_service.php`, `auth_helpers.php`, `auth_functions.php` | `home.html` (landing, root), `login.html`, `change_password.html` |
| Dashboard | `dashboard.php` | `dashboard_functions.php` | `dashboard.html` |
| Inventory | `inventory.php` | `inventory_functions.php` | `inventory.html` |
| Barcodes | `barcodes.php` | `barcode_functions.php` (allocate/map/lookup), `barcode_lib.php` (SVG rendering) | `barcodes.html`, `barcode_labels.html` (print-only) |
| Sales | `sales.php` | `sales_functions.php` (incl. the POS/checkout service) | `sales.html`; POS client `assets/js/pos.js` |
| Cashier shifts | `shifts.php` | `shift_functions.php` | `shifts.html`, `shift_report.html` (print-only X/Z) |
| Income | `income.php` | `income_functions.php` | `income.html` |
| Expenses | `expense.php` | `expense_functions.php` | `expense.html` |
| Banking | `bank.php` | `bank_functions.php` | `bank.html` |
| Accounts payable | `ap.php` | `ap_functions.php` | `ap.html` |
| Customer receivables | `customer_receivables.php` | `customer_receivables_functions.php` | `customer_receivables.html` |
| Payments received | `payment_received.php` | `payment_received_functions.php` | `payment_received.html` |
| Payments made | `payment_made.php` | `payment_made_functions.php` | `payment_made.html` |
| General ledger | `ledger.php` | `ledger_functions.php` | `ledger.html` |
| Reports | `report.php` | `report_functions.php` | `report.html` |
| Tax | `tax.php` | `tax_functions.php` | `tax.html` |
| Payroll | `payroll.php` | `payroll_functions.php` | `payroll.html` |
| Approval workflow | `approval.php` | `approval_functions.php` | `approval.html` |
| Company/branches/departments | `company.php` | `company_functions.php` | `company.html` |
| System settings | `system_settings.php` | `system_settings_functions.php` | `system_settings.html` |
| Categories | `categories.php` | `categories_functions.php` | `categories.html` |
| Users & roles | `admin.php` | `user_functions.php` | `admin.html` |
| Payment methods | `payment_methods.php` | `payment_methods_functions.php` | `payment_methods.html` |
| Payment gateways | `payment_gateways.php`, `gateway_webhook.php` (public callback) | `gateway_functions.php` (50 service functions), `crypto_functions.php` (secrets at rest) | `payment_gateways.html`, `gateway_sandbox.html` (the simulator stands in for a hosted checkout page) |
| QR scan-to-pay | `qr_payments.php` (list/edit/preview/download) | `qr_payment_functions.php`, `barcode_lib.php` (QR rendering) | `qr_payments.html` |
| Audit log | `audit.php` | `audit_functions.php` | `audit.html` |

## 7. Business integration

- **Inventory:** receipts, sales, adjustments and returns update `product_locations` and append `inventory_transactions` inside a transaction. Product lists are company-scoped and no longer multiply through branch joins.
- **Sales:** invoice creation locks stock rows, writes invoice + line items, deducts stock and records movements. Payments lock the invoice row, cap overpayment and recompute status. Cancellation restocks exactly once and is refused while any `customer_payments` row remains against the invoice, so stock and cash cannot diverge.
- **Cash drawer:** a shift is opened per `(company, branch, cashier)` and every POS invoice and tender is tagged with its `shift_id`. Expected cash is never stored as truth: `shiftTotals()` recomputes `Float + Cash Receipts + Paid In − Cash Refunds − Paid Out` on each read from `cashier_shifts.opening_float`, `shift_movements` and cash-flagged `customer_payments`. Change handed back from a larger cash tender is not a movement — `customer_payments.amount` records only the amount applied to the invoice, which is what keeps the formula identical to the specification. Closing needs the owner or `shifts.delete`, re-checks status under `FOR UPDATE`, stores the counted drawer and the variance, and is audited; a shift that has taken sales can be closed but never voided.
- **POS:** one route (`sales.php?action=pos`) with two envelopes — JSON for `assets/js/pos.js` (`format=json`, `ajax=1` or `X-Requested-With`), classic post/redirect/render without JavaScript. Prices, stock, tax and tenders are always recomputed server-side: cart lines store only `product_id`, `quantity` and `discount_percent`, and `recalculatePosTotals()` re-reads `products` on every mutation. `posCheckout()` refuses to sell without an open shift unless the company turns `pos_requires_shift` off, refuses an unknown or non-positive tender, refuses a split tender that does not cover the bill, and refuses to oversell. Retries are safe: `sales_invoices` has a unique `(company_id, idempotency_key)` index, the lookup happens before the cart is even read, and a duplicate-key race replays the original sale instead of failing.
- **Online payment:** the boundary between "the customer paid" and "the sale is posted" is `gateway_transactions`. `gatewayInitialize()` writes a pending row (reference `PA`/`FL` + `REF-YYYYMMDD-HHMMSS-XXXXXX`, amount, currency, cart snapshot with `product_id`s and **no prices**) and hands back a checkout URL; nothing is booked. The sale exists only after `gatewayVerify()` asks the provider for the real answer, `gatewaySettlementVerdict()` matches amount and currency, and `gatewayQuoteSnapshot()` re-prices the snapshot against today's catalogue. Anything that does not line up is held (`price_drift`, `amount_mismatch`, `currency_mismatch`) with the reason stored on the row, never posted. `gatewayFinaliseSale()` then claims the row `FOR UPDATE` and creates the invoice under the idempotency key `gw:<reference>`, so a return, a webhook and a "ask again" press are the same one sale. A card tender cannot reach the ordinary checkout path at all: `posCheckout()` refuses it and points at the gateway button. Refunds are rows in `gateway_refunds`; the invoice keeps its `paid` history.
- **Simulation vs settlement:** a gateway with no secret key of its own, `sandbox_allowed` on and a non-production host runs `runtime_mode = sandbox`, which serves `views/gateway_sandbox.html` in place of the provider's page. Simulated money is stamped `simulates_payment = 1` permanently and is totalled separately by `gatewayReconcile()` (`simulated_captured` beside `captured`). `gatewaySimulationPermitted()` is the environment's answer, not the gateway's, and `gatewaySandboxWarning()` names the setting that has to go before real money.
- **QR scan-to-pay:** a pay point is a destination, not a payment. `qr_payment_points` stores the link the customer's phone should open (plus the branch, tender and amount rule that belong with it); the QR is rendered on demand by `barcode_lib.php`, never stored as an image. An open-amount point encodes the link unchanged, a fixed-amount point appends `amount` and `reference` query parameters to an http(s) link, and a destination that cannot fit a version 1–10 QR is refused before it is saved. This module does not process money — the provider or bank behind the link does, so there is no gateway session and no invoice until that provider reports a payment through its own channel.
- **Banking:** every bank transaction stores the server-calculated `balance_after` and updates `banks.balance` in the same transaction.
- **Approvals:** requests reference an active workflow, compute the required approval count from workflow steps, prevent duplicate approvals by the same user, and transition `pending → approved | rejected`.
- **Reports:** trial balance, general ledger, balance sheet, income statement and cash flow are generated from `journal_entries` / `journal_entry_lines`; report definitions are stored in `financial_reports` and regenerating a report refreshes `generated_at` instead of creating duplicates. Sales analytics is the exception to the journal-entry source: it derives every figure at read time from `sales_invoices`, `sales_invoice_items` and `customer_payments`, shares invoice-level discount/tax across lines pro rata, and takes the tender mix from the payment rows (never the `split` header).
- **Dashboard:** all figures are SQL aggregates over live tables; the six-month trend and invoice-status charts are rendered with Chart.js from server JSON.

## 8. Routing

- `.htaccess` denies HTTP access to `.env`, dumps, logs, Markdown files, `prompt.txt` and the legacy diagnostic/test scripts (`apply_schema`, `check_schema`, `test_module*`, etc.).
- Controllers accept `?action=` values; unknown actions fall back to a safe list view.
- `index.php` is the landing page: guests receive `views/home.html` (product overview plus embedded sign-in) at `/`, `/index` and `/index.php`; signed-in users are redirected to `/dashboard`. `/auth` keeps the focused `views/login.html` form.
- `gateway_webhook.php?gateway=paystack|flutterwave` is the one public, sessionless POST route. It answers plain text and status codes only (405 for GET, 404 for an unknown provider, 401 for a missing or wrong signature, 400 for a body that is not JSON, 500 when processing fails so the provider retries) and never renders a page. A webhook announces; the handler still calls `gatewayVerify()` before anything is posted.
- Destination URLs are built with `url()`, which respects `APP_URL` and the current base path.

## 9. Migrations

- `scripts/migrate.php` is CLI-only and tracks applied files in `schema_migrations` with a SHA-256 checksum.
- Migrations are additive. They do not drop production tables or truncate business records.
- A pre-migration backup is taken before schema changes; see `backups/`. Backups exist for migrations 005 and 006, but not for 003 and 004 — that gap is recorded in `DATABASE.md` §4 and `MIGRATIONS.md`.
- Apply and inspect migrations with `php scripts/migrate.php --status` and `php scripts/migrate.php`; see `MIGRATIONS.md`.
