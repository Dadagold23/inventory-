# Testing Report

**Project:** Inventory, Sales, Expenses, Income, Approval and Financial Management System
**Location:** `C:\xampp\htdocs\inventory` (`http://localhost/inventory/`)
**Test date:** 2026-11-02 (baseline repair), re-verified 2026-11-05 (Phase A/B), 2026-11-06 (Phase C — see §16 and §17), 2026-11-07 (Phase F — see §20)
**Environment:** PHP 8.2.12 (XAMPP), Apache 2.4, MariaDB 10.4.32, Windows 10 x64

---

## 1. What was tested

| Layer | Method | Result |
|---|---|---|
| PHP syntax | `php -l` on every application PHP file (70 files) | **Pass** — no syntax errors |
| Page rendering | HTTP GET of 19 main module routes with an authenticated session | **Pass** — all 200, no PHP error output |
| Internal navigation | Crawl of 84 unique internal links found in rendered pages | **Pass** — all 200 or expected 302 |
| Access control to internals | Direct GET of `views/`, `includes/`, `config/`, `scripts/`, `check_schema.php`, `test_module4.php` | **Pass** — all 403 |
| CSRF enforcement | POST without `csrf_token` to inventory | **Pass** — 403 after correction |
| Inventory write flow | Create, receive, sell, adjust, edit, deactivate via HTTP forms | **Pass** — exact stock/value expected |
| Inventory guards | Over-sale and over-large negative adjustment | **Pass** — both rejected, data unchanged |
| System settings | Add, update, deactivate, delete via HTTP form | **Pass** |
| Bank account flow | Create, deposit, edit, deactivate, detail view | **Pass** (executed earlier in the session) |
| Company structure | Branch and department create + soft deactivate; company save | **Pass** (executed earlier in the session) |
| Approval lifecycle | Single-step approve, multi-step partial approve, duplicate approval block, reject | **Pass** (executed earlier in the session) |
| Sales cancellation guard | CLI fixture: cancel invoice with payment vs without payment | **Pass** |
| RBAC enforcement | Temporary manager/staff accounts: 18-page access matrix plus allowed/denied POST actions | **Pass** |
| Cleanup | Final row counts compared with pre-test seed baseline | **Pass** |

This is HTTP/CLI integration testing. It is **not** browser automation and it does not replace a manual UI pass on every screen.

## 2. Static checks

```text
LINT OK: 70 files
```

No PHP syntax errors. Earlier audit found 12 failing files; all now compile.

## 3. Page crawl

The following routes returned HTTP 200 with no `Fatal error`, `Uncaught` or generic error page:

```text
/dashboard                       /company?action=list
/inventory?action=list           /sales?action=list
/bank?action=list                /expense?action=list
/income?action=list              /ap?action=list
/payment_made?action=list        /payment_received?action=list
/customer_receivables?action=list /ledger?action=list
/report?action=list              /tax?action=list
/payroll?action=payroll          /audit?action=list
/approval?action=list            /system_settings?action=list
/auth?action=change_password
```

`index.php` serves the landing page (`views/home.html`, with the sign-in card) at the root (`/inventory/`, `/inventory/index`, `/inventory/index.php`) with HTTP 200; a signed-in user is redirected to `/dashboard`.

## 4. Link crawl

84 distinct internal links were extracted from the rendered pages and requested. All resolved with 200 or an expected 302 redirect. Static assets (`assets/css/foundation.css`) returned 200.

## 5. Inventory end-to-end (executed)

Fixture product: `QA-INV-001`, category 1, cost 20.00, price 26.00, reorder level 4, company 1, default branch 1.

| Step | Action | Expected | Observed |
|---|---|---|---|
| 1 | Create product | Product row + zero-quantity location | product `#4`, location `#3`, 0 on hand |
| 2 | Receive 10 @ 20.00 | 10 on hand, value 200 | 10 on hand, value 200 |
| 3 | Sell 2 | 8 on hand, value 160 | 8 on hand, value 160 |
| 4 | Adjust −1 @ average cost | 7 on hand, value 140 | 7 on hand, value 140 |
| 5 | Sale of 100 | Rejected, no movement | rejected; still 7 / 140, 3 transactions |
| 6 | Adjustment of −100 | Rejected, no movement | rejected; still 7 / 140, 3 transactions |
| 7 | Edit name/price/reorder | Persisted | name, price 26.00, reorder 4 persisted |
| 8 | Deactivate | `is_active = 0`, history retained | inactive; location and 3 transactions retained |

Movement log after steps 2–4:

```text
receiving   10.0000 @ 20.0000 =  200.0000
sale         2.0000 @ 20.0000 =   40.0000
adjustment  -1.0000 @ 20.0000 =  -20.0000
```

All three rows recorded `user_id = 5`, the authenticated QA user.

## 6. CSRF

- POSTing an inventory action without a token returned **403** with body `Invalid or expired CSRF token...`; no data changed.
- While verifying, the previous non-standard status code `419` was emitted by PHP but observed by the client as **500** on this XAMPP stack. The helper now uses **403**, which is standard and was verified.

## 7. Sales cancellation guard (executed)

A CLI fixture created one customer, a partially paid invoice (10.00 total, 5.00 paid and a payment row) and an unpaid invoice.

```text
paid_cancel=false   paid_status=partial
unpaid_cancel=true  unpaid_status=cancelled
```

- Cancelling an invoice that has payments is refused and leaves the invoice untouched.
- Cancelling an invoice with no payments succeeds, restores stock (no stock lines in this fixture) and writes the cancellation status.
- All fixture rows were deleted afterwards; zero QA customers/invoices remained.

## 8. Other flows verified earlier in the session

- **Bank:** created an account with opening balance 100, recorded a deposit of 50 (balance 150), edited the name (balance preserved), deactivated it (fields preserved), and confirmed the transaction appears on the detail page.
- **Company:** created and soft-deactivated a branch and a department; saving the company with unchanged values left data intact.
- **Approval:** created single-step and multi-step requests; approved a single-step request; recorded a partial approval on a multi-step request; a second approval by the same user was blocked; a request was rejected; the detail page showed the action history.

## 9. Fixture cleanup and data preservation

After testing:

| Entity | Count |
|---|---|
| Products | 2 (original seed) |
| Product locations | 0 |
| Inventory transactions | 0 |
| Banks | 2 (original seed) |
| Bank transactions | 0 |
| Users | 1 (`admin`) |
| `audit_log` rows | 20 (includes verification logins) |
| `system_settings` rows | 9 |
| Approval requests / actions | 0 / 0 |
| Applied migrations | 7 |

The QA user (`qa_verifier`, id 5), QA product/location/transactions, QA bank/transaction, QA settings and QA audit rows were removed. No production table was dropped, renamed or truncated.

## 10. Log review

`storage/logs/app.log` was checked after the crawl and flows. No new entries were written during the verified requests. The last historical entries predate the final fixes (e.g. the old `bin_code` query and an earlier settings return-type error); both code paths were subsequently corrected and exercised successfully.

## 11. Test limitations

- No automated browser test suite; interactive behavior (modal open/save, tab switching, dropdown menus) was not driven by automation. Headless Chrome was used for visual screenshots of the refactored shell (section 15), but clicks and form submissions were not scripted.
- The `admin` password is not known to this environment and was deliberately not reset. Tests used an admin session established with the credentials supplied for this environment; no password was written to documentation.
- RBAC is enforced; the matrix was exercised with temporary `manager` and `staff` accounts created for the test and removed afterwards (section 14).
- CRUD for expenses, income, AP, tax, payroll and report generation was not exercised end to end; those pages were only loaded and checked for errors.
- There is no PHPUnit/CI suite. The checks above are repeatable shell/HTTP commands, not a committed test harness.

## 12. Reproduce the checks

```bash
# Syntax
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Authenticated page crawl (use a session cookie jar)
curl -s -b cookies.txt -o /dev/null -w "%{http_code}\n" \
  http://localhost/inventory/dashboard

# Migration status
php scripts/migrate.php --status

# Logs
tail -n 50 storage/logs/app.log
```

## 13. Final post-hardening verification

After the final bootstrap, session and environment changes were applied, the following were re-run:

- Full lint: `php -l` across all 70 PHP files — no syntax errors.
- Migration status: `php scripts/migrate.php --status` — all migrations applied, no checksum drift.
- Authenticated crawl: 19/19 module pages returned HTTP 200; 84 internal links returned 200 or 302; direct requests to `.env`, `docs/`, `backups/`, `migrations/` and `storage/` returned 403.
- Create/edit/deactivate product flow re-executed end to end and cleaned afterwards: one product, one location row, three stock transactions recorded with the acting user, then removed. Database returned to baseline (products 2, `product_locations` 0, `inventory_transactions` 0, users 1, audit rows retained).
- No new `storage/logs/app.log` entries were written during those requests.
- Root routing was checked with and without a session: `/inventory/`, `/inventory/index` and `/inventory/index.php` return 200 and render the new landing page (`views/home.html`) for a guest, while a signed-in user gets 302 to `/inventory/dashboard`. The landing page posts to `/inventory/auth?action=login` and signs in successfully from the root URL; sign-out still redirects to `/auth` and returns 302 → `/auth` after a valid CSRF POST. A simulated stale cookie (`inventory_session=<valid>; inventory_session=deadbeef`) previously forced `/dashboard` to 302 back to `/auth`; after the bootstrap fix the same request returns 200 and both legacy cookie paths are expired in the response. The temporary verification accounts were removed and the database returned to baseline.
- The landing page was rendered in headless Chrome/Edge at 1440px, 600px and 390px widths (the 390px view via an iframe, since Windows headless clamps the minimum window width). Desktop shows the two-column hero/sign-in layout; 600px and 390px stack to a single column with the sign-in card below the feature list. Bootstrap and Font Awesome loaded under their SRI hashes, and no server errors appeared during rendering.

Session lifetime was also aligned during this pass: `session.gc_maxlifetime` is now set from `SESSION_LIFETIME`, so authenticated sessions no longer expire against PHP's 24-minute default while the UI advertises 120 minutes.

## 14. RBAC enforcement (executed)

Migration `007_rbac_permissions.sql` seeded **62 permissions**; `manager` received 58 grants and `staff` 20. The `admin` role bypasses permission checks. Two temporary accounts (`rbac_test_manager`, `rbac_test_staff`) were created for this test, signed in with CSRF-protected HTTP requests, and removed afterwards.

**Page access matrix**

| Route | manager | staff |
|---|---|---|
| `/dashboard`, `/inventory`, `/sales`, `/customer_receivables`, `/ap`, `/payment_made`, `/payment_received`, `/expense`, `/income`, `/approval`, `/report`, `/bank` | 200 | 200 |
| `/ledger`, `/tax`, `/payroll`, `/system_settings`, `/company`, `/audit` | 200 | **403** |

**Write actions**

| Case | Result |
|---|---|
| Staff → inventory delete, settings save, company save, payroll process, approval approve, tax create, ledger create | **403** (denied) |
| Staff → inventory update (invalid product), expense create, income create, approval submit | **302** (allowed, validation redirect) |
| Manager → settings save | **403** (denied) |
| Manager → company save, payroll process, approval approve, tax create, ledger create, inventory delete | **302** (allowed, validation redirect) |

- Allowed requests used zero/empty payloads, so no records were created or modified.
- The 403 page rendered “Access denied” with the dashboard link.
- A guest request to `/dashboard` returned 302 → `/auth`.
- The `admin` account still reached all 18 module routes with HTTP 200.
- Final data counts: products 2, expenses 0, income records 0, approval requests/actions 0/0, tax transactions 0, journal entries/lines 0/0, customers 0, users 1, system settings 9. The temporary users and their audit rows were deleted.

## 15. Shared dashboard shell (executed 2026-11-02)

**Implementation**

- New `views/layout/header.php` and `views/layout/footer.php`; shared `assets/css/app.css` and `assets/js/app.js`.
- Converted views: inventory, sales, customer receivables, AP, expenses, income, payments received, payments made, bank, ledger, tax, payroll, approval, report, audit, company, system settings. The dashboard sidebar was also switched to `can()` gating.

**Checks run**

| Check | Result |
|---|---|
| `php -l` on every `views/*.html` and `views/layout/*.php` | No syntax errors |
| Authenticated crawl of 18 pages (`dashboard` + 17 modules) | 18/18 HTTP 200 |
| Grep for `Fatal error`, `Uncaught`, `Warning`, `Notice`, `Deprecated`, `SQLSTATE`, `PDOException` in each response | 0 matches |
| Rendered structure | One `<main class="content">`, one `</main>`, one `</html>` and one Bootstrap bundle per page; no legacy `container`/`navbar` wrappers |
| Page headings / active sidebar link | Correct eyebrow + `h1` + `side-link active` on all converted pages |
| CSRF coverage (sample: bank, company, ledger, tax, system settings) | Every `<form>` has a matching `csrf_token` field |
| CDN SRI hashes (Bootstrap CSS/JS, Bootstrap Icons, AOS CSS/JS, three.js) | 6/6 recomputed hashes matched |
| `assets/css/app.css` and `assets/js/app.js` over HTTP | 200 with correct content type |
| Headless Chrome screenshots (1440×1100 and 1440×1500) of Banking, Companies & Branches and General Ledger | Shared shell rendered: fixed lime-on-dark sidebar, topbar, page header/date chip, dark cards and tables, animated background; no uncaught console errors |

**Limitations**

- The screenshots were taken from server-rendered copies of the authenticated pages served under the application origin, so they validate markup/CSS/JS presentation rather than the authenticated request itself; the authenticated crawl above validates the request path.
- Modal, tab and dropdown interactions were not automated. Their IDs, handlers and Bootstrap bundle placement were verified in the rendered HTML.
- The dashboard still carried its own inline copy of the shell CSS/JS and the Chart.js block; only its sidebar links were changed to use `can()`. **Resolved 2026-11-06**: the link list itself is now shared (`views/layout/nav.php`) — see §17.4.



## 16. Phase B — barcodes (executed 2026-11-05)

**Automated suites**

| Command | Result |
|---|---|
| `php scripts/test_barcode_functions.php` | 39 passed, 0 failed |
| `php scripts/test_barcode_render.php` | 65 passed, 0 failed |
| `php scripts/migrate.php` | `Nothing to migrate.` (010 applied 2026-11-05 20:33:16) |

`test_barcode_functions.php` covers EAN-13/EAN-8 check-digit maths, GTIN normalisation (separator stripping, check-digit rejection, UPC-A and Code 128 fallbacks, 60-character limit), in-store code generation, scan resolution (primary code, SKU, product id, alias table, unknown code, cross-company, branch-scoped stock figure), alias attach / re-primary / detach rules, cross-product collision refusal, primary-code protection, audit rows and fixture cleanup.

`test_barcode_render.php` does not trust the library's own tables: it diffs them against an independent reference (GS1 tables from `jsbarcode`, matrices from the `qrcode` encoder, both forced to byte mode), then decodes the PHP output back with reference tables and samples the rendered SVG rects back into a module grid.

- EAN-13 bitstrings: 6/6 identical to reference (incl. `4006381333931`, `5901234123457`, all-zeros, an in-store `2xx` code, an ISBN and a UPC-A).
- EAN-8 bitstrings: 2/2 identical; Code 128 symbol table: 107/107 patterns identical.
- Code 128 round-trip decode (values + mod-103 checksum recomputed independently): 7/7 payloads recovered.
- EAN-13 round-trip decode through reference L/G/R and parity tables: 6/6 codes recovered.
- SVG geometry: module patterns and quiet zones reproduce exactly for EAN-13, EAN-8 and Code 128.
- QR: **24/24 matrices cell-for-cell identical to the reference encoder** across versions 1–4 and ECC L/M/Q/H once compared at a fixed mask; mask *choice* agreed in 23/24 cases and never scored worse. Self-decode recovered the payload in 12/12 cases.
- Defect found and fixed by this cross-check: the QR finder eye was missing its four corner modules (12 cells per symbol), which breaks scanner detection. Fixed in `barcode_qr_function_map()`; the reference oracle re-run confirms the correction.

The reference oracle lives outside the web root at `C:\xampp\tmp\barcode-oracle` (`oracle.js`, `npm i qrcode jsbarcode`, `node oracle.js`). Without it the render suite prints `SKIP` and exits 0; set `%BARCODE_ORACLE%` to relocate it.

**HTTP checks against `http://localhost/inventory/` (crafted admin session, no password involved)**

| Check | Result |
|---|---|
| `GET /barcodes?action=manage`, `?product_id=1` | 200, workspace + code panel render |
| `GET /barcodes?action=svg&product_id=1` | 200, `Content-Type: image/svg+xml`, 3 845 bytes |
| `GET …&action=labels&ids=1,2,6&copies=2` | 200, 6 label boxes; `paper=sheet` renders the 3-column A4 grid |
| `GET …&action=pack&ids=1,2,6&type=qr` | 200, valid ZIP, 3 SVG entries |
| `GET …&action=lookup&code=…` | barcode → product 1 (`matched_by: barcode`), `WID-002` → product 2 (`sku`), unknown/letters → 404 JSON, spaces tolerated |
| POST without CSRF token | 403 |
| POST `save_code` valid EAN-13 | 302, promoted to primary, in-store code retained as alias (`source = gs1` row added) |
| POST `save_code` bad check digit | refused: “That EAN-13 fails its check digit.” |
| POST `save_code` code owned by another product | refused: “already assigned to another product” |
| POST `set_primary` / `delete_code` | 302; detaching the primary is refused with an explanation |
| POST as a company 2 session against a company 1 product | “Product not found in your company.” and the panel shows nothing |
| `POST /inventory` create product | 302; row created with barcode `2000000000145` + `product_barcodes` alias; flash “Product saved successfully. Barcode 2000000000145” |
| Forced symbology mismatch (`type=ean13` on an alphanumeric code) | clean 404 message, not a 500 |
| Unknown product id on `action=svg` | 404 |
| Regression sweep (22 module screens) | all 200 |

**Data left clean**: `backfill_barcodes.php` populated the 5 pre-existing products (that was the intended change). The probe product, its alias/stock rows and every test alias row were removed afterwards; `product_barcodes` holds exactly one active row per product, no orphans. Barcode audit rows for product 8 and the ZIP export remain as legitimate history.

---

## 17. Phase C — cashier shifts, drawer reconciliation and POS (executed 2026-11-06)

**Automated suites**

| Command | Result |
|---|---|
| `php scripts/test_shift_pos.php` | **75 passed, 0 failed** |
| `php scripts/http_smoke_shift_pos.php` | **61 passed, 0 failed** (45 shift/POS + 16 sidebar) |
| `php scripts/test_barcode_functions.php` (regression) | 39 passed, 0 failed |
| `php scripts/test_barcode_render.php` (regression) | 65 passed, 0 failed |
| `php scripts/migrate.php --status` | `All migrations are applied.` (011 applied 2026-11-05 22:02:57) |
| `php -l` sweep of every application PHP file | 91 files, 0 failures |
| `node --check assets/js/pos.js` | clean |

### 17.1 `scripts/test_shift_pos.php` — service layer (75 checks)

Runs against the live database with the harness setting its own `$_SESSION` actor, and calls `restore_error_handler()` / `restore_exception_handler()` so a PHP fatal prints a trace instead of the app's generic error page. Fixtures: two priced products with known stock, a second branch and a company 2 product for the isolation checks.

- **Opening** — shift opens; the row is `open` with the posted float; the float is *not* also a movement (no double counting); the shift is audited; a second shift for the same cashier + branch is refused; the same cashier may hold a shift at another branch; negative float refused; a shift needs a signed-in cashier; another company cannot read the shift.
- **Movements and the formula** — a fresh shift expects exactly its float; paid in / paid out / cash refund accepted; the worked example `50 + 0 + 20 − 3 − 5 = 62`; zero amount, negative amount, unknown type, movement on another company's shift and movement referencing a foreign invoice all refused.
- **The shift gate** — a cart can be built without a shift; checkout is refused with a message naming the shift; the refused checkout creates no invoice.
- **Server-side pricing and stock** — a tampered `$_SESSION['pos_cart'][0]['unit_price']` is overwritten from `products`; an over-stock quantity is refused at add *and* at checkout; a failed checkout leaves stock untouched.
- **Cash sale** — checkout succeeds; change is the tender above the bill; cart cleared; exactly one invoice; invoice bound to the open shift; invoice fully paid; invoice total matches the server cart; the payment row carries the shift; the cash receipt is booked at the bill, not the tender; receipts and expected cash rise by the bill; stock decremented; one `inventory_transactions` ledger row.
- **Idempotency** — the same key replays the original sale (flagged `duplicate`), adds no invoice, adds no stock movement and does not double the receipts.
- **Split tender** — cash + bank transfer creates two payment rows; the invoice is marked `split`; only the cash half moves the drawer; non-cash receipts are reported separately; a split tender that does not cover the bill is refused; an unknown method (`crypto`) is refused.
- **Cross-company isolation** — another company's product cannot enter the cart; POS search hides it; barcode lookup is scoped to the signed-in company.
- **Closing, voiding and the switch** — closing counts the drawer; variance is counted − expected; the closed shift stores expected cash; closing is audited; a closed shift cannot be closed twice; movements are refused afterwards; a shift with sales cannot be voided; another cashier cannot close my shift; a manager can force close (flagged, variance unknown); an untouched shift can be voided and is gone; `pos_requires_shift = 0` allows a shiftless sale with `shift_id` NULL and the setting is restored; the switch is offered by `appSettingDefinitions()` as a boolean defaulting to on, and `saveAppSettings()` writes it back through the same company-scoped row the POS consults; the summary reports no open shift; `shiftList` filters by status, recomputes totals per row and is company-scoped.

### 17.2 `scripts/http_smoke_shift_pos.php` — routes over Apache (45 shift/POS checks)

Crafted session files in the PHP temp session path stand in for four signed-in users (cashier, a second cashier with no shift, an `auditor` role, a company 2 cashier) plus an anonymous session — no passwords involved. Every fixture (branch, three products, four users, shifts, sales) and session file is deleted afterwards.

- **Guards** — anonymous `GET /shifts.php` → 302 to the login page; the register renders 200 for a cashier; `POST ?action=open` without a CSRF token → 403; the `auditor` role gets 403 on `shifts.create` and `shifts.update` but 200 on the register.
- **Register locked** — POS JSON status reports `open:false, allowed:false`; the HTML shows “Register locked”; a cart can still be built; checkout is refused with a message naming the shift and stores no invoice; an un-tokened POS JSON POST → 403.
- **Shift over HTTP** — the open form redirects to `action=detail&shift_id=…`; the detail page renders the reconciliation block with the float; the printable report renders; a company 2 session is bounced back to the register; an unknown `shift_id` falls back to the register; a `paid_in` movement raises expected cash to 60.
- **POS JSON with a shift open** — status reports the open shift id; search returns the branch product and hides the other company's; scanning a SKU adds one unit; the cart total is built from database prices (expectations recomputed from `default_tax_rate`, so the check does not assume a tax-free database); `set_quantity` above on-hand stock is refused; checkout sells the cart, returns change = tender − bill, clears the cart and binds the invoice to the shift opened over HTTP; a retry with the same key replays the original sale and creates no second invoice; the drawer moves by the bill, not the tender; the shift page lists the new sale by invoice number; stock drops by the units sold.
- **Browser-facing screens** — the POS page mounts `id="posApp"` and loads `js/pos.js`; the open-shift badge shows the shift number; `/assets/js/pos.js` is served (200).
- **No-JS path on the same route** — the classic add-to-cart form posts and redirects; the added line is visible through the JSON status poll on the same session; the classic checkout redirects to `action=receipt&sale_id=…`; that sale is bound to the same shift; the receipt page renders 200.
- **Closing over HTTP** — the close form writes `status='closed'`, records a −3 variance from a short count, and POS is locked again immediately afterwards.

### 17.3 Regression sweep

Authenticated `GET` of 24 module screens — `/dashboard`, `/inventory`, `/categories`, `/barcodes`, `/sales`, `/sales?action=pos`, `/customer_receivables`, `/ap`, `/expense`, `/income`, `/payment_received`, `/payment_made`, `/bank`, `/ledger`, `/tax`, `/payroll`, `/approval`, `/report`, `/audit`, `/company`, `/admin`, `/payment_methods`, `/system_settings`, `/shifts` — **all 200**, and the POS JSON status poll answers with the cart and shift envelope. The Phase B suites re-run green, so barcode lookup and the label/scan endpoints still work with the POS changes in place.

The enforcement switch was also exercised through the real settings form (`POST /system_settings?action=manage_settings`, `app_settings[pos_requires_shift]`): 302, `settings.value` moved `1 → 0 → 1`, and the Application Settings card renders the labelled toggle. `settings.pos_requires_shift` is back to `1`.

### 17.4 Sidebar navigation (added after the Phase C report, 2026-11-06)

The administrator reported that `/dashboard` did not list the screens added in Phases A–C. The cause was a second, hard-coded `<nav class="side-nav">` inside `views/dashboard.html`; `views/layout/header.php` held the current list. The definition now lives in `views/layout/nav.php` and both pages include it. 16 checks were added to `scripts/http_smoke_shift_pos.php` and are driven through Apache with a crafted administrator session (the seeded `admin` user, read from `users` by role — no password involved) alongside the existing cashier session:

| Check | Result |
|---|---|
| `GET /dashboard` as the administrator | 200, sidebar parses to 23 links |
| Sidebar lists Categories, Barcodes, Shifts & Drawer, Users & Roles, Payment Methods | 5/5 present |
| Dashboard sidebar equals the sidebar of a `header.php` page (`/inventory?action=list`) for the same session | identical href→label map — no second list can drift again |
| `GET` every href the administrator's sidebar offers | no 403/5xx; each link is a screen the role can open |
| Administrator section headings | 4 headings, none empty |
| Cashier sidebar vs administrator | fewer links; Companies & Branches, Users & Roles and System Settings absent |
| Cashier sees the new screens its role allows | Shifts & Drawer, Categories, Barcodes present |
| Section with no permitted links | heading not rendered (`Control` disappears for the cashier; `Administration` stays because `payment_methods.view` is granted) |
| `GET` every href the cashier's sidebar offers | no 403/5xx |

Each visible link is fetched rather than matched against a duplicated permission table, so the test asserts the property that matters: **nothing in the sidebar leads a role to a page that refuses it.** A `GET /expense?action=list` timed out once on the first run (transient Apache hiccup, 200 in 0.5 s when repeated directly), so `unopenableLinks()` retries a zero-response request before calling it a fault.

### 17.5 Not verified by automation

- `assets/js/pos.js` was syntax-checked with `node --check` and exercised indirectly — the endpoints it calls, the JSON envelope it expects and the markup hooks it binds to (`id="posApp"`, `data-pos-*`, the open-shift badge) were all verified over HTTP. No browser is available in this environment, so the click-through (scan → cart → tender → success panel) was not driven automatically. Confirm it by hand on `/sales?action=pos` or cover it in Phase F.
- Read-side branch filtering (narrowing lists other than the shift register by `user_branch_access`) remains deferred, as recorded in `OUTSTANDING_ISSUES.md`.

**Data left clean**: both harnesses delete everything they create. After the run `cashier_shifts` holds 0 rows, `shift_movements` 0, `customer_payments.shift_id IS NOT NULL` 0, and no fixture remains (`SHIFT-*` / `SMOKE-*` SKUs, `smoke.%` users, `SHIFT-BR` / `HTTP-SMOKE` branch codes and `smoke%` idempotency keys all count 0). Audit rows were deleted only for the ids the harnesses created; the `Permission denied` entries the negative tests produced stay in `storage/logs/app.log`.

---

## 18. Phase D — Paystack / Flutterwave gateways (executed 2026-11-06)

**Automated suites**

| Command | Result |
|---|---|
| `php scripts/test_gateways.php` | **64 passed, 0 failed** — three consecutive runs, identical counts |
| `php scripts/http_smoke_gateways.php` | **100 passed, 0 failed** |
| `php scripts/test_shift_pos.php` (regression) | 75 passed, 0 failed |
| `php scripts/http_smoke_shift_pos.php` (regression) | 61 passed, 0 failed |
| `php scripts/test_barcode_functions.php` (regression) | 39 passed, 0 failed |
| `php scripts/test_barcode_render.php` (regression) | 65 passed, 0 failed |
| `php scripts/migrate.php --status` | `All migrations are applied.` (012 at 07:17:13, 013 at 07:22:11) |
| `php -l` sweep of every application PHP file | 98 files, 0 failures |
| `node --check assets/js/pos.js` | clean |

No live provider keys exist on this host, so every payment in these suites is a **simulated** one: `simulates_payment = 1` on the row and `mode = 'sandbox'`. The suites assert that, rather than trusting it.

### 18.1 `scripts/test_gateways.php` — service layer (64 checks)

Live database, `$_SESSION` actor set by the harness, `restore_error_handler()` / `restore_exception_handler()` so a fatal prints a trace. Fixtures the run owns and removes: a branch (`GW-TEST`), one priced product (`GW-TEST-1`, 1,000.00, 10 on hand), a customer, a company-2 user for the tenancy check, and the gateway configuration itself — the `payment_gateways` rows found at the start are snapshotted and put back, and the tenders, sessions, refunds, events, invoices, shift and audit rows created after the recorded high-water marks are deleted. A `register_shutdown_function` teardown guarded by a flag makes an aborted run self-healing. The run's invoices are the ones named by **its own** `gateway_transactions` rows (plus any `notes = 'POS sale'` strays it can attribute to itself), never "everything above id N", so a concurrent real sale cannot be swept up.

- **1. Credentials and runtime selection (14)** — simulation is permitted by the environment and not by the gateway's own mode (`permitted === !appIsProduction() || GATEWAY_SANDBOX`), and a production host that still simulates admits it via `gatewaySandboxWarning()`; encrypt/decrypt round-trip with a value that is deliberately not a plausible key; the stored form is neither the secret nor unencrypted; enabling with nothing behind it is refused and leaves the runtime `unavailable`; a test key is not accepted in the live field; a sandbox configuration (public + webhook secret only) is accepted and remembered; no credential is stored in the clear; a test-mode gateway with no secret key of its own resolves to `sandbox`; the service layer can decrypt the webhook secret while the secret key stays empty; `gatewayConfigViews()` hands out hints and `set` flags, never the value; enabling the provider created exactly one card tender; configuring the *other* provider with a test secret key yields runtime `test` with the key decrypting but absent from every view — and no HTTP request is made against it anywhere in the suite; both providers then offer a tender each.
- **2. A session is not a sale (13)** — opening a session writes `pending` with `invoice_id` NULL; the checkout URL is the simulator page; the cart snapshot carries `product_id` and **no** `unit_price`; `gatewayVerify()` on an unanswered session posts nothing; the provider's approval is what writes the sale; the invoice is priced from the catalogue, not from the session; it is paid in full by the card tender with the payment reference as `reference_number`; the payment row ends up tied to the sale and stays `simulates_payment = 1` / `mode = sandbox`; the invoice's idempotency key is `gw:<reference>`; stock moved for the snapshot's quantity; a replay answers "Already recorded" and adds nothing; the run produced exactly one invoice; `gatewayPosStatus()` reports posted with a receipt URL derived from the invoice.
- **3. Money that does not match the sale (5)** — a price change during payment holds the money as `price_drift` with no invoice and a reason a reconciler can read ("The catalogue now totals …"); `gatewaySettlementVerdict()` for an exact settlement, a short one (`amount_mismatch`) and a wrong currency (`currency_mismatch`).
- **4. Never paid (3)** — `decline` → `failed` and `timeout` → `expired`, both with no invoice; a refund against an unpaid session is refused and leaves `gateway_refunds` empty.
- **5. Refunds (5)** — an over-refund is refused with the arithmetic in the message; a partial refund moves the payment to `partial_refund` while the posted invoice stays `paid` at its original total; the refund exists as its own auditable row at the requested amount; `gatewayReconcile()` totals the money it found; simulated capture is reported separately and equals captured on a host with no real settlement.
- **6. A callback announces, it does not prove (6)** — no signature 401; wrong-key signature 401; unreadable body 400 (before anything is parsed); a correctly signed `charge.success` settles the session it names; the same event redelivered is acknowledged with "Already received." and stored once; a signed-looking body with a tampered amount is refused and leaves no event row; an event naming an unknown reference posts nothing.
- **7. The simulator cannot be mistaken for a settlement (11)** — `sandbox_allowed` cannot be taken from an enabled gateway that has no real key behind it (refused, runtime unchanged); switching the provider off stops it recording anything **for a session it opened itself** and its card tender disappears from the till; turning it back on restores both; a row whose own `mode` is `live` is answered by that gateway only; an empty basket and a `cash` tender are both refused by `gatewayPosSession()`; with `pos_requires_shift` on, a session needs an open drawer; the till cannot post a card tender through `posCheckout()` on either the JSON or the no-JS shape of that call, and no invoice appears.
- **8. Tenancy (2)** — a sessionless callback's audit entry is stamped with the company and user that own the payment, not the default company and not the signed-in session (the harness deliberately unsets both before calling `gatewayAudit()`); a second company is left with nothing to find.

### 18.2 `scripts/http_smoke_gateways.php` — routes over Apache (100 checks)

Crafted session files for an administrator, a cashier, an auditor and a company-2 user, plus an anonymous session; a throwaway `gws.*` user set and fixtures, all deleted at the end. High-water marks scope every cleanup, and the run leaves `payment_gateways` exactly as it found it.

| Section | Checks | Covers |
|---|---|---|
| 1. Who may open the screen | 7 | anonymous → login redirect; an administrator of another company gets their own empty workspace; a view-only role can read the register; a role without `gateways.view` is refused; saving configuration without CSRF → 403; a view-only role cannot change configuration; a cashier cannot run a server-side verification |
| 2. The screen renders, and shows no credential | 21 | the workspace renders with both providers, the callback URL and the signature header named for the provider dashboard; credential inputs are write-only boxes; a credential in the wrong format is rejected and writes no row; enabling with nothing behind it is refused; a sandbox configuration is accepted and its runtime is the simulator; nothing is stored in the clear and the rendered page carries no credential value, only a masked hint; enabling created the online tender; a test key cannot be stored as the live key; the connection test answers **without calling the provider** and records the check; a stored credential is forgotten only when asked for |
| 3. The provider callback route | 6 | GET → 405; unknown provider → 404; no signature → 401; forged signature → 401; non-JSON body → 400; and every rejected callback left **no** `gateway_events` row behind |
| 4. Detail and form guards | 14 | a pending session opens its detail page, which says what it waits for and never presents an invoice-less session as a sale; the register lists it; status, provider and reference filters behave; another company cannot read the session; a view-only role cannot verify, refund or expire; a payment the provider never took cannot be refunded; an unknown session falls back to the register; junk filters are answered, not fatal; refunding a payment that is not ours sends you back |
| 5. The gateway field on the payment methods screen | 6 | the screen offers a provider choice; a cash tender cannot be linked; an unknown provider cannot be linked; an online tender can be, and the link is stored; editing a tender through a form that has no gateway field keeps its link |
| 6. The sidebar | 4 | Payment Gateways is listed for the administrator and opens; a cashier is not offered it; a view-only role is offered it and can open it |
| 7. The till | 42 | see below |

**Section 7 in detail** — the card path end to end as a browser would drive it, in a throwaway cashier's session with its own drawer:

`views/sales.html` renders the `#posOnline` panel and marks the tender `data-gateway="paystack"`, while the secrets (`pk_test_…`, `whsec_…`) appear nowhere in the till HTML and the no-JS card form is offered. The branch is taken from the session (read through the `search` op), an orphan-shift sweep runs first, and a shift is opened for the throwaway cashier. With an empty cart, `gateway_init` is refused and creates **no** session row. After `add_item`: the AJAX `checkout` with the card tender is refused with "online payment button", and the no-JS `checkout` form is refused the same way, with no invoice either time. `gateway_init` then records a `pending` session with `invoice_id = 0`, the drawer's `shift_id`, a snapshot that carries `product_id` and no `unit_price`, and a `checkout_url` pointing at `action=gateway_sandbox`. Posting `amount` / `total` / `payment_amount` / `unit_price` = 0.01 with it does not change the quoted amount (same reference, reused). Pressing it twice reuses one row. `gateway_status` says pending and not posted; `gateway_return` on an unpaid session posts nothing; changing the cart supersedes the old session (`expired`) and leaves exactly one pending. The simulator page renders as a Simulator, quotes the server's total (`number_format`), asks for no card details, offers exactly three outcomes, leaks no credential, refuses a foreign company (redirect), refuses without CSRF (403) and refuses the auditor (403). An unknown reference is not followed. `decline` → `failed` with no invoice. `approve` → 302 to `sale_id=N`, exactly one invoice, its total equal to the cart, `created_by` the cashier, the tender on `customer_payments` the card code, `simulates_payment = 1`. `gateway_status` then reports posted with a receipt URL; the cart is empty server-side; a second `approve` returns the same invoice and still one invoice; a correctly signed webhook accepted over HTTP writes one `signature_valid = 1` event and does not double-post; `gateway_return` on the paid reference leads to the same receipt; and the receipt page opens.

### 18.3 Environment and data-preservation evidence

The suites were run against the workshop database with the pre-existing state (one open shift for user 1 at branch 1, one invoice, five payment methods, five products, four users) recorded before and after. After the full Phase D sweep — `test_gateways` ×3, `http_smoke_gateways`, `http_smoke_shift_pos`, `test_shift_pos`, both barcode suites — the counts are back to the recorded baseline:

| Table | After everything |
|---|---|
| `gateway_transactions`, `gateway_events`, `gateway_refunds`, `payment_gateways` | 0 rows (as found: this host has no live gateway configuration) |
| `sales_invoices` | 1 (the pre-existing `INV-20261106-3811`) |
| `payment_methods` | 5 · `cashier_shifts` 1 (open, untouched) · `products` 5 · `users` 4 · `branches` 1 |
| `audit_log` | 97 — the exact pre-session value |

42 orphaned `payment_gateways` audit rows left by earlier runs of the two harnesses (all with `entity_id = 0`, all created today, all naming configurations that no longer exist) were deleted once their cause was fixed at source; the baseline of 97 was re-measured before the confirming runs above.

### 18.4 Defects found and fixed while verifying Phase D

1. **A first-time configuration was audited against row 0.** `gatewaySaveConfig()` took `gateway_config_id` from its pre-insert snapshot, so the trail said `payment_gateways #0` and named no configuration. The row is now read back before the audit is written, and `test_gateways.php` asserts that a first write is audited against the id it created.
2. **`gatewayTestConnection()` hard-coded `entity_id = 0`** in its `READ` audit — the same blind spot, one function over. It now uses the id the resolved configuration already carries. Both were found *because* the harnesses refused to leave a clean audit count behind, which is the property that makes the "zero leftovers" claim meaningful.
3. **`sales_functions.php` used a function it never required.** `posAddItem()` → `getStockLevels()` died with *"Call to undefined function"* from any CLI entry point that did not happen to load `inventory_functions.php` first. The dependency is now declared beside the file's other requires.
4. **Two harness assertions were false passes waiting to happen.** `request()`'s signature header was being passed in the POST-fields slot, so "a payload signed with the wrong key is rejected" had actually been testing "no signature at all" (fixed at both call sites, and section 3 was split into "accepted over HTTP with a journalled event" and "…without double-posting"); and `(int) $row['status'] !== 0` on a string column could only ever have been false. Three counts were also global rather than scoped to the run's own high-water marks, which would have made a rerun after any interruption fail for the wrong reason.
5. **One assertion was wrong about the code, not the code wrong about the rule:** `gatewaySimulationPermitted()` is `!appIsProduction() || GATEWAY_SANDBOX`, and this host sets `APP_ENV=production` with `GATEWAY_SANDBOX=true`. The test now states the real rule and also requires `gatewaySandboxWarning()` to be non-empty in exactly that configuration.
6. **The migration 012 header comment overstates `gateway_events`** ("every callback … signed or not"), while `gatewayHandleWebhook()` correctly refuses an unverified body before storing it — proven by section 3's "every rejected callback left no event behind". An applied migration is not edited, so the mismatch is recorded in `OUTSTANDING_ISSUES.md` §6.3.

### 18.5 Not verified by automation

- **No browser click-through of the POS card panel.** `assets/js/pos.js` passes `node --check`, every `getElementById` it uses exists in the rendered `views/sales.html`, and all the endpoints it calls were exercised over HTTP in section 7 — but the popup-open, the 4-second poll and the success panel were never driven in a browser, because none is available in this environment. Recorded in `OUTSTANDING_ISSUES.md` §6.2.
- **No real provider traffic.** No request was ever sent to `api.paystack.co` or `api.flutterwave.com`: there are no keys, and the suites assert that fact rather than assume it. `gatewayHttpRequest()`, the live `initialize`/`verify`/`refund` request bodies and each provider's response normalisation are therefore untested against the real API; `test_gateways.php` covers the `'test'` runtime only up to the point where a call would leave the machine.
- **No TLS/hostname concerns** such as certificate pinning or webhook replay windows beyond the dedupe key.
- **Refund status refresh against a provider** (`gatewayRefreshRefundStatus`) is exercised only through the recorded-row path, since a real refund needs real money.

---

## 19. Phase E - receipts and deep sales analytics (executed 2026-11-06/07)

### 19.1 Results

| Command | Result |
|---|---|
| `php scripts/test_sales_analytics.php` | **36 passed, 0 failed** |
| `php scripts/http_smoke_sales_analytics.php` | **16 passed, 0 failed** |
| `php scripts/test_receipts.php` (regression) | **70 passed, 0 failed** |
| `php scripts/http_smoke_receipts.php` (regression) | **31 passed, 0 failed** |
| `php scripts/migrate.php --status` | `All migrations are applied.` (014 at 12:48:55, 015 at 15:48:06) |

### 19.2 `scripts/test_sales_analytics.php` - service layer (36 checks)

Fixtures the run owns and removes: a branch (`ANA-TEST`), two priced products, a customer, and six invoices - a discounted/taxed two-line cash sale, an unpaid sale, a split tender, a draft, a cancelled sale and a sale dated last month paid today. A `register_shutdown_function` teardown deletes only the invoices above the recorded high-water mark plus the run's own products, customer and branch.

- **What counts as a sale (6)** - the three real bills count and the draft/cancelled ones do not; `salesRevenueStatusSql()` is exactly `pending|partial|paid`; the dashboard summary and the monthly trend agree with the analytics report for the same month, so a draft or cancelled bill cannot lift either.
- **The bill reconciles (11)** - net = bill minus header discount; `net + tax = invoiced`; gross - line discounts - header discount = net; COGS/profit/margin come from the lines; units, transactions and average sale agree. For all five dimensions the rows add back up to the summary in net, tax, units, COGS, gross and discounts.
- **The dimensions say what they claim (10)** - product, category, branch, cashier and day labels; per-product units/net/margin; header discount and tax shared across lines (20.00/18.00 and 5.00/4.50); unknown dimension falls back to day; branch filter excludes other branches while the company-wide view includes them.
- **The money came from the payment rows (5)** - the split sale's header says `split` while the tender mix lists both methods; cash 287.50 / non-cash 60.00; collected equals the payment rows; outstanding is the unpaid bill and its count.
- **A payment belongs to its own day (3)** - a payment against an older bill is cash this period but the older bill is not invoiced again; in its own period it is invoiced, settled and has no collection.
- **Nothing is invented (2)** - an empty period returns zeroes, no rows and no tenders; margins and averages are 0 rather than division errors.

### 19.3 `scripts/http_smoke_sales_analytics.php` - routes over Apache (16 checks)

Crafted sessions for a company-1 administrator and a company-2 administrator, plus an anonymous request. Fixtures: one branch, one product, one customer, one taxed sale and three `financial_reports` rows (two sales analytics, one income statement), all removed at the end.

- **Who may open it (2)** - anonymous -> login; another company's report is neither named nor shown.
- **The panel renders (7)** - report name and type; all five breakdowns; branch filter with all-branches and the real branch; headline cards; the sale's money; tender mix and collection.
- **View choices are honoured (5)** - product/category/branch selections are marked and change the table; filtering to another branch removes the sale; an unknown dimension falls back to day.
- **A non-analytics report is unaffected (1)** - the income statement ignores `group_by` and still renders its own panel.
- **Teardown (1)** - exactly three reports, one sale, one product and one user were created.

### 19.4 Data preservation

After both analytics suites and both receipt suites, the database is back to its pre-run state: 1 invoice, 1 invoice line, 1 payment, 0 financial reports, 5 products, 1 branch, 4 users, `audit_log` 98. No migration was re-run; 014 and 015 are recorded in `schema_migrations`.

### 19.5 Not verified by automation

- No browser click-through of the report's filter form was performed (no browser tool on this host); the same GET parameters were driven over HTTP and the rendered markup was asserted.
- The analytics report has no CSV/PDF export; the specification asked for the screen and the numbers, not a download.

---

## 20. Phase F — QR Code Scan-to-Pay (executed 2026-11-07)

### 20.1 Results

| Command | Result |
|---|---|
| `php scripts/test_qr_payments.php` | **46 passed, 0 failed** |
| `php scripts/http_smoke_qr_payments.php` | **42 passed, 0 failed** |
| `php scripts/migrate.php --status` | `All migrations are applied.` (016 at 09:03:33) |
| `php -l` on the new controller, library, view and nav | No syntax errors |
| `node --check` on the view's script block | Clean |

### 20.2 `scripts/test_qr_payments.php` — service layer (46 checks)

Live database, `$_SESSION` actor set by the harness, `restore_error_handler()` / `restore_exception_handler()`. Fixtures the run owns and removes: a branch (`QR-TEST`), a tender (`qr_test_tender`), company-2 equivalents for the tenancy checks, and the pay points and audit rows created after recorded high-water marks. A `register_shutdown_function` teardown makes an aborted run self-healing.

- **Validation (13)** — a lowercase code with digits/underscores/hyphens is accepted and uppercase or digit-first codes refused; a one-character code refused; an https link accepted while a bare host is refused; a well-formed non-http scheme accepted; raw whitespace refused; a point without a name, a bad code, a destination without a scheme, or a fixed amount of zero refused; a destination too long for a QR refused *before* storage; a branch or tender from another company refused.
- **Create, read and payload (8)** — an open-amount point is created with its branch, tender and link; an open payload is the link unchanged; the list joins branch and tender names; the amount label says *Open amount*; the point renders an SVG with a `viewBox`; the rendered payload equals the stored destination; the QR decodes back (via `barcode_qr_extract_text()`) to the exact destination.
- **Fixed amounts (4)** — a fixed point is created; the amount and reference are appended to an existing query string while preserving the fragment; the amount is stored as a decimal and labelled; the chosen ECC level is stored; the fixed QR decodes back to the amount-bearing link.
- **Update, uniqueness and tenancy (7)** — a duplicate code in the same company is refused; the same point updates in place (name, amount rule, active flag, ECC); the updated payload carries the new amount; another company cannot read, update or toggle the point; the same code is allowed in another company; each company sees only its own point.
- **Toggle, delete and audit (6)** — reactivate, deactivate without losing the row, an active-only list omits the deactivated point; a point with no history deletes; another company cannot delete it; audit rows record CREATE/UPDATE/DELETE with a non-blank create snapshot.
- **What it refuses to invent (3)** — updating a nonexistent point is refused; a missing point has no payload and no QR; an unknown ECC level falls back to `M` instead of failing the save.
- **Teardown evidence (1)** — the sweep deletes exactly the run's points, branches, tenders and audit rows.

### 20.3 `scripts/http_smoke_qr_payments.php` — routes over Apache (42 checks)

Crafted session files stand in for an administrator, a view-only `staff` user, a company-2 administrator and an anonymous visitor; a throwaway branch, tender, users and points are removed at the end.

| Section | Checks | Covers |
|---|---|---|
| A. Who may open the screen | 8 | anonymous → login; administrator gets 200 with the nav link and create form; the page explains the payload; a view-only staff user reads the screen but is not offered the form and cannot POST (403) |
| B. Create, preview and download | 9 | create redirects to its edit page and stores branch/tender; the edit page shows the saved values, preview, encoded destination and download link; preview returns `image/svg+xml`; download is an attachment named after the code; anonymous preview → login; another company's preview → 404 |
| C. Fixed amount | 4 | a fixed point is created; the amount and reference appear in the encoded destination; it is stored as fixed with its ECC; it previews as an SVG |
| D. Update and toggle | 5 | update redirects back and changes name/amount/ECC/active; an unchecked checkbox deactivates; activate and deactivate both persist the flag; the activation is confirmed |
| E. Validation, CSRF and duplicate codes | 5 | a bad code redirects to the form with its message and keeps the typed name; a duplicate code is refused by name; a POST without CSRF → 403 and writes nothing |
| F. Another company | 5 | another company sees an empty list and cannot open the edit form; a foreign update is refused and leaves the original unchanged; it can create and delete its own point with the same code pattern |
| G. Delete | 2 | both points delete through the screen and disappear from the list |
| Teardown | 2 | no test point remains; the sweep removes the branch, tender, users and audit rows |

### 20.4 Data preservation

After both suites the database is back to its pre-run state: 1 invoice, 1 invoice line, 1 payment, 0 financial reports, 5 products, 1 branch, 4 users, 0 QR points, and `audit_log` 99. The 99th row is a pre-existing browser `LOGIN` (`audit_id 605`, `::1`), not created by either harness; the QR suites remove only the audit ids above their own high-water mark under entity `qr_payment_points`.

### 20.5 Not verified by automation

- **No physical scan.** Correctness was proven by decoding the rendered payload back with the library's own byte-mode decoder (`barcode_qr_extract_text()`) and by the Phase B reference-encoder cross-check for QR versions 1–4; a camera spot-check of a printed code is still worth doing, because print DPI and media affect scannability more than the encoding does.
- **No browser click-through** of the amount-type toggle or the preview card; the server routes and rendered markup were exercised over HTTP and the script block passed `node --check`.
- **No payment is taken or verified.** The module stores a destination and draws it; it never talks to a provider, so no settlement path exists here to test.
