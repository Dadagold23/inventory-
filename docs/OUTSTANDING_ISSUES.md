# Outstanding Issues and Recommended Follow-Up

**Updated:** 2026-11-07
**Scope:** items that remain after Phases 2–10 and the Phase A–F feature work. None of these were silently “fixed” in the database; they need a product or operations decision.

---

## Priority 1 — security and access control

### 1.1 RBAC is now enforced (resolved 2026-11-02)
- Migration `007_rbac_permissions.sql` seeded **62 permissions**, of which `manager` holds 58 and `staff` holds 20; `admin` bypasses checks in `userHasPermission()`.
- Every authenticated controller calls `require_permission()` for page views and maps each POST action to `create`, `update`, `delete` or `approve`.
- Denied requests receive a styled HTTP 403 page (`views/error_403.html`) and are logged to `storage/logs/app.log`.
- Remaining gap: `user_branch_access` is not yet used to scope queries to a user's branches, and there is no user-management UI for assigning `user_permissions` overrides.

### 1.2 Database account and environment
- `.env` currently connects as `root` with an empty password (XAMPP default). `APP_DEBUG` has been switched to `false` and `APP_ENV` to `production` to stop SQL details reaching users.
- Recommendation: create a least-privilege MySQL user for the application and store its credentials in `.env`; keep `.env` outside version control.

### 1.3 CDN dependencies
- The shared layout loads Bootstrap 5.3.2, Bootstrap Icons 1.11.1, AOS 2.3.4 and three.js r128 with `integrity`/`crossorigin`; all six SRI hashes were re-verified against the CDN files on 2026-11-02.
- Font Awesome 6.4.0 (module content icons) and the dashboard's Chart.js are still loaded without SRI.
- Recommendation: vendor the remaining pinned files locally or add `integrity`/`crossorigin` attributes, especially for deployments that must work offline.

## Priority 2 — financial and inventory integrity

### 2.1 No refund workflow for paid invoices
- Invoice cancellation is now **blocked** when `customer_payments` contains a payment for that invoice.
- There is no UI to reverse or refund a payment. Cancellation therefore requires a manual process.
- Recommendation: add a refund/cancellation flow that reverses the payment, restocks once, and records the accounting impact.

### 2.2 Product-panel sales are not customer invoices
- **Inventory → Record Sale** issues stock and writes `inventory_transactions`, but does not create a `sales_invoices` row, receivable or ledger entry.
- Recommendation: label this action clearly as a stock issue, or route it through invoice creation so revenue and receivables stay consistent.

### 2.3 Bank transfers have no destination
- A `transfer` reduces the selected account like a withdrawal; there is no paired inflow and no destination bank field.
- Recommendation: model transfers as linked out/in pairs or a dedicated transfer table.

### 2.4 Report balancing is informational
- The Balance Sheet returns a `balance_check` value but generation is not blocked when it is non-zero.
- Recommendation: decide whether a non-zero check should block “generated” status or only warn.

## Priority 3 — operations and compliance

### 3.1 Missing pre-migration dumps for 003 and 004
- `backups/` contains dumps taken before migrations 005 and 006 only.
- The earlier 003 and 004 migrations were applied before the backup routine was established.
- Recommendation: keep a dump before every future migration and verify restore procedures. Do not attempt to reconstruct the missing dumps.

### 3.2 No automated test suite
- Verification is currently HTTP/CLI commands documented in `TEST_REPORT.md`; there is no committed PHPUnit or browser test suite. Phase A–C work added three self-contained harnesses that create and delete their own fixtures — `scripts/test_barcode_functions.php`, `scripts/test_shift_pos.php` (service layer) and `scripts/http_smoke_shift_pos.php` (routes over Apache) — and `scripts/test_barcode_render.php` cross-checks encoding against an external reference.
- Recommendation: fold these into a single runner with per-module suites, add regression tests for invoice cancellation, payments and approvals, and cover the JavaScript screens with a browser harness.

### 3.3 Shared layout (resolved 2026-11-02)
- All 17 authenticated module views now render through `views/layout/header.php` and `views/layout/footer.php`, sharing `assets/css/app.css` and `assets/js/app.js` with the dashboard. The per-view copies of the navigation, page header, loader, background canvas and shell CSS were removed.
- Sidebar links come from one definition in `views/layout/nav.php`, filtered with `can(resource, 'view')` and included by both `views/layout/header.php` and `views/dashboard.html`. (Fixed 2026-11-06: the dashboard had kept its own hard-coded copy of the link list, so screens added in Phases A–C — Categories, Barcodes, Shifts & Drawer, Users & Roles, Payment Methods — never appeared on it.)
- View-specific scripts are captured in `$pageScripts` and emitted after Bootstrap/AOS/app.js, so modal, tab and ledger-line behavior is unchanged.
- The dashboard page still keeps its own inline copy of the shell CSS/JS and the Chart.js block; only its sidebar was moved onto the shared `nav.php`. New modules should extend the shared layout rather than copy it.

### 3.4 Legacy scripts remain in the web root
- `apply_schema.php`, `check_schema.php`, `create_payment_received.php`, `fix_module13.php`, `test_db.php`, `test_module*.php` are still present, including a script that drops a table on GET.
- They are blocked by `.htaccess` and are not referenced by the application, but a server that ignores `.htaccess` would expose them.
- Recommendation: move them outside the document root or delete them after confirming they are not needed.

### 3.5 Branch selection is implicit
- Inventory transactions use the session branch when set, otherwise the company's first active branch. The UI does not let the user choose a branch.
- Shifts and the POS now resolve the branch deliberately: `shiftResolveBranchId()` validates company, branch and the user's branch assignment, and the POS always uses the session branch — never a posted `branch_id`. Other modules still follow the implicit rule.
- Recommendation: add an explicit branch selector for multi-branch companies, and read-side filtering driven by `user_branch_access` (see the Phase C follow-up below).

## Priority 4 — polish / known limitations

- There is no browser automation; JavaScript behavior (modals, dropdowns, chart rendering) was reviewed but not automatically exercised.
- CRUD flows for expenses, income, accounts payable, tax, payroll and report generation were page-load tested only.
- The application has no localization layer; currency formatting uses the company currency but dates follow the PHP server locale.
- `SESSION_DRIVER` and `FEATURE_*` keys in `.env` are reserved and unused.
- The dashboard chart depends on a CDN; if Chart.js fails to load, the page still renders but charts stay empty.
- `getLowStockAlerts()` only considers products that already have a `product_locations` row; newly created products may show as low stock through other paths (`getProductsLowStock()`), but not in every alert. Review if this matters for reporting.
- The `categories` table currently contains four rows named `General` (ids 1–4) for company 1, inherited from module seeds. Product creation works, but the category dropdown is repetitive. Consolidation needs an administrator decision because existing products reference these rows.

---

## Phase B follow-ups (added 2026-11-05)

### 4.1 Barcode work deliberately left for later phases
- **POS scanning is wired (completed 2026-11-06).** `sales.php?action=pos` resolves scans server-side through the same `bcLookup()` service the workspace uses (`pos_action=scan`), and `GET /barcodes?action=lookup&code=` remains available for entry tests and other clients.
- **No physical scan verification was possible here.** Correctness was proven by comparing every encoding against independent reference implementations (`jsbarcode` tables, the `qrcode` encoder) and by decoding the rendered SVG back into modules. A camera/handler spot-check of a printed 58 mm label is still worth doing on the real printer, because print DPI and shrink affect scannability more than the encoding does.
- **QR support is byte mode, versions 1–10 (ECC L/M/Q/H)** — enough for GTINs, SKUs and short URLs. Kanji, ECI, structured-append and macro segments are not implemented; payloads above roughly 213 bytes are rejected rather than silently truncated.
- **Code 128 subset A is not implemented** (control characters are rejected with a clear message). Subset B/C cover all printable ASCII and pure-digit payloads.
- **`010_barcodes.sql` does not backfill inside SQL** (EAN check digits need arithmetic); `php scripts/backfill_barcodes.php` does it and is idempotent. New releases that import products should run it or save through `inventory.php`, which allocates automatically.
- **Label presets are three fixed sizes** (58×35, 80×40, Avery L7162). Arbitrary label geometry would need a settings table entry; none exists yet, and inventing one was out of scope.
- The reference oracle used by `scripts/test_barcode_render.php` lives outside the web root (`C:\xampp\tmp\barcode-oracle`). It is a development aid, not a deployment dependency — the suite skips itself when it is missing.

---

## Phase C follow-ups (added 2026-11-06)

### 5.1 Known limits of the shift/POS work as it stands

- **The browser click-through was not automated.** `assets/js/pos.js` passes `node --check`, and every endpoint it calls, the JSON envelope it expects and the markup hooks it binds to were verified over HTTP (`scripts/http_smoke_shift_pos.php`), but no headless browser exists in this environment. Scan → cart → tender → success panel should be walked through by hand once, and then covered in the Phase F integration suite.
- **Only POS takings land inside a drawer.** Payments recorded from *Payments Received* or the counter-sales screen are not shift-tagged, so they do not appear in a shift's Expected even when they are cash. That is the agreed boundary (the gate applies to the register), but it means a shop taking counter cash has two cash trails: the drawer report and the receivables ledger. Decide later whether counter cash should be forced through the register or explicitly reconciled.
- **`cash_refund` is a drawer event, not a document.** It reduces Expected, but there is still no refund workflow (see §2.1): refunding a paid invoice remains a manual credit note or an out-of-band action.
- ~~**A split-tender invoice stores `payment_method = 'split'` on the header** while the real methods live on the individual `customer_payments` rows.~~ **Resolved 2026-11-07:** `reportSalesAnalytics()` reads the tender mix from `customer_payments` and never from the header; the harness asserts a split sale shows both methods.
- **The shift register recomputes totals per row** (`shiftTotals()` inside `shiftList()`), which is an extra handful of queries per listed shift. Fine at current volumes; replace with one grouped query if the register page slows down.
- **Force close needs `shifts.delete`,** which also allows voiding an untouched shift. A dedicated `shifts.force_close` permission would separate “end someone's register” from “delete history”. Left as is to avoid inventing a permission the specification did not ask for.
- **`salesSetting()` falls back to company 1's row** when a key is missing for the session company, so `pos_requires_shift`, `default_tax_rate` and friends behave as “company 1 value is the system default”. Intentional and pre-existing, but worth knowing before turning enforcement off for one company only.

### 5.2 Deferred to later phases

- Read-side branch filtering by `user_branch_access` for lists other than the shift register (Phase C decision, recorded in §1.1 and §3.5).
- ~~Receipt formatting for POS sales: 58/80 mm layout, logo, PDF, reprint audit, and consuming `$_SESSION['pos_change_due']` so the printed slip shows the change handed back (Phase E).~~ **Delivered 2026-11-06** - change is now read back from `customer_payments.tendered_amount`; see `docs/CHANGELOG.md` (E1).
- ~~Deep sales analytics (gross/net/COGS/margin, void exclusion, cash-versus-revenue separation) and low-stock notification delivery (Phase E).~~ **Analytics delivered 2026-11-07** (E2); low-stock notification delivery remains open.
- ~~Paystack / Flutterwave server-side verification, signed webhooks, refunds and reconciliation (Phase D).~~ **Delivered 2026-11-06** — see §6 below for what Phase D deliberately left open.

---

## Phase D follow-ups (added 2026-11-06)

### 6.1 No real provider has been contacted

This is the largest open item and it is deliberate: the phase was approved as *configuration placeholders plus a mock-verified sandbox*, and no live keys exist on this host.

- `gatewayHttpRequest()` and the live/test request bodies, response normalisation (`gatewayNormaliseVerify`, `gatewayNormaliseEvent`) and provider-side refund calls have **never executed against Paystack or Flutterwave**. Every check in `scripts/test_gateways.php` and `scripts/http_smoke_gateways.php` is satisfied without a single outbound request, and the suites assert that.
- Consequence: the amount-unit rule (Paystack minor units, Flutterwave major), each provider's event vocabulary and its refund status vocabulary are implemented from documentation, not observed. Before the first real payment, run one sandbox-then-test transaction per provider against a provider test account and store what comes back.
- The connection test (`gatewayTestConnection`) answers from the runtime decision when there is no key; a real read-only call is likewise untested.

**To take this live, in order:** set `APP_KEY`; remove `GATEWAY_SANDBOX=true` (and keep `sandbox_allowed = 0`); store live public + secret + webhook values; point the provider dashboard at `gateway_webhook.php?gateway=…` and confirm a delivery arrives with `signature_valid`; take one small real payment end to end; check it appears in reconciliation with `simulated_captured = 0`.

### 6.2 The POS card panel was not walked through in a browser

Same limitation as §5.1, now with more surface: `#posOnline`, the popup-blocked fallback link, the 4-second `gateway_status` poll, `?payment=REF` resume after a reload, and the success panel are all verified server-side (42 HTTP checks drive the exact endpoints they call), and `node --check` plus an id cross-check against `views/sales.html` passed — but no browser rendered the page. The `<noscript>` card form *is* covered end to end over HTTP. Cover the click-through in Phase F.

### 6.3 Two documentation-versus-code mismatches worth recording

- `migrations/012_payment_gateways.sql` describes `gateway_events` as storing "**every** callback … signed or not". `gatewayHandleWebhook()` refuses an unverified body at the door and stores nothing — proven by "every rejected callback left no event behind". The behaviour is the safer one, and an applied migration is not edited, so the comment stands as history. `DATABASE.md` now documents the real rule. If unverified callbacks ever do need to be kept (for forensics), that needs a new decision about where an untrusted payload may be stored.
- `gateway_events.signature_valid` is therefore written as `1` by every path that inserts a row. It is a stated per-row fact rather than a filter anyone can use. Either start storing rejected events, or drop the column in a future migration — do not leave a column that looks like a security control and cannot vary.

### 6.4 Money held for reconciliation has no workflow behind it

A session that arrives paid-but-unposted (price drift, short settlement, wrong currency) is recorded, reasoned and totalled in `unposted`, and an operator can re-verify it. What does **not** exist yet:

- A way to post a held payment at the price the customer actually paid (a discount/adjustment on the sale), or to refuse it and start a refund, from the workspace. Right now the office re-verifies until it clears or refunds by hand.
- Any notification. Held money, `stale_pending` sessions and `paid_not_posted` buckets only reach a person who opens the screen. Low-stock and payment alerting are both Phase E items and should share one delivery mechanism rather than invent two.
- **Stock is enforced late.** `gatewayFinaliseSale()` re-prices the snapshot from the catalogue and then hands it to the ordinary `createSalesInvoice()`, which applies the normal stock rules — so if the goods went out while the customer was at the gateway, the invoice is refused (`Insufficient stock for …`), the reason is stored on the session, and the money sits captured and unposted in `unposted`. Nothing reserves stock at session creation, and no screen says "this paid customer is waiting for stock". A reserve-at-checkout step (or an alert) belongs with the Phase E notification work.

### 6.5 Smaller known limits

- **Idempotency is per payment reference.** Two deliberate sales of an identical basket to the same customer at the same total reuse the same waiting session by design, which is right for a till and wrong for an API-driven shop. No such caller exists yet.
- **A session's expiry is manual.** `gatewayExpireStale()` runs from the workspace; nothing schedules it. Unbounded `pending` rows will accumulate on a busy till where customers walk away.
- **The provider is told about the company, not the customer.** The till builds its intent with `customer_email = gatewayContactEmail()`, which returns the **company's** `companies.email` and, if that is empty, `no-reply@<hostname>.invalid` — the cart's customer is never consulted, even when one is chosen. Live/test sends that value to the provider (Paystack requires *an* email, so the placeholder at least satisfies the call). Consequences: the provider's receipt goes to the office instead of the buyer, and a company with no email on file sends a `.invalid` address. Feeding the customer's own email into the intent is a small change, best made once receipts know who the customer is.
- **Refunds are not drawer-aware.** `cash_refund` is not written automatically by anything — it is one of the four movement types a cashier records by hand on the shift screen (`shiftAddMovement()`), so refunding drawer cash at the register is a deliberate second action. A gateway refund never touches `shift_movements` at all, which is correct while no drawer money moved, but it means a shift's card takings can exceed what was handed over by exactly the refunded amount and the report does not say so.
- **No multi-currency conversion.** A settlement in a currency other than the company's is held, never converted. That is the safe rule; it is also a feature a business trading in two currencies would need to design.
- **`payment_methods.gateway` is one provider per tender code.** A company wanting "Card (Paystack)" and "Card (Flutterwave)" side by side gets it by codes, but a method whose `gateway` is set cannot be re-pointed by a form that omits the field (§18.2 section 5 asserts the current behaviour).

---

## Phase F follow-ups (added 2026-11-07)

### 7.1 A QR code is a destination, not a payment processor

This is the defining boundary of the module and it is deliberate.

- `qr_payment_points` stores the link a customer's phone should open. The application renders that link as a QR and nothing more: it does not open a gateway session, verify a settlement, write an invoice, or record a payment. The provider or bank behind `scan_url` takes the money.
- Consequence: a scan-to-pay code and the gateway module (§12) solve different halves of the same problem. The gateway path posts a sale after server-side verification; a QR code cannot, because the application never sees the customer's payment. If a business needs scanned payments to post automatically, the code should point at a gateway checkout URL and the office should reconcile it through **Payment Gateways**, or the module should grow a verified-callback path — a new design, not a bug fix.
- There is no reconciliation between a scanned payment and the ledger. A customer who scans, pays and leaves produces no row here.

### 7.2 Known limits of the QR work as it stands

- **No physical scan was performed.** The rendered payload is decoded back with the library's own byte-mode decoder, and the Phase B suite cross-checks QR matrices against an independent reference encoder, but no camera or scanner read a printed code. Print DPI, media and shrink affect scannability more than the encoding does; a spot-check on the real printer is worth doing.
- **QR capacity is versions 1–10, byte mode (~213 bytes at ECC M).** A longer destination is refused at save time with a message to shorten it or lower the error-correction level. Kanji, ECI and structured-append are not implemented, as in Phase B.
- **A fixed amount is appended only to http(s) links.** Other schemes (`upi:`, `tel:`, …) are stored and encoded unchanged because they do not share the URL query convention. A provider whose non-http scheme needs a different amount parameter would need a per-scheme rule.
- **No per-user or per-branch visibility filtering.** The list shows every point in the company; `branch_id` is a label and a suggested scope, not an access control. This matches the read-side branch-scoping gap in §1.1/§3.5.
- **No image is stored, so there is no "as printed" archive.** A reprint always reflects the current row, which is the intended guarantee; a business that must reproduce a code exactly as it was printed on a given date would need to snapshot the payload at print time.
- **The browser click-through of the amount-type toggle and the preview card was not automated** (no browser on this host); the routes and rendered markup were exercised over HTTP and the script block passed `node --check`.
- **Deleting a point is a hard delete.** It is allowed while no document references the point, and a future FK turns a blocked delete into a friendly "deactivate it instead" message. Until then, a deleted code leaves only its audit row as evidence that it ever existed.
