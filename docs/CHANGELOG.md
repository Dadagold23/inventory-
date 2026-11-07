# Change Log — Repair and Modernization

**Project:** Inventory, Sales, Expenses, Income, Approval and Financial Management System
**Period:** 2026-10-30 to 2026-11-07
**Baseline:** `AUDIT_REPORT.md` (Phase 1, historical)

This is a reconstruction from the completed phase work; the project is not under Git, so line-by-line diffs are not available. Database changes are listed separately in `DATABASE.md` and `MIGRATIONS.md`.

---

## 2026-10-30 — Foundation, authentication and routing

**New / rewritten**

- `bootstrap.php` — single entry bootstrap; loads configuration, helpers, logger and auth service; starts the secure session; emits CSRF token.
- `config/database.php` — `.env` parser and one PDO connection factory (`get_db_connection()` / `db()`), exceptions enabled, native prepares, utf8mb4.
- `includes/helpers.php` — `e()`, `url()`, `asset()`, `redirect()`, `formatPrice()`, `csrf_token()`, `csrf_field()`, `csrf_verify()`, `require_post_csrf()`.
- `includes/logger.php` — structured `app_log()` and global error/exception handlers; hides details unless `APP_DEBUG`.
- `includes/auth_service.php` — password verification, 5-attempt / 30-minute lockout, session regeneration, audit hooks.
- `includes/auth_functions.php`, `includes/auth_helpers.php` — compatibility wrappers and role/permission helpers.
- `scripts/migrate.php` — CLI-only migration runner with checksum tracking.
- `scripts/set_admin_password.php` — CLI password setter.
- `migrations/001_auth_core.sql`, `migrations/002_core_inventory.sql`.
- `.htaccess` — extensionless routing (`RewriteBase /inventory/`), deny rules for secrets, dumps, logs, docs and legacy scripts.
- `.env.example` — corrected base URL/database placeholders and production-safe defaults.
- Per-directory `.htaccess` deny files for `config/`, `controllers/`, `db/`, `docs/`, `includes/`, `migrations/`, `models/`, `scripts/`, `storage/`, `views/`.
- `index.php`, `auth.php`, `views/login.html`, `views/change_password.html`.

## 2026-11-02 — Shared dashboard shell for every module

- Added `views/layout/header.php` and `views/layout/footer.php`: one authenticated layout with the fixed sidebar, sticky topbar, page header, date chip, loader, three.js background and CSRF sign-out. Sidebar sections and links are defined once and filtered with `can(resource, 'view')`.
- Added `assets/css/app.css` and `assets/js/app.js`, carrying the dashboard's lime-on-dark theme, fonts, shell components, dark forms/tables/modals and the shared loader/sidebar/AOS/three.js behavior.
- Converted **inventory, sales, receivables, suppliers & purchases, expenses, income, payments received, payments made, banking, general ledger, tax, payroll, approvals, reports, audit log, companies & branches and system settings** to the shared shell. Every form, modal ID, CSRF field, data binding and inline script was preserved; module scripts are captured in `$pageScripts` and loaded after Bootstrap/AOS/app.js.
- Updated the dashboard sidebar to the same permission-aware link rules, so the sidebar no longer offers modules the signed-in role cannot open.
- Verified: all views lint clean; the 18 authenticated pages return HTTP 200 with no PHP notices; rendered pages contain one `<main>`, one `</html>` and one Bootstrap bundle; forms keep their CSRF tokens; all SRI hashes match; headless Chrome screenshots at 1440px confirm the shared shell on Banking, Companies & Branches and General Ledger.

## 2026-11-02 — Company, inventory, sales and financial modules

**Company**
- `company.php`, `includes/company_functions.php`, `views/company.html`
- `migrations/003_company_branch_departments.sql`
- Branch/department creation, editing and soft deactivation; company-scoped currency.

**Sales and receivables**
- `sales.php`, `includes/sales_functions.php`, `views/sales.html`
- `customer_receivables.php`, `includes/customer_receivables_functions.php`, `views/customer_receivables.html`
- `migrations/004_sales_receivables.sql`
- Row-locked stock deduction, insufficient-stock guard, customer payments, quotes and statements.
- **2026-11-02 (final pass):** cancellation now refuses invoices that already have payments; message updated in `sales.php`.

**Accounts payable, expenses and income**
- `ap.php`, `includes/ap_functions.php`, `views/ap.html`
- `expense.php`, `includes/expense_functions.php`, `views/expense.html`
- `income.php`, `includes/income_functions.php`, `views/income.html`
- `payment_made.php`, `includes/payment_made_functions.php`, `views/payment_made.html`
- `payment_received.php`, `includes/payment_received_functions.php`, `views/payment_received.html`
- `migrations/005_ap_expense_income.sql`, `migrations/006_expense_vendor_nullable.sql`

**Ledger, tax, payroll, audit, approval and reports**
- `ledger.php`, `includes/ledger_functions.php`, `views/ledger.html`
- `tax.php`, `includes/tax_functions.php`, `views/tax.html`
- `payroll.php`, `includes/payroll_functions.php`, `views/payroll.html`
- `audit.php`, `includes/audit_functions.php`, `views/audit.html`
- `approval.php`, `includes/approval_functions.php`, `views/approval.html`
- `report.php`, `includes/report_functions.php`, `views/report.html`

**Dashboard and settings**
- `dashboard.php`, `includes/dashboard_functions.php`, `views/dashboard.html` — live figures and Chart.js trend/status charts.
- `system_settings.php`, `includes/system_settings_functions.php`, `views/system_settings.html` — company-scoped setting CRUD.
- `bank.php`, `includes/bank_functions.php`, `views/bank.html` — bank accounts, transactions, detail view.

**Inventory (final pass)**
- `inventory.php` — company-scoped list, per-product stock aggregates, CSRF checks, ownership validation before edit/delete, branch-aware stock actions, controller-computed totals.
- `includes/inventory_functions.php` — `recordReceiving()`, `recordSale()` and `recordAdjustment()` rewritten with transactions, `SELECT ... FOR UPDATE`, company derivation, preserved reserved quantity, negative-stock rejection and average-cost fallback; fixed `isProductLowStock()`.
- `views/inventory.html` — full functional rewrite: standard navigation, stat cards, product table, View/Edit wiring, add/edit modal, low-stock table, product detail with receipt/sale/adjustment/deactivate forms.

**Landing page (final pass)**
- `views/home.html` — new standard landing page: branded header, product overview with six capability cards (inventory, sales, expenses, banking, approvals, reporting) and an embedded sign-in card with CSRF, validation and flash messages. Responsive from one column on phones to two on desktop, using the same Bootstrap 5 + Font Awesome + `foundation.css` system as the rest of the app.
- `views/home.html`, `views/login.html` — CDN stylesheets and the Bootstrap bundle are pinned with SRI (`integrity` + `crossorigin`) so a tampered CDN cannot inject script into the public sign-in pages.
- `index.php` — serves `views/home.html` at `/`, `/index` and `/index.php` for guests instead of issuing a 302 to `/auth`; signed-in users still go to `/dashboard`. Sign-in from the landing page posts to `/auth?action=login` and returns to the dashboard.

**Session and CSRF hardening (final pass)**
- `bootstrap.php` — `session.gc_maxlifetime` now follows `SESSION_LIFETIME` (fixes premature sign-out).
- `bootstrap.php` — when a browser sends duplicate `inventory_session` cookies (legacy `path=/` or `path=/inventory` alongside the current `path=/inventory/`), the first, most-specific cookie is used and the legacy paths are expired. Previously PHP kept the last duplicate, so a stale cookie shadowed every login and all pages redirected to `/auth`.
- `includes/helpers.php` — invalid CSRF now returns standard **403** (previously 419, observed as 500 on the XAMPP stack).
- `.env` — `APP_ENV=production`, `APP_DEBUG=false`; no SQL details are shown to users.

**Authorization (RBAC) — final pass**
- `migrations/007_rbac_permissions.sql` — seeds **62 permissions** and role grants (manager 58, staff 20). `admin` bypasses checks.
- `includes/auth_helpers.php` — request-scoped permission cache, `can()` helper for views, and `require_permission()` guard that renders a 403 page and logs the denial.
- `bootstrap.php` — loads the authorization helpers for every entry point.
- All 18 authenticated controllers now enforce `resource.view` on reads and map each POST action to `create`, `update`, `delete` or `approve` (including cross-module actions such as sales payments and customer credit control).
- `views/error_403.html` — standard access-denied page with a dashboard link.

**Dashboard revamp (final pass)**
- `dashboard.php` / `views/dashboard.html` — dashboard rebuilt with the landing page's visual language (three.js background, lime-on-dark theme, fixed off-canvas sidebar, dark Chart.js panels) while preserving all live data bindings and actions.

**Documentation**
- `docs/ARCHITECTURE.md`, `docs/DATABASE.md`, `docs/INSTALLATION.md`, `docs/ENVIRONMENT.md`, `docs/MIGRATIONS.md`, `docs/ADMIN_GUIDE.md`, `docs/USER_GUIDE.md`, `docs/TROUBLESHOOTING.md`, `docs/TEST_REPORT.md`, `docs/OUTSTANDING_ISSUES.md`, `docs/CHANGELOG.md`.
- `docs/AUDIT_REPORT.md` — historical-baseline banner added.

## Database changes

- Seven tracked migrations applied (see `MIGRATIONS.md`).
- No production table dropped, renamed or truncated.
- No financial record silently replaced; backfills derive only from existing columns.
- Seed data preserved: 2 products, 2 banks, 1 admin user, 9 system settings, 8 approval workflows, 8 workflow steps, 13 audit rows, 1 branch.
- Test fixtures created during verification were removed; final counts match the seed baseline.

## Repaired database dependencies

| Assumption in old code | Actual schema | Resolution |
|---|---|---|
| `suppliers` | `vendors` | AP queries use `vendors` |
| `invoice_receipts.ir_id` | `invoice_receipt_id` | Corrected |
| `income_sources.source_id` | `income_source_id` | Corrected |
| `incomes` | `income_records` | Corrected |
| `payments_made` | `payment_made` | Corrected |
| `journal_entries.posted_at` | `posted_date` | Corrected |
| `journal_entry_lines.debit/credit` | `debit_amount` / `credit_amount` | Corrected |
| account type `revenue` | `income` | Corrected |
| `financial_reports.updated_at` | `generated_at` | Corrected |
| product columns `allow_backorder`, `list_price`, `weight`, dimensions | do not exist | Removed from writes/views |
| `product_locations.bin_code`, `is_active` | do not exist | Removed |
| `banks.balance_after` update target | `banks.balance` | Corrected (history keeps `balance_after`) |
| `users.password` | `users.password_hash` | Migration 001 + auth service |
| `expenses.vendor_id NOT NULL` | vendor optional | Migration 006 |
| `branches.branch_name`, `departments.department_name` | `name` | Corrected queries and views |
| hard-coded company/branch `1` | session company/branch | Corrected in repaired modules |

## Verification

See `TEST_REPORT.md`: PHP lint 70/70, 19 pages, 84 internal links, inventory end-to-end, CSRF, settings CRUD, cancellation guard, and final data-count cleanup.

---

## 2026-11-05 — Phase A: administrative core, payment methods, settings unification

**New**

- `admin.php` + `views/admin.html` — Users & Roles workspace: create/edit users, reset passwords, deactivate, per-user branch access and permission overrides; create/rename/delete roles and edit each role's permission matrix. The built-in `admin` role is uneditable and the last active administrator cannot be deactivated or deleted.
- `payment_methods.php` + `views/payment_methods.html` + `includes/payment_methods_functions.php` — payment-method registry (create, edit, activate/deactivate) seeded with cash, bank transfer, credit card, check and credit sale. Methods are deactivated, never deleted, once documents reference them.
- `includes/barcode_functions.php` (see Phase B) and migration `008_admin_core.sql` (`roles`, `payment_methods`, `users.role` widened from the old enum, permission grants for `users.*`, `roles.*`, `payment_methods.*`).
- Sidebar: **Users & Roles** and **Payment Methods** entries under Administration, filtered by `can()`.

**Changed**

- Migration `009_payment_method_widen.sql` — `customer_payments.payment_method` and `payment_made.payment_method` widened from enum to `VARCHAR(50)` so registry codes survive; unknown legacy codes fall back to `bank_transfer` instead of being rejected.
- `includes/auth_helpers.php` — new `auth_guard_branch()`; sales invoices, quotes, purchase orders and income transactions now refuse a `branch_id` the session user may not touch (HTTP 403 + `Branch scope violation` in the application log).
- `includes/approval_functions.php` — a requester can no longer approve their own request unless the company turns on `allow_self_approval`.
- `includes/company_functions.php` — `getCompanySetting()` / `updateCompanySettings()` are company-scoped (they previously read and wrote global rows), plus `appSettingDefinitions()` for the typed application settings (decimal places, default tax rate, default AP terms, default income source, allow self-approval).
- `system_settings.php` + view — new **Application Settings** card writing through `saveAppSettings()`; `includes/sales_functions.php` reads settings per company.
- Settings tables unified on `settings`; the empty legacy `company_settings` table was renamed `zz_deprecated_company_settings` rather than dropped.

## 2026-11-05 — Phase B: barcodes

**New**

- `migrations/010_barcodes.sql` — `products.barcode` (unique) and the `product_barcodes` alias table (`code`, `symbology` enum ean13/ean8/upc_a/code128/qr/other, `source` enum system/gs1/manual/import, per-company unique code).
- `scripts/backfill_barcodes.php` — allocates an internal code for every existing product (5 of 5 products backfilled; 0 left without a code).
- `includes/barcode_lib.php` — dependency-free SVG renderer for EAN-13, EAN-8, Code 128 (subsets B and C) and QR (byte mode, ECC L/M/Q/H, versions 1–10, Reed-Solomon over GF(256) with polynomial 0x11d, 8-mask penalty selection, ISO quiet zones).
- `includes/barcode_functions.php` — check-digit maths, GTIN normalisation, GS1 in-store code generation, alias mapping, primary switching, collision rules, scan resolution and barcode audit rows.
- `barcodes.php` + `views/barcodes.html` + `views/barcode_labels.html` — barcode workspace: scan tester, per-product code list (attach / set primary / remove), printable label sheets (58 mm, 80 mm, Avery L7162 A4 sheet; 1–99 copies; price on/off), single SVG download, bulk SVG ZIP, and a JSON scan-resolution endpoint for the POS.
- `scripts/test_barcode_functions.php`, `scripts/test_barcode_render.php` — verification suites (see `docs/TEST_REPORT.md`).

**Changed**

- `inventory.php` — saving a product guarantees a barcode: new products receive a GS1 in-store (2xx) EAN-13 and the allocated code is reported in the confirmation message; allocation failures are logged and surfaced, never silent.
- `views/inventory.html` — Barcode column with a link into the barcode workspace.
- `includes/helpers.php` — `json_response()` shared JSON responder.
- `views/layout/header.php` — **Barcodes** entry under Operations (`inventory.view`).

**Bug found and fixed during verification**: the QR function pattern omitted the four corner modules of each 7×7 finder eye, which breaks scanner detection. Caught by comparing every generated matrix against an independent reference encoder; 24/24 matrices now match cell-for-cell.

---

## 2026-11-05 — Phase C: cashier shifts, drawer reconciliation, fast POS

Approved decisions for this phase: **upgrade the existing POS in place** (no second POS route) and **block POS checkout until a shift is open** for that cashier + branch. Counter sales raised from the normal Sales screen stay unshifted.

**New**

- `migrations/011_cashier_shifts.sql` — `cashier_shifts` (status, `opening_float`, `expected_cash`, `closing_count`, `variance`, `force_closed`, `idx_shifts_scope`) and `shift_movements` (`float_adjust`/`paid_in`/`paid_out`/`cash_refund`); `sales_invoices.shift_id`, `sales_invoices.idempotency_key` with unique `uq_sales_idempotency (company_id, idempotency_key)`, `customer_payments.shift_id`; `shifts.view/create/update/delete` permissions with 20 role grants. Applied 2026-11-05 22:02:57 after `backups/inventory_pre_011_20261105_220221.sql`.
- `includes/shift_functions.php` — the whole shift lifecycle: `shiftOpen`, `shiftGet`, `shiftCurrentFor`, `shiftRequireOpen`, `shiftTotals`, `shiftPersistExpected`, `shiftClose` (owner-or-`shifts.delete`, `FOR UPDATE` status re-check, variance, audit), `shiftAddMovement`, `shiftVoidIfEmpty`, `shiftList`, `shiftSalesForUser`, `shiftEnforcementEnabled` and `shiftSummaryForUser`, plus branch resolution that validates company, branch and user assignment. **Expected cash is recomputed on every read** as `Float + Cash Receipts + Paid In − Cash Refunds − Paid Out`; the stored `expected_cash` is a snapshot of that value at close.
- `shifts.php` + `views/shifts.html` — shift workspace: “My register” card (open form with branch, float and note), shift detail (reconciliation table, takings by method, movement form, movement and sale lists, close form, manager force-close and empty-shift void gated by `shifts.delete`), and a filterable shift register (status, cashier, branch, date range; the branch filter only appears for `shifts.delete` holders).
- `views/shift_report.html` — standalone 80 mm printable X/Z report: reconciliation terms, takings by method, sales, movements, signature lines, and the note that change returned is not a payout.
- `assets/js/pos.js` — POS client: scan-to-add on Enter, 220 ms debounced search, cart mutations, split-tender rows with a live change preview, one in-flight checkout at a time, 30 s shift-status poll. It never sends prices; it only sends product ids, quantities, discounts and tenders.
- `scripts/test_shift_pos.php` (75 checks) and `scripts/http_smoke_shift_pos.php` (45 checks) — see `docs/TEST_REPORT.md`.

**Changed**

- `sales.php` — the `pos` action is one route with two response envelopes: JSON when the request asks for it (`format=json`, `ajax=1` or `X-Requested-With`), the classic post/redirect/render cycle otherwise. JSON ops: `search`, `scan` (through `bcLookup`), `add_item`, `set_quantity`, `set_discount`, `remove_item`, `clear_cart`, `checkout`, plus an `unknown_op` fallback and a read-only status poll. Branch comes from the session, never from a posted field. Successful no-JS checkout stores the change in `$_SESSION['pos_change_due']` and redirects to the receipt with `autoprint=1` (receipt formatting itself is Phase E). The product list query no longer runs a per-row stock lookup.
- `includes/sales_functions.php` — new `posCheckout()` (shift gate, tender validation against `payment_methods`, split tenders, change, idempotent replay), `posAddLine()`, `posSetLineQuantity()`, `posSetLineDiscount()`, `posSearchProducts()`, `posCartPayload()`, `posWantsJson()`, `posNewIdempotencyKey()`, `salesFindByIdempotencyKey()`, `salesNormaliseTenders()`, `salesTenderLabel()`. `recalculatePosTotals()` now re-reads `sales_price`, `cost_price`, name, SKU and barcode for every cart line in one query, so a tampered session price cannot survive to the invoice and POS cart lines carry no `unit_price` at all. `createSalesInvoice()` accepts `shift_id`, `idempotency_key` and a `payments[]` tender list, writes one `customer_payments` row per tender (booked at the amount **applied**, not the cash handed over), returns `invoice_number`/`amount_paid`/`amount_owed`/`tendered`/`change`/`duplicate`, and replays the original sale when the unique idempotency index is hit by a concurrent retry. `salesInsertPayment()` records `shift_id`.
- `views/sales.html` — POS pane replaced: shift bar (open badge with a link to the drawer, “Register locked” with an Open-a-shift button, or the not-required notice), scan input, live search results, a `<details>` browse list as the no-JS fallback, the cart table, tender rows with “Add payment” and a change display, Complete Sale, a success panel with the receipt link, and a `<noscript>` classic checkout form carrying a hidden idempotency key.
- `views/layout/header.php` — **Shifts & Drawer** entry under Operations (`shifts.view`).
- `includes/company_functions.php` — `pos_requires_shift` added to `appSettingDefinitions()` as a boolean (default on), so the shift gate is an administrator setting rather than a database edit; it reads and writes through the same company-scoped `settings` row the POS consults.

**Behaviour worth knowing**

- Cash tendered above the bill is change, not a drawer movement: keeping it out of `shift_movements` is what makes the reconciliation formula stay exactly as specified.
- `pos_requires_shift` (per-company `settings` row, default on) is the only switch that lets a POS sale through without a shift; when it is off, `sales_invoices.shift_id` stays NULL and the POS screen says so explicitly.
- A shift that has taken sales or non-float movements cannot be voided — only closed, by its owner or by someone holding `shifts.delete`.

**Bugs found and fixed during verification**

1. `011_cashier_shifts.sql` first seeded role grants by joining `roles.name`, which holds display labels — `role_permissions.role` is matched against the `users.role` **slug**, so the grants would never have applied. Rewritten as explicit slug values.
2. `shiftOpen()` stored the float on the shift *and* inserted a `float_adjust` movement that `shiftTotals()` added again — the same notes counted twice. The movement insert was removed; the float lives only on the shift row.
3. A retried POS checkout answered “The cart is empty” instead of replaying the sale, because the idempotency lookup sat inside `createSalesInvoice()` behind the cart guard. The lookup now runs at the top of `posCheckout()`, before the cart is read.
4. Test-harness only: a placeholder/argument mismatch in the fixture insert (HY093), and `logger.php`'s error/exception handlers turning CLI fatals into the generic error page — the harnesses now call `restore_error_handler()` / `restore_exception_handler()`.

**Verification follow-up — the administrator sidebar (2026-11-06)**

Reported after the Phase C report: the dashboard did not list the newly added screens (Categories, Barcodes, Shifts & Drawer, Users & Roles, Payment Methods) while every other page did.

5. `views/dashboard.html` still carried its **own hard-coded `<nav class="side-nav">` list**, a copy that predated Phases A–C. The 2026-11-02 work had converted it to `can()` *gating* but never to the shared *link list*, so each new module added to `views/layout/header.php` was invisible on the dashboard. Fixed by extracting the definition into **`views/layout/nav.php`** — one array of sections and links, each declaring the `resource.verb` it needs, filtered through `can()` and rendered as the whole `<nav>` — now required by both `views/layout/header.php` (line 115) and `views/dashboard.html` (line 544, which keeps its own inline shell CSS/JS and Chart.js block). A second copy can no longer drift, because there is only one.
   - The link permissions were checked against each route's own gate: `categories.php` and `barcodes.php` require `inventory.view`, `shifts.php` requires `shifts.view`, `payment_methods.php` requires `payment_methods.view`, `admin.php` requires `users.view` — the sidebar never offers a screen the role cannot open, and never hides one it can.
   - `scripts/http_smoke_shift_pos.php` grew a navigation section (45 → **61 checks**): the administrator dashboard lists all five new screens and the same list a standard page draws, every link the sidebar shows for both administrator and cashier answers below 400, a cashier sees fewer links and is not offered Companies & Branches / Users & Roles / System Settings, and a section with no permitted links disappears entirely.

---

## 2026-11-06 — Phase D: Paystack and Flutterwave gateways

Approved decisions for this phase: **build the whole payment path against configuration placeholders plus a mock-verified sandbox** — no live keys exist yet, so nothing in this phase can take real money, and the code that would is the same code that will. A branch is a store, so an online payment taken at a till is attributed to the shift that was open at the moment (`shift_id` on the invoice and its payment row, while that shift is still `open`) — attributed, not counted: a tender whose payment method is not flagged `is_cash` never enters the drawer formula, so a card sale shows in the shift's sale list and non-cash takings, and leaves Expected alone.

### D1 — schema (migrations 012, 013)

**New**

- `migrations/012_payment_gateways.sql` (applied 2026-11-06 07:17:13 after `backups/inventory_pre_012_20261106_071630.sql`)
  - `payment_gateways` — one row per `(company_id, gateway)`; `mode` enum `test`/`live`, `is_enabled`, `sandbox_allowed`, six credential columns, `last_checked_at`/`last_check_result`, `updated_by`.
  - `gateway_transactions` — the payment attempt: merchant `reference` (unique per company), `gateway_reference`, `checkout_url`, nullable `invoice_id`, `branch_id`, `shift_id`, `user_id`, amount/currency, `status` enum `pending`/`paid`/`failed`/`expired`/`refunded`/`partial_refund`, `channel`, `authorization_code`, `failure_reason`, `simulates_payment`, `payload` (cart snapshot), `provider_response`, `verified_at`/`finalised_at`.
  - `gateway_events` — each verified callback with `signature_valid`, `processed`, `result`, `payload` and `dedupe_key CHAR(64)` under `uq_gateway_event_dedupe`: a redelivered event is a unique-key hit, not a second sale. An unsigned body never reaches this table — it is answered at the door.
  - `gateway_refunds` — refunds as documents of their own, so a posted invoice is never rewritten.
  - `payment_methods.gateway` — an online tender must name the provider that takes the money.
  - `gateways.view/update/verify/refund` permissions with 12 role grants (owner all four; manager and accountant view/verify/refund; approver and auditor view; **cashier none**).
  - Repair: `ALTER TABLE settings DROP INDEX `key``. `settings` was company-scoped by `uk_settings_company_key`, but carried a bare UNIQUE on `key` from the original schema — the second company to save any application setting failed with a duplicate-key error. `idx_settings_key` still covers the lookup.
- `migrations/013_gateway_provider_id.sql` (applied 2026-11-06 07:22:11) — `gateway_transactions.provider_id`, because a refund names the provider's own numeric id rather than our merchant reference.

### D2 — service layer and secret storage

**New**

- `includes/crypto_functions.php` — `secretEncrypt()` / `secretDecrypt()` / `secretIsEncrypted()` / `secretHint()` over AES-256-GCM. The envelope is `v2:<iv>:<tag>:<ciphertext>`, all base64. The data key comes from `APP_KEY` in `.env`; a host without one mints `storage/keys/app.key` (a directory the web server already refuses), so a local XAMPP install works without manual setup and still never stores a credential in the clear. `APP_KEY` present-but-empty is refused as the deployment mistake it is rather than silently minting a key. `appIsProduction()` lives here too.
- `includes/gateway_functions.php` — 50 functions, and the only place that talks to a provider:
  - **Provider table** — `gatewayProviders()` states, per provider, the endpoints, key prefixes, webhook header and algorithm (`x-paystack-signature` HMAC-SHA512 vs `verif-hash` plain compare), amount unit (minor vs major), success statuses and event names. `gatewayBaseUrl`, `gatewayAmountInProviderUnits`, `gatewayProviderLabel`, `gatewayValidateKey`.
  - **Configuration** — `gatewayConfigRow`, `gatewayResolvedConfig` (decrypts for the service layer only), `gatewayRuntimeMode` (`live`/`test` with a secret key, `sandbox` when a test-mode gateway has no key of its own and the host allows simulation, otherwise `unavailable`), `gatewaySimulationPermitted`, `gatewaySandboxWarning`, `gatewayConfigViews` (hints, never credentials), `gatewaySaveConfig`, `gatewayEnsureOnlineMethod`, `gatewayWebhookUrl`, `gatewayTestConnection`.
  - **Sessions** — `gatewayNewReference` (`PAREF-20261106-103415-002AC7`), `gatewayCreateTransaction`, `gatewayInitialize`, `gatewayTransaction`, `gatewayTransactionByReference`, `gatewayCompanyIdForReference`, `gatewayUpdateTransaction`, `gatewayListTransactions`, `gatewayExpireStale`.
  - **Verification and posting** — `gatewayHttpRequest`, `gatewayNormaliseVerify`, `gatewayVerify`, `gatewaySettlementVerdict`, `gatewayQuoteSnapshot`, `gatewayFinaliseSale` (`FOR UPDATE` claim, re-priced snapshot, invoice under `idempotency_key = 'gw:<reference>'`, audit for the owning company and user).
  - **Webhooks** — `gatewayVerifySignature`, `gatewayNormaliseEvent`, `gatewayHandleWebhook`, `gatewayApplyRefundEvent`, `gatewayListEvents`.
  - **Refunds and reconciliation** — `gatewayRefundedTotal`, `gatewayIssueRefund`, `gatewayRefreshRefundStatus`, `gatewayListRefunds`, `gatewayReconcile` (totals `captured`/`refunded`/`posted`/`unposted`/`simulated_captured` and six buckets: `paid_not_posted`, `posted_not_paid`, `amount_drift`, `duplicate_capture`, `stale_pending`, `refund_mismatch`).
  - **Till-facing** — `gatewayOnlineMethods`, `gatewaySummaryForUser`, `gatewayPosSession`, `gatewayPosStatus`, `gatewaySandboxRespond`, and `gatewayAudit` for the hand-stamped trail a sessionless callback needs.
- `docs/ENVIRONMENT.md` — `APP_KEY` and `GATEWAY_SANDBOX` documented; `.env.example` ships `GATEWAY_SANDBOX=false`.

### D3 — workspace, callback route, tender registry

**New**

- `payment_gateways.php` + `views/payment_gateways.html` — the gateway workspace: per-provider configuration cards (enable, mode, simulate switch, credential fields that show `set`/hint state and keep a blank field unchanged, a per-field forget flag), the payment-session register with status/provider/reference/date filters and a session detail page (cart snapshot, provider responses, refund form, verify/expire actions), the reconciliation panel (six buckets with reasons, totals including simulated money, "expire pending older than N minutes") and the provider-callback journal (newest 25 verified events, with what the app did about each). POST verbs: `save_config`/`test` → `gateways.update`, `verify`/`reconcile`/`expire` → `gateways.verify`, `refund` → `gateways.refund`; every GET requires `gateways.view`.
- `gateway_webhook.php` — `POST /inventory/gateway_webhook.php?gateway=paystack|flutterwave`. No session, no CSRF token (a provider cannot hold one): authentication is the HMAC over the raw body checked against that company's stored secret before any field is trusted, and the reply is plain text with a status code the provider understands — 405 for GET, 404 unknown provider, 401 missing/invalid signature, 400 unreadable payload, 500 when processing must be retried. An exception is logged, never echoed.
- `views/gateway_sandbox.html` — the simulator that stands in for a provider's hosted page (see D4).
- `scripts/http_smoke_gateways.php` — routes over Apache.

**Changed**

- `payment_methods.php` + `views/payment_methods.html` — the tender registry learned the `gateway` column: an online method names its provider, and `gatewayOnlineMethods()` is the only thing that decides whether the till offers it.
- `views/layout/nav.php` — **Payment Gateways** under Administration, beside Payment Methods (`gateways.view`).
- `includes/sales_functions.php` — `posCheckout()` refuses a card tender outright: *"… is a card payment: take it with the online payment button, which asks the gateway before anything is recorded as a sale."* A gateway-posted invoice takes its tender from the snapshot method and carries the payment reference as `reference_number`.

### D4 — the till takes a card

**New**

- `sales.php` — four more gateway paths on the existing POS route, no new screen:
  - JSON `gateway_init` → `gatewayPosSession()` (quotes the server-side cart, refuses an empty basket, a non-online tender, and no open shift when `pos_requires_shift` is on) and JSON `gateway_status` → `gatewayPosStatus()`.
  - Non-JavaScript `pos_action=gateway_init`, which opens the session and `header('Location: …')` to the provider URL.
  - `action=gateway_return` — the provider's redirect target. It calls `gatewayVerify()` and only reaches a receipt when the verification says posted with an invoice id; anything else goes back to the register with `?payment=<reference>` so the cashier can look at the held money.
  - `action=gateway_sandbox&reference=…` — the simulator page, gated by `sales.create` + `require_post_csrf()` + company scope, with `approve` / `decline` / `timeout` as the only outcomes.
- `views/gateway_sandbox.html` — says in its own header that it is **not** the provider's site, shows the amount the server quoted (not editable on the page), lists the cashier's cart, asks for no card details, and closes by stating that "Pay now" does not complete a sale.
- `assets/js/pos.js` — the card panel: `data-gateway` on the tender options, "Take card payment" (opening the provider page, with a visible link if the popup is blocked), "Ask the gateway again", a 4 s status poll, and `doCheckout()` refusing while a card row is in the tender list. It resumes a session from `?payment=REF` after a page reload.
- `views/sales.html` — `#posOnline` panel inside the tender card, provider-labelled tender options, and a `<noscript>` card form so the flow survives without JavaScript.

**Behaviour worth knowing**

- The only gateway fact the browser is ever given is which tender is a card tender and who runs it (`data-gateway="paystack"`). No key, no webhook secret, no hint reaches a page — asserted by the harnesses against the rendered HTML.
- A card session is per basket: the same cart at the same total reopens the session already waiting (`reused`), a changed cart retires the old one as `expired` and quotes a fresh amount. Pressing the button twice cannot queue two payments.
- The simulator is refused when the row's own `mode` is not `sandbox` ("answered by that gateway"), when the host stops permitting simulation, and when the gateway has been switched off since the session opened — even for a session that gateway opened itself.
- Money that does not match the sale is held, not posted: a price change while the customer was paying lands as `price_drift` with the reason on the row, a short or wrong-currency settlement as `amount_mismatch` / `currency_mismatch`. Both are counted in `unposted` by reconciliation, so held money is visible instead of lost.

### D5 — verification and this documentation

- `scripts/test_gateways.php` — **64 checks** at the service layer: credentials and runtime selection, session-not-a-sale, settlement holding, unpaid sessions, refunds, webhook trust, simulator gates, audit tenancy.
- `scripts/http_smoke_gateways.php` — **100 checks** over Apache, of which the till path added in D4 is **42**: the `views/sales.html` panel and no-JS form, the card checkout refused on both paths, session quoting and tamper resistance, the simulator page, decline/approve, one invoice, replay, a signed webhook, the return redirect and the receipt.
- `docs/DATABASE.md`, `docs/MIGRATIONS.md`, `docs/ARCHITECTURE.md`, `docs/ADMIN_GUIDE.md`, `docs/USER_GUIDE.md`, `docs/TEST_REPORT.md`, `docs/OUTSTANDING_ISSUES.md` and `docs/ENVIRONMENT.md` updated for Phase D (`APP_KEY`, `GATEWAY_SANDBOX`).

**Bugs found and fixed during verification**

1. `gatewaySaveConfig()` audited a configuration written for the first time against `payment_gateways` **id 0** — it used the pre-insert snapshot, so the trail named no row. The row is now read back and the id recorded; `scripts/test_gateways.php` asserts a first write is audited against the row it created.
2. `gatewayTestConnection()` wrote its `READ` audit with a hard-coded `entity_id` of 0 for the same reason. It now uses the id the resolved config already carries. (Both leaks left 42 orphaned audit rows in the workshop database, which were removed; the audit total returned to its pre-session value of 97.)
3. `includes/sales_functions.php` called `getStockLevels()` — defined in `includes/inventory_functions.php` — without declaring that dependency. It worked only because every web entry point happens to load both; the first CLI caller of `posAddItem()` died with *"Call to undefined function getStockLevels()"*. `sales_functions.php` now requires `inventory_functions.php` beside its other requires.
4. `gatewaySimulationPermitted()` is the environment's answer, not the gateway's: this workshop host sets `APP_ENV=production` with `GATEWAY_SANDBOX=true`, so a first harness assertion (`permitted === !production`) was wrong about the code rather than the code being wrong about the rule. It is now asserted as `!appIsProduction() || GATEWAY_SANDBOX`, alongside `gatewaySandboxWarning()` being non-empty in exactly that situation.
5. Test-harness only, worth recording because each one was a false pass waiting to happen: the signature header in `request()` was being passed in the POST-fields slot, so "a payload signed with the wrong key is rejected" was silently identical to "no signature"; a `sales_invoices WHERE invoice_id > mark` sweep in the CLI teardown could have deleted a concurrent user's sale and now deletes only the invoices named by the run's own payment rows; and three counts were global rather than scoped to the run's own high-water marks.

**Not verified by automation:** the POS JavaScript panel was checked statically (`node --check`, every `getElementById` id present in the rendered view) and its server endpoints were exercised over Apache, but no browser click-through was performed — this environment has no browser tool. Recorded in `OUTSTANDING_ISSUES.md` §6.2.

---

## 2026-11-06/07 - Phase E: receipts and deep sales analytics

### E1 - receipt printing

- `migrations/014_receipt_printing.sql` - `customer_payments.tendered_amount` records the note handed over (separate from `amount`, which stays the sum applied to the bill), so a reprint can still show the change; `PRINT` joins the `audit_log.action` enum.
- `includes/receipt_functions.php` - one payload builder for HTML and PDF, 58/80 mm text layouts, logo preparation (stored outside the web root, flattened to a slip-sized JPEG), reprint detection and a `PRINT` audit row that records format, paper, totals and change. `receiptPdfBytes()` writes the PDF itself, so no PDF extension is needed.
- `sales.php` + `views/receipt.html` - `action=receipt` serves the slip, `format=pdf` downloads it, `width` switches paper; `views/system_settings.html` gained the paper, footer and logo controls.
- `scripts/test_receipts.php` (70 checks) and `scripts/http_smoke_receipts.php` (31 checks).

### E2 - deep sales analytics

**New**

- `migrations/015_sales_analytics.sql` - `financial_reports.report_type` widened with `sales_analytics`. No new table: the report is computed at read time from invoices, lines and payment rows.
- `includes/sales_functions.php` - `salesRevenueStatusSql()` is the single rule for what counts as a sale (`pending`, `partial`, `paid`; never `draft` or `cancelled`).
- `includes/report_functions.php` - `reportAnalyticsDimensions()` and `reportSalesAnalytics()`:
  - gross sales, line discounts, header discounts, net, tax, COGS, gross profit, margin, markup, units, transactions and average sale;
  - breakdown by day, product, category, branch or cashier, plus an optional branch filter;
  - invoice-level discount and tax allocated pro rata by line net, so every dimension adds back up to the bill and `net + tax = invoiced`;
  - tender mix read from `customer_payments` (never the `split` header), with cash and non-cash totals;
  - collection view: money received in the period (dated by payment), invoiced in the period, outstanding on the period's bills and the count of unpaid bills.
- `report.php` + `views/report.html` - the report accepts `group_by` and `branch_id` as view choices (not stored on the report), and renders the analytics panel: headline cards, the breakdown table, tender mix and collection.
- `scripts/test_sales_analytics.php` (36 checks) and `scripts/http_smoke_sales_analytics.php` (16 checks).

**Changed**

- `includes/dashboard_functions.php` - `getDashboardSummary()` and `getDashboardMonthlyTrend()` now use the shared revenue rule, so a draft or cancelled bill can no longer inflate this month's sales or the trend chart; the dashboard and the analytics report agree on the same period.

**Behaviour worth knowing**

- A payment against an older invoice is cash received this period, but the older invoice is not invoiced again; outstanding covers only the invoices dated in the selected period.
- Cancelled bills are excluded from both sides, so money recorded against a cancelled bill cannot reappear as revenue.
- An empty period returns zeroes and no rows, and an unknown `group_by` falls back to `day` rather than erroring.

**Verification**

- `php scripts/test_sales_analytics.php` - 36 passed, 0 failed.
- `php scripts/http_smoke_sales_analytics.php` - 16 passed, 0 failed.
- Receipt regression: `test_receipts.php` 70 passed, `http_smoke_receipts.php` 31 passed.
- Database returned to its pre-run baseline: 1 invoice, 1 line, 1 payment, 0 reports, 5 products, 1 branch, 4 users, audit log 98.

---

## 2026-11-07 - Phase F: QR Code Scan-to-Pay

**New**

- `migrations/016_qr_payments.sql` - `qr_payment_points`: a company-scoped pay point with an optional branch and payment method, a unique-per-company `code`, `scan_url`, `amount_type` enum `open`/`fixed`, `amount`, `ecc` enum `L/M/Q/H`, description, active flag, sort order and audit stamps. Foreign keys to `companies` (CASCADE), `branches`/`payment_methods`/`users` (SET NULL). `qr_payments.view/create/update/delete` permissions and 18 role grants (83 permissions, 299 grants in total). Applied 2026-11-07 09:03:33 after `backups/inventory_pre_016_20261107_090322.sql`.
- `includes/qr_payment_functions.php` - validation (`qrPaymentCodeIsValid`, `qrPaymentUrlIsValid`), the payload builder, the SVG renderer, list/get/save/toggle/delete and audit snapshots. The QR is rendered from the stored row by `barcode_lib.php`; a destination that cannot fit a version 1-10 symbol is refused before storage.
- `qr_payments.php` + `views/qr_payments.html` - the admin workspace: a register with QR thumbnails, create and edit cards sharing one field set, an on-page preview with the encoded destination, full-size preview and SVG download routes, activate/deactivate and delete, a fixed-amount toggle, and CSRF/permission gates on every write.
- `views/layout/nav.php` - **QR Scan-to-Pay** under Administration (`qr_payments.view`).
- `scripts/test_qr_payments.php` (46 checks) and `scripts/http_smoke_qr_payments.php` (42 checks).

**Behaviour worth knowing**

- A pay point is a destination, not a payment: the QR encodes `scan_url`, and the provider or bank behind that link takes the money. Nothing in this module writes a sale, payment or receivable.
- An open-amount point encodes the link unchanged; a fixed-amount point appends `amount` and `reference` to an http(s) link, preserving an existing query string and fragment. Other URI schemes are left alone because they do not share the URL query convention.
- Deactivation is the normal removal. Hard delete is allowed while no document references the point; a future FK turns that into a friendly "deactivate it instead" message rather than a database error.
- The QR is drawn on demand, so the printed code always matches the record; no image bytes are stored.

**Verification**

- `php scripts/test_qr_payments.php` - 46 passed, 0 failed.
- `php scripts/http_smoke_qr_payments.php` - 42 passed, 0 failed.
- `php -l` over the new controller, library, view and nav - clean; the view's script block passes `node --check`.
- Database returned to its pre-run baseline: 1 invoice, 1 line, 1 payment, 0 reports, 5 products, 1 branch, 4 users, 0 QR points, audit log 99 (the 99th row is a pre-existing browser `LOGIN`, not created by the tests).

---

## 2026-11-07 - Preloader scope (2.0.1)

The workspace preloader no longer appears on every navigation. It is now opt-in:

- **Homepage** and **dashboard** keep their existing branded loader on load.
- **Sidebar links** leave a short-lived `sessionStorage` flag (`inv:navLoader`, 15-second window) so the page they open shows the loader; modified clicks (new tab/window) do not set it.
- **Login** shows a light overlay while the sign-in POST is in flight; **logout** holds the workspace loader while the sign-out POST is in flight.
- Every other page load — a Save button, an Edit link, a filter, a redirect — renders immediately with no overlay.

Implementation: `assets/css/app.css` hides `#loader` by default and shows it only under `html.loader-on`; `views/layout/header.php` consumes the flag in `<head>`; `assets/js/app.js` and the dashboard's inline script set it on sidebar clicks; `views/login.html` gained its own submit overlay. `APP_VERSION` moved to `2.0.1` so the changed CSS/JS bypass the browser cache.
