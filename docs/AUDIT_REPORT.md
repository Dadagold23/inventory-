# Phase 1 — Project Audit Report

**Project:** Inventory, Sales, Expenses, Income, Approval and Financial Management System
**Location:** `C:\xampp\htdocs\inventory` (served at `http://localhost/inventory/`)
**Audit date:** 2026-10-30
**Phase:** 1 only — inspection and reporting. **No application file or database record was modified.**
**Environment observed:** PHP 8.2.12 (XAMPP), Apache 2.4 running, MariaDB 10.4.32 (XAMPP data dir), Windows 10 x64.

> **Historical baseline.** This document records the state observed during Phase 1, before any repairs. Findings below are retained for traceability and may no longer describe the current code or database. For the current state see `ARCHITECTURE.md`, `DATABASE.md`, `TEST_REPORT.md` and `OUTSTANDING_ISSUES.md`.

---

## 0. Executive verdict

The application **cannot currently boot** and **cannot complete a login**. This is not a styling or edge-case problem: the entry points fail at bootstrap, the authentication layer is structurally unusable, 12 PHP files do not compile, and the code expects a database schema that largely does not exist.

| Area | State | Evidence |
|---|---|---|
| Homepage (`index.php`) | Loads a "Database Connection Failed" / "Schema Initialization Required" diagnostic to anonymous users | `index.php:17,32-65,69-113`; live HTTP 200 with setup content |
| Login | Impossible | `auth.php` fatal before rendering; `auth_functions.php` exits with JSON on include; login POST targets a non-existent `/auth` route |
| Any module (`/inventory/dashboard.php`, etc.) | HTTP 500 fatal | Verified live: `require_once(C:\xampp\htdocs/config/database.php): Failed to open stream` at `dashboard.php:13` |
| PHP syntax | **12 of 64 files fail `php -l`** | see §3.3 |
| Database | Live server crashes on any table read (InnoDB LSN corruption); schema is 40 tables but code references 20+ tables that do not exist | see §6 |
| Security | Unauthenticated destructive scripts, source-disclosing views, no RBAC, no CSRF on business writes | see §8 |
| Test suite | 19 "test" scripts only assert that files/tables exist; zero functional tests | see §13 |

None of the 15 final acceptance criteria can be marked as met at this time. The root causes are systemic (generated code drifting from its own schema, no bootstrap/router, no migration runner), not isolated bugs.

---

## 1. Method and evidence gathered

**Static inspection**
- Inventoried every file: 116 total (64 PHP, 23 SQL, 20 HTML views, 1 CSS, 1 JS, plus `.env`, docs, scripts).
- Read in full: `.env`, `.env.example`, `config/database.php`, `index.php`, `dashboard.php`, `auth.php`, `includes/auth_functions.php`, `includes/auth_helpers.php`, `includes/dashboard_functions.php`, `includes/company_functions.php`, `includes/report_functions.php`, `apply_schema.php`, `check_schema.php`, `db/schema.sql`, representative module SQL, all asset files, `create_payment_received.php`, `fix_module13.php`, `run_migration.bat`, `robots.txt`, `sitemap.xml`.
- Reviewed all 20 controllers, all 20 includes, all 20 views and the 19 test scripts via targeted parallel reviews, then verified every headline claim with direct grep/read.
- Ran `php -l` over all 64 PHP files and `node --check` on the only JS file.

**Dynamic inspection (read-only)**
- `curl` against the live Apache server: `/inventory/index.php` → 200 (DB/setup diagnostic), `/inventory/dashboard.php` → fatal, `/inventory/auth.php` → fatal, `/inventory/views/login.html` → raw HTML **including unexecuted `<?php` source**.
- Confirmed no `.htaccess` and no front controller exists in `htdocs` or `htdocs/inventory`.
- MySQL: server was down on arrival. Started XAMPP MariaDB 10.4.32; extracted all 40 live tables and 385 column definitions from `information_schema`; the server then **crashed on `SELECT COUNT(*)` against ordinary tables**, reproducibly (see §6.8).

**Limits**
- Row counts and business data could not be read because of the server crash. Therefore data volume and data quality are **unknown** (`.ibd` files exist; `users.ibd` = 128 KB, `sales_invoices.ibd` = 96 KB, so records likely exist).
- No functional test could be executed beyond bootstrap probes, because no request reaches a controller. Per the strict rules, **no test is reported as passed**.

---

## 2. File and folder inventory

| Item | Count / state |
|---|---|
| Root PHP controllers | 20 (`ap, approval, audit, auth, bank, company, customer_receivables, dashboard, expense, income, inventory, ledger, payment_made, payment_received, payroll, report, sales, system_settings, tax, index`) |
| Includes ("function libraries") | 20 |
| Ad-hoc scripts | 5 (`apply_schema.php`, `check_schema.php`, `create_payment_received.php`, `fix_module13.php`, `test_db.php`) |
| Test scripts | 19 (`test_module2.php` … `test_module20.php`) — existence checks only |
| Views | 20 HTML files containing PHP |
| SQL | `db/schema.sql` + `db/schema_module1..21.sql` + `migration_complete.sql` = 23 files |
| Shared assets | 1 CSS (1.5 KB), 1 JS (4.7 KB) — JS is syntactically invalid |
| `controllers/` | **Empty** |
| `models/` | **Empty** |
| `assets/images/`, `assets/fonts/` | **Empty** |
| Routing | **None** — no `.htaccess`, no `index.php` front controller, no route registry |
| Source control | **Not a git repository** — no restore point |
| Environment loader | **None** — `.env` is never read by any PHP file (`grep APP_URL|getenv|parse_ini` = 0 hits) |

---

## 3. Critical (blocking) findings

### 3.1 `APP_ROOT` is computed one directory too high in every controller — every module is unreachable

Every root controller sets `APP_ROOT = dirname(__DIR__)`, which resolves from `C:\xampp\htdocs\inventory\X.php` to **`C:\xampp\htdocs`**, then requires `<APP_ROOT>/config/database.php`.

- `dashboard.php:8,13`, `inventory.php:8,13`, `sales.php:8,13`, and the same two lines in the other 16 controllers.
- `auth.php` is worse: `AUTH_ROOT = dirname(__DIR__)` → `htdocs` (`auth.php:17`), `APP_ROOT = dirname(AUTH_ROOT)` → **`C:\xampp`** (`auth.php:18`).
- `index.php:12,17` uses the same definition but masks the problem with a hard-coded Windows path fragment `APP_ROOT . '\inventory\config\database.php'` — this breaks on Linux/cPanel.

**Verified live:** `GET /inventory/dashboard.php` returns
`require_once(C:\xampp\htdocs/config/database.php): Failed to open stream` (fatal at line 13).
Same fatal occurs for every controller; nothing after line 13 executes.

### 3.2 `includes/auth_functions.php` is an entry-point script, not a library — including it terminates the request

`includes/auth_functions.php` does on include:
- `session_start()` (`:20`), reads `$_GET['action']` (`:28`), dispatches (`:39-65`), then `header('Content-Type: application/json'); echo json_encode($response); exit;` (`:68-70`).

All 18 business controllers (`dashboard.php:14`, `sales.php:14`, `inventory.php:14`, `ap.php:14`, …) and `company_functions.php:16` require it. Even if paths were fixed, every request would emit an auth JSON payload and die before the controller runs. It also has broken requires (`AUTH_ROOT . '/../config/database.php'` → `htdocs/config`, `:16`; self-require, `:17`) and a parse error at `:167`.

### 3.3 PHP syntax failures — 12 files

`php -l` failures (exact files):
`includes/approval_functions.php` (line 71), `ap_functions.php` (490), `auth_functions.php` (167), `bank_functions.php` (103), `customer_receivables_functions.php` (109), `ledger_functions.php` (50), `payment_made_functions.php` (58), `payment_received_functions.php` (2), `payroll_functions.php` (142), `sales_functions.php` (256), `tax_functions.php` (135), `sales.php` (343).

Cause: `$_SESSION['user_id'] ?? 1` (and `{$_SERVER[...] ?? 'x'}`) interpolated directly inside double-quoted SQL strings — illegal expression interpolation in PHP 8.2.
`payment_received_functions.php` is additionally **corrupted**: the entire file is one physical line containing literal `\n` sequences, so it defines no functions at all.

### 3.4 Duplicate function declarations will fatal as soon as syntax is fixed

`formatPrice(PDO, float)` is defined identically three times: `includes/inventory_functions.php:363`, `includes/sales_functions.php:505`, `includes/ap_functions.php:527`.
`sales.php:17-19`, `ap.php:17-19`, `expense.php:17-19` include two or more of these files in one request → `Cannot redeclare formatPrice()`.
The **dashboard does not include any** of them, yet `views/dashboard.html:44-96,143` calls `formatPrice()` → undefined-function fatal.

### 3.5 Authentication is incompatible with the live `users` schema and cannot succeed

- Live `users` columns: `user_id, username, email, password, role, is_active, created_at, updated_at`.
- `auth_functions.php` queries/uses `password_hash, status, full_name, failed_login_attempts, lock_until, last_ip_address, last_login, password_reset_token, password_reset_expires, password_changed_at` (`:104,117,124-155,232,263,276,316-330`) — none exist.
- Seed admin (`db/schema.sql:81-82`) stores the literal string `hashed_password`, not a bcrypt hash, so `password_verify()` could never succeed.
- `auth.php:66,72,87,109` calls handlers with one argument while signatures require `(PDO, array &$response)` (`auth_functions.php:183,206,245,290`) → `ArgumentCountError`.
- `auth.php:56` has an operator-precedence bug: `$_SESSION['user_id'] ?? 0 > 0`.
- CSRF token is expected by `auth.php:32` but only generated inside `auth_functions.php`, which `auth.php` cannot load.
- The login form posts to `/auth?action=login` (`views/login.html:25`); there is no router, no `.htaccess`, and no `/auth` path — the browser receives 404.

### 3.6 The live database schema does not match the code; 20+ tables are missing

Live DB = 40 tables. The code references many tables that exist only in its imagination. Full map in §6.3. Examples: `sales`, `sale_lines`, `quotes`, `branches`, `departments`, `suppliers`, `invoice_receipts`, `payments_received`, `payments_made`, `income_sources`, `income_transactions`, `expense_categories`, `product_locations`, `inventory_transactions`, `dashboard_metrics`, `tax_payments`, `payroll_items`, `user_roles`, `customer_statements`, `report_lines`, `general_ledger`, `accounts`.
Conversely, live tables such as `customer_payments`, `vendors`, `payment_made`, `income_records`, `chart_of_accounts`, `journal_entries` are barely or never used by the code.

### 3.7 The homepage is a setup/debug page, not a dashboard

`index.php` is a foundation debug page: it checks for tables `accounts` and `temp_transactions` that **no schema file ever creates** (`index.php:32-41`), so it permanently renders "Database Schema Initialization Required"; it then prints company/user counts and setup links to anonymous visitors (`:69-113`). It never checks authentication and never redirects to login or the dashboard. This directly violates Module 3's first requirement.

### 3.8 No routing layer; every link and redirect is root-absolute

There is no router/.htaccess. All links/redirects use `/dashboard`, `/login`, `/sales`, `/auth?action=…`, etc. (250+ occurrences; e.g. `views/login.html:25,47`, `views/dashboard.html:19,22-30`, `auth.php:36,57,67,96,104,112`, and every controller). Under `/inventory/` these resolve to `http://localhost/dashboard` → **404**.
Some nav items are not links at all (`views/dashboard.html:23,25-29` use a bare string attribute with no `href`).

### 3.9 Unauthenticated destructive scripts exposed in the web root

- `check_schema.php:15` executes `DROP TABLE IF EXISTS payment_received` on a plain GET; no auth; root credentials inline (`:2`).
- `apply_schema.php:7-10,21-63` executes the entire migration set on a GET; catches failures and continues, so a half-applied schema is silently reported as "complete".
- `create_payment_received.php:5` and `fix_module13.php:3` create tables with root credentials, unauthenticated.
- `test_db.php` dumps schema details; the 19 `test_module*.php` scripts are also publicly reachable.
- None of these are protected by auth, CSRF, IP restriction, or deletion safeguards.

### 3.10 Views are directly web-readable and leak PHP source

`views/*.html` contain `<?php` and are served raw because `.html` is not executed by Apache. Verified: `GET /inventory/views/login.html` returns HTML including unexecuted PHP. Same for `includes/auth_functions.php` (reachable as a script and will emit JSON). This discloses CSRF handling, SQL structure, and session logic.

---

## 4. High-severity findings

### 4.1 No authorization / RBAC
- `requireAuth()` (`auth_helpers.php:74-79`) only checks `$_SESSION['user_id']`.
- `userHasPermission()` (`auth_helpers.php:32-40`) and `getUserPermissions()` (`:43-55`) query `user_roles(resource, action, is_allowed)`, a table that does not exist; they are never called anywhere.
- No controller performs a role check (grep: 0 role checks in all 18 controllers). Every authenticated user sees every module, including payroll, tax, bank, approvals, settings, audit.

### 4.2 CSRF is effectively absent
- Only `auth.php:31-38` validates a token, and that path cannot run.
- No business controller validates POSTs (grep: 0 `csrf` in all controllers), although views emit hidden `csrf_token` fields (45 occurrences).
- Logout is a GET request (`auth.php:64`), so it is CSRF-triggerable.
- `verifyCsrfToken()` has a precedence bug (`auth_helpers.php:59`: `!empty($token) && $token === $_SESSION['csrf_token'] ?? ''`) and is unused.
- AJAX/fetch POSTs in views (POS add/remove) carry no token.

### 4.3 No tenancy scoping; hard-coded company/branch `1`
- Every list function is called with literal `1`: `ap.php:51,155,316`, `sales.php:49,133,236`, `dashboard.php:41-42,50-51`, `bank.php:45,129,152,155`, `audit.php:41,51-58,69`, `company.php:107,168`, `expense.php:49,108,207-209,217-223`, `approval.php:45,198`, `tax.php:45`, `customer_receivables.php:45,91,174`.
- Branch/company IDs are accepted from POST/hidden fields (`company.php:116`, `ap.php:163`, `views/ap.html:559,568-572`, `views/sales.html:671,682-684`).
- Single-record getters omit `company_id` filters (e.g. `ap_functions.php:45`, `sales_functions.php:44`, `inventory_functions.php:49`, `bank_functions.php:44`, `tax_functions.php:40`) → cross-tenant IDOR if multi-company data exists.
- The session never stores `company_id`/`branch_id`; there is no authorized context to resolve them from.

### 4.4 No database transactions or row locking
Grep for `beginTransaction|commit|rollBack|FOR UPDATE` across the whole codebase: **0 hits**.
Multi-step writes are non-atomic: sale + lines + stock (POS), quote→sale→stock, payment + invoice status, bank transaction + balance, journal header + lines, payroll run + lines. A failure leaves partial financial/stock state.

### 4.5 Inventory and financial calculations are incorrect
- `sales_functions.php:291` loops `$quote['products']`, which `getQuoteById()` never returns; `recordSale()` is never called from the sales flow → **sales never reduce stock**.
- No COGS is recorded anywhere; no GL posting for sales/COGS/inventory.
- `inventory_functions.php:225`: weighted average cost `$oldTotal / $newQty` omits the received value, understating inventory value on every receipt.
- `inventory_functions.php:273` clamps oversold stock with `max(0, …)`, hiding negative stock instead of preventing it.
- `sales_functions.php:188,263` build document numbers from `lastInsertId()` before the insert → always `-000`.
- Payments mark invoices fully paid regardless of amount (`recordPaymentReceived` in the corrupt `payment_received_functions.php`; `ap_functions.php:507-515`; `payment_made_functions.php:77`).
- `ap_functions.php:430` condition `$irData['paid'] && !$irData['was_paid'] ?? true` is always truthy.
- `ap_functions.php:185-186` sets `amount_received = total_amount` on creation; `createPurchaseOrder` has 19 columns vs 18 values, so it always fails.
- Revenue can be counted in `sales`, `income_transactions`, and `dashboard_metrics` with no deduplication.
- `payroll_functions.php:201` computes net pay without deductions (net = gross) and persists it.
- `ledger_functions.php:79` posts `NOW()` into `posted` and `NULL` into `posted_date`; `balanced` is computed but never enforced; running balance is always 0.
- `bank_functions.php:123-126` updates `banks.balance = balance_after`, but no such column exists and deposit/withdrawal signs are ignored.

### 4.6 Approval engine is incomplete
- `approval_requests` has no `reference_number`; status enum lacks `returned` (`'pending','approved','rejected','expired'`), yet the prompt requires returned/resubmittable requests.
- `approval_functions.php:131` references `approved_count`; live column is `approval_count`.
- No reject/expire transition logic; `$approved` is unused (`:128`); `userCanApprove()` (`:146`) ignores role, step and status → self-approval is not prevented.
- Approval buttons in `views/approval.html` have no handlers.

### 4.7 Dashboard is broken in three independent ways
1. Depends on nonexistent `dashboard_metrics` (`includes/dashboard_functions.php:29-44,74-80`; `views/dashboard.html:134`).
2. Executes a DB query inside the view (`views/dashboard.html:134`), violating the controller/view contract.
3. Calls undefined `formatPrice()` and renders all figures as `?? 0`; a broken/missing table silently shows zeroes instead of a diagnostic.
No Chart.js is loaded anywhere, so none of the required charts exist. The "Add Metric" form posts `save_metric` to `/dashboard?action=manage_dashboard` (`views/dashboard.html:177`), for which `dashboard.php` has no handler.
`dashboard_functions.php:77` uses an undefined `$companyId`; `setDashboardMetric()` re-fetches after `fetch()` consumption.

### 4.8 Missing modules and dead endpoints
Missing required controllers/views: user management, customers, stock receiving/issuing/transfers/adjustments, branches, departments, notifications. Redirect targets `/branches` and `/departments` (`company.php:140,151,199,212`) do not exist.
Views call endpoints that do not exist: `/includes/pos_add_item.php`, `pos_remove_item.php` (`views/sales.html:722,744`), `pos_income_add.php`, `pos_income_remove.php` (`views/income.html:605,627`), `get_branches.php`, `get_managers.php` (`views/company.html:489,503`).
`customer_receivables.php:177` calls `getApTerms()` without including `ap_functions.php`; `sales.php:266` calls undefined `updateSaleStatus()`.

### 4.9 Include-time side effects and duplicated bootstrapping
- 19 of 20 includes call `session_start()` at include time; 17 open their own PDO connection.
- `company_functions.php:20-22` runs `session_start()`, `requireAuth()` and opens PDO **inside a function library**; including it can redirect the request to `/login`.
- Every controller opens another PDO after including libraries (e.g. `dashboard.php:24`).
- Sessions are started up to three times per request.

### 4.10 Configuration, migrations and environment
- `config/database.php:13-18` hard-codes host/name/user/password, ignoring `.env` (no loader exists; `APP_URL` is unused).
- `tables_exist()` queries `information.tables` (invalid schema; should be `information_schema.tables`) and builds invalid `:table` placeholders (`:117-133`).
- `execute_schema()` splits scripts on the string `GO` (`:89`) — SQL Server syntax; MariaDB scripts contain no `GO`, so this is a no-op/wrong.
- `apply_schema.php:23,52` splits on `";\n"` (fragile; breaks on procedures/strings) and continues after failures; **there is no `schema_migrations` table and no execution tracking**.
- `run_migration.bat` applies only `db/schema.sql`, not modules 1–21.
- No DB backup was ever created; no restore point exists.
- `.env.example` points to `inventory_db` and `http://localhost`, inconsistent with `.env` (`inventory`, `/inventory`).

### 4.11 Error handling and diagnostics
- No `set_error_handler`/`set_exception_handler`; no application logger.
- `display_errors` is on in this environment; fatal errors expose absolute paths and stack traces (observed).
- DB failures are swallowed by `get_db_connection()` returning `null`; callers then call methods on `null` → new fatals instead of a controlled diagnostic.
- No health check, migration status page, or admin-only diagnostics exist.

### 4.12 Data disclosure
- `auth_functions.php:239` returns the password-reset token in the JSON response.
- `index.php` exposes company/user counts and table status to anonymous users.
- `robots.txt` enumerates admin paths and contradicts `.php` disallowing; `sitemap.xml` advertises direct `.php` URLs that do not match the link scheme.
- No password policy beyond non-empty; no rehash; no session lifetime enforcement despite `SESSION_LIFETIME=120`.

---

## 5. Medium / low findings (selected)

- **Layout duplication:** all 20 views repeat `<!DOCTYPE>`, `<head>`, navbar; there is no shared layout, sidebar, breadcrumbs, partials, or component library. Path drift is visible: `views/dashboard.html:23` uses `/inventory/inventory` while `:24` uses `/sales`.
- **Assets:** no view loads `assets/js/foundation.js`; `foundation.js` is **syntactically invalid** (`node --check`: `bsTooltips.push new bootstrap.Tooltip(el);` at line 126) and uses a nonexistent `element.setRole()`.
- **Font Awesome URL is invalid:** `.../font-awesome/6.4.0/css/minimal.css` (all views) → icons 404.
- **XSS:** raw echoes of DB values in `views/report.html:150-154` (also reflected into a CSS class), `ap.html:184,292`, `income.html:116,164,207`, `expense.html:178,220`, `customer_receivables.html:202,279`, `system_settings.html:68`.
- **Broken data contracts:** `views/payment_made.html:209` iterates `$pos` only set on a different action; `views/payment_received.html:209` iterates an unset `$invoices`; `views/approval.html:134,154` uses `$requests` which is never assigned; `views/report.html:142,150-154` treats a list as a keyed map.
- **Dead POST branches:** `expense.php:167-190` (`record_payment`, `delete_expense`) is nested inside `save_expense`; `income.php:187-217` is nested inside `save_transaction`. Those actions can never fire.
- **No validation:** amounts/totals trusted from hidden fields (`ap.php:165-170`, `sales.php:143-148`, `expense.php:121-123`); no date/amount range checks.
- **Markup errors:** `views/ledger.html:88-95` missing `</td>`, duplicate hidden `lines` never populated (`:109,138`); `views/company.html:112,115` invalid `type` attributes; `views/tax.html:44,109` and `payroll.html:42,104` `data-bs-target` missing `#`; `dashboard.html:17` same.
- **UX gaps:** no pagination/search on several tables; no date-range or daily/weekly/monthly/quarterly/annual summaries; no empty-state components; fake buttons (View/Edit/Delete) with no handlers; hardcoded counts in `views/report.html:119,123`; `income.html:79,204` hardcode `0`.
- **Tests:** `test_module*.php` only check `file_exists()` and table existence; they do not exercise any behavior, and they are web-accessible.
- **Dead/duplicate files:** `controllers/` and `models/` are empty; `migration_complete.sql` duplicates only 6 foundation tables; `db/schema_module13.sql` creates `payment_received` while `db/schema_module7.sql` creates `customer_payments` for the same concept.
- **`settings` vs `system_settings` vs `company_settings`:** three competing configuration tables (`db/schema.sql:59`, `db/schema_module19.sql`, `db/schema_module3.sql`), with no single access layer.

---

## 6. Database compatibility and dependency report

### 6.1 Live schema (40 tables, extracted from `information_schema`)

```
approval_actions            approval_requests          approval_workflow_steps
approval_workflows          audit_log                  bank_transactions
banks                       categories                 chart_of_accounts
companies                   company_settings            customer_communications
customer_payments           customers                   dashboard_config
dashboard_widgets           employees                  expenses
financial_reports           income_records             journal_entries
journal_entry_lines         pay_rates                  payment_made
payroll_run_lines           payroll_runs               permissions
products                    purchase_orders            report_columns
role_permissions            sales_invoices             settings
system_settings             tax_rates                  tax_transactions
user_permissions            user_sessions              users
vendors
```

Key core columns (verified):
- `users`: `user_id, username, email, password, role ENUM('admin','manager','staff'), is_active, created_at, updated_at`
- `sales_invoices`: `invoice_id, company_id, customer_id, invoice_number, issue_date, due_date, total_amount, tax_amount, status ENUM('pending','partial','paid'), notes, timestamps` — **no line-item table, no `amount_paid`/`paid_date`, no COGS**
- `products`: `product_id, company_id, category_id, sku, name, description, track_inventory, manage_stock, sales_price, cost_price, reorder_level, maximum_level, target_level, is_active, sort_order` — **no quantity/on-hand column**
- `expenses`: `expense_id, company_id, vendor_id, expense_date, description, amount, category, receipt_path, is_active`
- `income_records`: `income_id, company_id, source, amount, source_id, income_date, description, recognized`
- `payment_made`: `payment_id, company_id, vendor_id, purchase_order_id, payment_date, amount, payment_method, reference_number, notes`
- `customer_payments`: `payment_id, company_id, customer_id, invoice_id, amount, payment_date, payment_method, reference_number, notes`
- `approval_requests`: `request_id, workflow_id, company_id, requester_id, entity_type, entity_id, status ENUM('pending','approved','rejected','expired'), submitted_date, completed_date, approval_count, required_count, notes`

### 6.2 Migration DDL vs live DB

`db/schema.sql` + `db/schema_module1..21.sql` define 41 tables. **Live DB contains 40 of them.** The single missing one is `payment_received` (`db/schema_module13.sql`), which is exactly what `create_payment_received.php` / `fix_module13.php` were written to patch. This is direct evidence that `apply_schema.php` failed and continued silently.
Note that `migration_complete.sql` only contains 6 tables and is not a complete migration.

### 6.3 Tables the code uses that do not exist (by module)

| Missing table | Referenced by |
|---|---|
| `suppliers` | `ap_functions`, `expense_functions`, `payment_made_functions` (live equivalent: `vendors`) |
| `branches`, `departments` | `company_functions`, `sales_functions`, `ap_functions`, `inventory_functions` |
| `sales`, `sale_lines` | `sales_functions`, `customer_receivables_functions`, `income_functions` |
| `quotes`, `quote_lines` | `sales_functions` |
| `invoice_receipts` | `ap_functions`, `customer_receivables_functions` |
| `payments_suppliers` | `ap_functions` |
| `payments_received` | `sales_functions`, `customer_receivables_functions`, `payment_received_functions` (live: `customer_payments`) |
| `payments_made` | `payment_made_functions` (live: `payment_made`) |
| `income_sources`, `income_transactions` | `income_functions` (live: `income_records`) |
| `expense_categories` | `expense_functions` |
| `product_locations`, `inventory_transactions` | `inventory_functions` (no stock ledger exists at all) |
| `dashboard_metrics` | `dashboard_functions`, `views/dashboard.html` (defined nowhere) |
| `tax_payments` | `tax_functions` (live: `tax_transactions`) |
| `payroll_items` | `payroll_functions` (live: `payroll_run_lines`) |
| `user_roles` | `auth_helpers` (live: `user_permissions`, `role_permissions`, `permissions`) |
| `customer_statements` | `customer_receivables_functions` |
| `report_lines` | `report_functions` (live: `report_columns`) |
| `purchase_order_lines` | `ap_functions` |
| `general_ledger`, `accounts` | `ledger_functions`, `report_functions` (live: `journal_entries`, `journal_entry_lines`, `chart_of_accounts`) |
| `temp_transactions` | `index.php` bootstrap check |

### 6.4 Column mismatches

| Table | Columns the code expects | Reality |
|---|---|---|
| `users` | `password_hash, status, full_name, failed_login_attempts, lock_until, last_ip_address, last_login, password_reset_token, password_reset_expires, password_changed_at, name` | Only `password`, `is_active`; **no `name`/`full_name`** (used in `audit_functions:57`, `expense_functions:114`, `report_functions:30`, `payroll_functions:117`, `approval_functions:55`, `ap_functions:126`) |
| `sales_invoices` | `paid_date, amount_paid, amount_owed, balance` | None exist |
| `products` | `quantity, allow_backorder, list_price, weight, width, height, length` | None exist |
| `expenses` | `category_id, supplier_id, employee_id, expense_number, tax_amount, total_amount, receipt_status, payment_status, created_by, approved_by, payment_date` | Only `category, vendor_id, receipt_path, is_active` |
| `approval_requests` | `created_by`, `approved_count` | `approval_count`; no `created_by` |
| `payment_made` | `supplier_id, created_by` | `vendor_id`; no `created_by` |
| `financial_reports` | `u.name` join | users has no `name` |
| `audit_log` | `u.name` join | same |

### 6.5 Duplicate / competing concepts to be resolved before adding any table

| Concept | Competitors | Recommendation |
|---|---|---|
| Supplier | `vendors` (live + M6) vs `suppliers` (code) | **Standardize on `vendors`**; update code |
| Customer receipts | `customer_payments` (live + M7) vs `payment_received` (M13) vs `payments_received` (code) | **Use `customer_payments`**; delete duplicate migration concept |
| Supplier payments | `payment_made` (live + M14) vs `payments_made` (code) | **Use `payment_made`** |
| Other income | `income_records` (live + M9) vs `income_transactions`/`income_sources` (code) | **Use `income_records`**; add a source lookup only if required |
| Settings | `settings`, `system_settings`, `company_settings` | Consolidate on `system_settings` + `company_settings`, or document one accessor |
| Accounting | `chart_of_accounts` + `journal_entries` + `journal_entry_lines` (live) vs `general_ledger`/`accounts` (code) | **Use the journal tables**; build ledger views on them |
| Dashboard metrics | `dashboard_metrics` (nowhere) vs `dashboard_config/widgets` (live) | **Do not create `dashboard_metrics`**; compute aggregates from transactions as the prompt requires |
| Receivables/AP documents | `invoice_receipts`/`purchase_order_lines` (nowhere) vs `sales_invoices`/`purchase_orders` | Add line-item tables only if AP/AR documents are actually needed; otherwise use existing headers + payments |

### 6.6 Missing database capabilities required by the prompt

These do **not** exist in any migration or in the live DB and must be added by numbered migrations:
- Stock movement ledger (receiving/issuing/transfer/adjustment) and per-location stock.
- Sales line items and purchase order line items (for COGS and receiving).
- Branches, departments, and user↔branch/company authorization.
- Approval `returned` status, unique request reference, approval stages metadata.
- Receivable/payable aging support (derived from invoices/payments is acceptable).
- `schema_migrations` tracking table.
- A single notification mechanism (no notifications table or service exists).
- Audit coverage for business tables (only `audit_log` exists as a shell).

### 6.7 Migration/DB layer defects (summary)

| Defect | Location |
|---|---|
| Hard-coded credentials; `.env` ignored | `config/database.php:13-18` |
| Invalid `information.tables` query and placeholder misuse | `config/database.php:117-133` |
| `GO` splitting for MariaDB | `config/database.php:89` |
| Naive `";\n"` splitting + continue-on-failure | `apply_schema.php:23,52`, module loop `:36-63` |
| No `schema_migrations` tracking | everywhere |
| No backups before DDL | everywhere |
| `payment_received` DDL failures patched by ad-hoc scripts | `db/schema_module13.sql`, `create_payment_received.php`, `fix_module13.php` |
| Destructive `DROP TABLE` script in web root | `check_schema.php:15` |

### 6.8 Live database integrity incident (environmental blocker)

On arrival, XAMPP MariaDB was stopped. After starting it, `information_schema` queries succeeded, but **every ordinary table read crashed the server** (`ERROR 2013 Lost connection`), reproducibly, across all tested tables. `mysql_error.log` shows repeated:

```
InnoDB: Page [...] log sequence number ... is in the future!
InnoDB: Your database may be corrupt or you may have copied the InnoDB tablespace
        but not the InnoDB log files.
```

A core dump (`mysqld.dmp`) was produced. The data directory is shared with other local projects and a newer MariaDB 11.8.3 binary exists in `C:\CodesEasy\DevKit\services\mysql`. The most likely cause is a **datadir written by a newer MariaDB (11.8) and then opened by XAMPP's 10.4**, which cannot replay the newer log sequence. **No data was modified or repaired during this audit**; the server was stopped after metadata extraction.

This must be resolved (under approval) before any migration work, because:
- Backups cannot be taken while the server crashes on reads.
- Row counts/data quality are still unknown.
- Any DDL on a crashing InnoDB instance risks further damage.

---

## 7. Required navigation vs actual implementation

| # | Required module | Status |
|---|---|---|
| 1 | Dashboard | Exists, non-functional (`dashboard.php` + view) |
| 2 | Inventory | Exists, non-functional, no stock tables |
| 3–6 | Stock receiving / issuing / transfers / adjustments | **Missing** |
| 7 | Sales | Exists, parse error, wrong schema |
| 8 | Customers | **Missing** (only embedded in sales/POS) |
| 9 | Purchases | Partial via `ap.php`; schema mismatched |
| 10 | Suppliers | Partial via `ap.php`; uses missing `suppliers` table |
| 11 | Income | Exists, wrong schema |
| 12 | Expenses | Exists, wrong columns |
| 13 | Payments received | Exists, corrupt include, wrong table |
| 14 | Payments made | Exists, wrong table name |
| 15 | Bank management | Exists, wrong balance logic |
| 16 | Customer receivables | Exists, missing tables |
| 17 | Accounts payable | Partial via `ap.php`, broken SQL |
| 18 | Accounting ledger | Exists, wrong tables/logic |
| 19 | Approval requests | Exists, partial workflow |
| 20 | Payroll | Exists, wrong table names, net-pay bug |
| 21 | Tax | Exists, wrong table names |
| 22 | Reports | Exists, broken joins |
| 23 | Audit logs | Exists, joins nonexistent `users.name` |
| 24 | Company management | Exists, redirects to missing branch/department pages |
| 25 | System settings | Exists, three settings tables |
| 26 | User management | **Missing** |

---

## 8. Security assessment (summary)

| # | Issue | Severity | Evidence |
|---|---|---|---|
| 1 | Unauthenticated `DROP TABLE` and migration execution | Critical | `check_schema.php:15`, `apply_schema.php:21-63` |
| 2 | PHP source disclosure via direct view access | Critical | `views/login.html` served raw (verified) |
| 3 | Authentication bypass/unusable auth (wrong columns, plaintext seed) | Critical | `auth_functions.php:104`, `db/schema.sql:81-82` |
| 4 | No RBAC anywhere | High | `auth_helpers.php:32-55` (unused), 0 role checks |
| 5 | CSRF missing on all business POSTs | High | 0 checks in 18 controllers |
| 6 | Hard-coded tenancy + IDOR on single-record getters | High | `getXById` functions; literal company `1` |
| 7 | Stored XSS in reports and lists | High | `views/report.html:150-154` and others |
| 8 | Reset token returned in API response | High | `auth_functions.php:239` |
| 9 | SQL injection risk from interpolated superglobals | High | 10+ occurrences (`sales_functions.php:256`, etc.) |
| 10 | Debug diagnostics/credentials exposed | Medium | `index.php:69-113`, compiled paths in errors |
| 11 | Weak password policy, no session timeout | Medium | `auth_functions.php:290-338` |
| 12 | No audit trail for business mutations | Medium | `audit_log` not written by modules |
| 13 | Public test scripts | Low/Medium | 19 `test_module*.php`, `test_db.php` |

---

## 9. Root-cause analysis

1. **Code/schema divergence.** Controllers and includes were generated against a hypothetical schema (sales/quotes/suppliers/branches/stock/dashboard_metrics), while the migration files and live DB implement a different, simpler schema (sales_invoices/customer_payments/vendors/journal tables). Neither side was reconciled.
2. **No application bootstrap.** Each controller invents its own root constant (incorrectly), session, PDO and includes. There is no single config, router, auth, layout or error handler.
3. **Function libraries behave as controllers.** `auth_functions.php` and `company_functions.php` execute request logic/redirects at include time.
4. **Copy-paste module generation with no integration test.** 12 syntax errors, duplicate `formatPrice`, empty `controllers/`/`models/`, fake buttons and nonexistent endpoints.
5. **No migration discipline.** Ad-hoc `apply_schema.php` continues after failures; one table (`payment_received`) silently missing; destructive repair scripts added instead of a tracked migration.
6. **No environment discipline.** `.env` is unused; credentials and paths are hard-coded to this Windows machine; DB server itself is version-conflicted and crashing.

---

## 10. Proposed remediation sequence (Phases 2–10)

**Decision gates are required before Phase 2.** See §11.

**Phase 2A — Stabilize and protect (immediate)**
1. Freeze writes; resolve MariaDB version/datadir conflict; take a full `mysqldump` + data-file backup; verify a restore; record the exact server version to standardize on.
2. Create a timestamped file backup (project is not under git). Optionally initialize git locally.
3. Move/deny public access to `check_schema.php`, `apply_schema.php`, `create_payment_received.php`, `fix_module13.php`, `test_db.php`, `test_module*.php`, and `views/*.html` / `includes/*.php` (web-root protection).
4. Turn off `display_errors` for non-local environments; add an error/exception logger.

**Phase 2B — One bootstrap and one schema**
5. Add `bootstrap.php` + `config/app.php` + `.env` loader; define one `APP_ROOT`, one PDO service, one session/auth service, one CSRF service, one renderer, one logger.
6. Add `schema_migrations` and a real migration runner (statement-aware, transactional where possible, records success/failure, never continues silently). Backfill tracking for the 40 existing tables.
7. Reconcile the schema using the **live/migration tables as canonical**: rename code references (`suppliers→vendors`, `payments_received→customer_payments`, `payments_made→payment_made`, `income_transactions→income_records`, `tax_payments→tax_transactions`, `payroll_items→payroll_run_lines`, `general_ledger→journal_*`), and add only the genuinely missing structures (stock ledger, line items, branches/departments, approval returned/reference, RBAC).
8. Fix `users` authentication additively (add `password_hash`, `status`, reset/lock columns; migrate the seed admin to a real bcrypt hash without losing accounts).

**Phase 3 — Homepage/dashboard**
9. Replace `index.php` with an auth-aware front controller that redirects to login or role-appropriate dashboard.
10. Build dashboard aggregates directly from posted transactions (no `dashboard_metrics` table): financial, inventory, approval, operational summaries; date-range filters; Chart.js; admin-only diagnostics; empty-DB handling.

**Phase 4–5 — Layout and routing**
11. One layout + partials + components (tables, filters, pagination, cards, alerts, empty states); migrate all 20 views; no queries in views.
12. Central route registry (subdirectory-safe URLs), POST/redirect/GET, correct HTTP methods, 404/403 handling; register all 26 required modules.

**Phase 6 — Auth/RBAC**
13. Central auth, login/logout, CSRF on all writes, password policy, session lifetime; roles and company/branch scoping from the authenticated user; remove every hard-coded `1`.

**Phase 7 — Business integration**
14. Wrap multi-step writes in transactions with row locking; post sales/COGS/inventory/GL atomically; single-source revenue; correct payment status transitions; fix weighted-average/FIFO cost; balanced journals.

**Phase 8 — Approval engine**
15. Reference numbers, configurable stages, reject/return/resubmit, no self-approval, history, reapproval on material change; approval ≠ posting.

**Phase 9 — UI**
16. Unified Bootstrap 5 theme, sidebar, responsive tables/charts, accessible contrast, icons, notifications; remove dead buttons and fix markup.

**Phase 10 — QA/docs**
17. Real tests (unit + integration + HTTP), PHP lint gate, DB reconciliation checks, P&L and ledger balance tests, approval E2E, security checks; documentation package and modified-files/DB-change log.

---

## 11. Decisions requiring approval before Phase 2

1. **Canonical schema:** Use the live/migration tables as the source of truth (recommended) and rewrite the code to them, adding only missing structures — versus rebuilding the schema from the code's expectations. The first preserves existing records and avoids duplicate concepts.
2. **Database recovery:** approval to repair the MariaDB datadir conflict (identify which server version owns it, force recovery if needed) and to take/restore backups. No destructive action will be taken without explicit approval.
3. **Ad-hoc scripts and test files:** confirm they may be moved into `tools/` (non-web) or deleted after backup.
4. **Stock model:** add a movement ledger (`inventory_transactions`) plus per-location quantities, deriving on-hand from movements (recommended), versus a single `products.quantity` column.
5. **Branches/departments:** confirm they are in scope now (required by navigation and company module) though no schema exists.
6. **Settings consolidation:** confirm one authoritative settings table (`system_settings`) with company overrides, or retain all three with one accessor.
7. **Deployment target:** confirm cPanel PHP/MariaDB versions so the bootstrap and SQL stay portable (no Windows paths, no MariaDB-11-only syntax).

---

## 12. Verification instructions (current state)

```bash
# PHP syntax gate (expected: 12 failures today)
cd C:/xampp/htdocs/inventory
for f in $(find . -name '*.php' -not -path './.qodo/*'); do php -l "$f" || echo "FAIL $f"; done

# Bootstrap probe (expected: fatal today)
curl -s http://localhost/inventory/dashboard.php | head
curl -s http://localhost/inventory/auth.php | head

# Source-disclosure probe (expected: raw <?php today)
curl -s http://localhost/inventory/views/login.html | head -5

# Database metadata (only while the server is stable)
mysql -u root information_schema -e \
  "SELECT table_name FROM tables WHERE table_schema='inventory' ORDER BY 1;"
```

No test in this phase is reported as passed; the probes above document failures.

---

## 13. Files created and database changes

**Created:** `docs/AUDIT_REPORT.md` (this report).
**Modified:** none.
**Database changes:** none. The XAMPP MariaDB service was started to read metadata and stopped after it crashed; no table, row, or schema was altered.

---

## 14. Outstanding issues / unknowns

- Row counts, existing financial records and data quality cannot be confirmed until the DB server is stable (crashes on read).
- Exact cause of the InnoDB LSN mismatch (which server version last owned the datadir) is not yet proven; only the symptom is confirmed.
- No source control exists, so file-level rollback depends on the backup to be taken in Phase 2.
- The intended deployment environment (cPanel PHP/MariaDB version) is unknown.
- The 19 "tests" provide no behavioral assurance; a real test harness must be created.
- Whether `payment_received` ever held production data cannot be determined (table is absent).
