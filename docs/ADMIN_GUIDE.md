# Administrator Guide

**Updated:** 2026-11-07

---

## 1. Signing in

- Open `http://localhost/inventory/` (or the deployed URL). The sign-in page is served at the root; signed-in users are taken to the dashboard.
- Enter a username or email address and password.
- Five consecutive failures lock the account for 30 minutes. Successful login resets the counter.
- Session cookies are HTTP-only with `SameSite=Lax`; the session ID is regenerated on login.
- Change your password from **Change password** in the user menu. The current password is required.

Administrators are identified by the `admin` role on the `users` table. Accounts, roles and permission grants are managed in **Administration → Users & Roles** (see §7).

## 2. Company profile

Open **Company** (`/company`).

- **Company details:** name, currency, fiscal year start month, tax ID, address, phone and email. Saving updates the current company only. Currency is used by `formatPrice()` across reports and dashboards.
- **Branches:** create or edit branches with code, address, contact details, active flag and sort order. Deactivation is soft (`is_active = 0`); historical records remain linked.
- **Departments:** create or edit departments, link them to a branch and choose an active manager. Deactivation is soft.

## 3. System settings

Open **System settings** (`/system_settings`).

- Settings are company-scoped key/value pairs with a value type (`string`, `number`, `boolean` or `json`) and description.
- **Add:** fill the new-setting row at the bottom of the table and save.
- **Edit:** change value, type, description or active flag in the table and save. All rows are submitted together.
- **Delete:** use the row delete button; the key is removed for the current company only.
- The active flag is informational; the current lookup helper does not filter on it, so do not use it as an access-control switch.

## 4. Approval workflows

Open **Approvals** (`/approval`).

- **Workflows** (`?action=workflows`): create a workflow, edit its name/description, or toggle active status. Seeded workflows: Expense, Purchase Order, Invoice and Sales Discount (two company-specific sets exist in seed data).
- **Workflow steps:** each workflow has ordered steps with a required role and action. The number of steps equals the number of required approvals.
- **Requests** (`?action=requests`): the requests screen lists pending/all requests and lets an authorised user submit, approve or reject.
  - Submitting requires an active workflow, a positive entity ID and an entity type (expense, purchase order, invoice, sales discount, journal entry, payment made/received, payroll run).
  - An approval action is refused if the same user already approved the request.
  - A request becomes `approved` only when the number of approvals reaches the required count; otherwise it stays `pending`.
  - A rejection closes the request as `rejected`.
- All actions are stored in `approval_actions` with the user, comment, timestamp and IP address.

## 5. Audit trail

Open **Audit log** (`/audit`). Entries record logins, logouts, failed attempts and repository changes with user, entity, old/new values, IP address and user agent. The log is read-only in the UI; retention is a database maintenance decision.

## 6. Backups and migrations

- Back up before any schema change:

```bash
mysqldump -u root inventory > backups/inventory_manual_$(date +%Y%m%d_%H%M%S).sql
```

- Check and apply migrations:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

- Migrations are CLI-only. The web server can never execute them.
- Do not edit an applied migration; its checksum is recorded. Add a new numbered file.

## 7. User administration

Accounts are managed in **Administration → Users & Roles** (`/admin`, permission `users.view`; writes need `users.create` / `users.update` / `users.delete`). The screen creates and edits users, resets passwords, deactivates accounts, assigns branches (`user_branch_access`) and grants per-user permission overrides (`user_permissions`), and edits each role's permission matrix. Guards: the built-in `admin` role cannot be edited or deleted, and the last active administrator cannot be deactivated. Role slugs you can assign are `owner`, `manager`, `sales_manager`, `inventory_manager`, `storekeeper`, `cashier`, `staff`, `accountant`, `procurement`, `approver`, `auditor` (plus `admin`, which bypasses checks).

Command-line fallbacks, for recovery when no administrator can sign in:

```bash
php -r "echo password_hash('Strong-Password', PASSWORD_DEFAULT), PHP_EOL;"   # produce a hash
php scripts/set_admin_password.php jdoe "New-Strong-Password"                # reset one account
```

## 8. Security checklist

- Set `APP_DEBUG=false` and `APP_ENV=production` in production.
- Keep `.env` outside web access and out of version control.
- Use a dedicated MySQL account with only the required privileges.
- Keep `backups/`, `storage/`, `db/`, `docs/`, `migrations/`, `.sql` and `.md` files inaccessible over HTTP (bundled `.htaccess` rules cover this under Apache).
- Review `audit_log` and `storage/logs/app.log` periodically.
- Test restores of backups, not only their creation.

## 9. Known limitation

Permission checks are enforced at the controller for every module (`require_permission()`), and read queries are scoped to the session company. Role grants are seeded, not inferred: `admin` bypasses all checks, `manager`/`owner` hold broad access, `cashier` holds the register and shift permissions, `staff` holds day-to-day stock, sales, expense, income, payment and approval-request access, and `auditor`/`accountant`/`approver` are read-only on the register. Denied actions return HTTP 403 and are logged. Definitions live in `permissions`, grants in `role_permissions` (migrations 007, 008, 011, 012, 016), per-user overrides in `user_permissions`.

The remaining gap is read-side branch scoping: `user_branch_access` is checked when a document is *written* (`auth_guard_branch()` refuses a branch the user may not touch) and the shift register filters by branch, but list queries in other modules still return every branch in the company. See `docs/OUTSTANDING_ISSUES.md`.

## 10. Barcodes, labels and scanning (added 2026-11-05)

Open **Operations → Barcodes** (permission `inventory.view`; code changes need `inventory.update`, detaching an alternate code needs `inventory.delete`).

**Two kinds of code, never mixed**

- **In-store codes** are generated for products that arrive without a manufacturer barcode. They are EAN-13 numbers in the GS1-reserved internal-use range (leading `2`), derived from the product id with a valid check digit, and stored with `source = system`. Example: product 1 → `2000000000015`.
- **Supplier / GS1 codes** are the EAN-13, EAN-8 or UPC printed on the packaging. Attach them with *Attach another scannable code* and choose origin *Supplier / GS1 allocated* (`source = gs1`). The check digit is verified before anything is written; a code that fails, or that already belongs to another product in your company, is refused.

When a product gains its first real EAN-13, that code becomes the **primary** (the one printed on labels) and the in-store code is kept as an alternate, so previously printed labels keep scanning. Use **Set primary** to choose a different code; the primary code cannot be detached until a replacement is set.

**Everything is company-scoped.** Codes, aliases and lookups are filtered by the session company, and a scan never resolves another company's product.

**Labels**

- Tick products in the table (or open one product) and choose a label size: **58 mm** or **80 mm** thermal roll, or **Avery L7162** (three 63.5 × 38.1 mm labels per A4 row); choose symbology (**Auto** matches the code, or force EAN-13 / EAN-8 / Code 128 B / Code 128 C / QR), copies per product, and whether to print the price.
- **Print** opens a borderless page sized in millimetres. In the browser print dialog set the paper to the label width and leave scaling at **100%**; the toolbar and cut guides are hidden when printing.
- **Download .zip** writes one SVG file per selected product (vector, so any DPI prints sharply); single files can be downloaded from the product panel.

**Scanning**

`GET /barcodes?action=lookup&code=<scan>` returns JSON: product id, name, SKU, price, branch stock and how the match was made (`barcode`, `sku`, `product_id`, or `alias:gs1` / `alias:system`). The workspace's *Scan / entry test* box uses it. The point-of-sale screen resolves scans through the same `bcLookup()` service on the server (`POST /sales?action=pos` with `pos_action=scan`), so a POS scan is priced and stock-checked in one step. A lookup writes nothing, so a mis-scan cannot create a document.

**Audit and failure behaviour**

Attaching, re-primarying and detaching codes, and ZIP exports, write `audit_log` rows under entity `product_barcodes`. Barcodes are allocated automatically when a product is saved; if allocation fails the product still saves, the reason goes to `storage/logs/app.log`, and the confirmation message tells you to open Barcodes.

---

## 11. Cashier shifts, the drawer and the POS register (added 2026-11-06)

Open **Operations → Shifts & Drawer** (`/shifts`). A shift is one cashier at one branch: it makes cash provable, because every sale and tender taken while it is open is tagged with its `shift_id`.

**Permissions**

| Permission | Grants | Controls |
|---|---|---|
| `shifts.view` | cashier, staff, manager, owner, sales_manager, accountant, auditor, approver | reading the register and a shift |
| `shifts.create` | cashier, staff, manager, owner, sales_manager | opening a shift |
| `shifts.update` | cashier, staff, manager, owner, sales_manager | drawer movements and closing |
| `shifts.delete` | manager, owner | force-closing someone else's register and voiding an untouched shift; also unlocks the branch filter on the register |

`admin` bypasses all of them. A cashier can only close **their own** shift unless they also hold `shifts.delete`, and no one can post to a closed shift.

**Reconciliation**

```
Expected = Float + Cash Receipts + Paid In − Cash Refunds − Paid Out
```

The float is `cashier_shifts.opening_float` — deliberately not duplicated as a movement row, or the same notes would be counted twice. Cash Receipts are the `customer_payments` rows of this shift whose method is flagged `is_cash` in **Payment Methods**; the other tenders are listed separately so the drawer and the takings never get confused. Money that is not cash never moves the drawer: `paid_in` and `paid_out` are for the drawer itself (a bank run, a correction, a petty-cash refund), and each one records an amount, a reason and optionally the invoice it relates to.

**Change is not a payout.** If a customer hands over 61.00 for a 41.00 bill, the payment row is booked at 41.00 — the 20.00 that came straight back out of the drawer never appears as a movement, so Expected stays exactly the formula above. That is also why the invoice, not the tender, decides the drawer.

**Closing, forcing, voiding**

- *Close* takes the counted drawer, stores Expected at that moment, computes `variance = counted − expected` and audits the shift. Leaving the count empty is allowed and records a force-close-style close with variance unknown — the form warns you before you do it.
- *Force close* (manager/owner) ends an abandoned register; it is flagged `force_closed` and asserts no counted cash.
- *Void* removes a shift **only** while it has no sales and no movement other than float adjustments, and it refuses otherwise. A shift that traded stays in the database forever; the sales keep their `shift_id` even if the shift row is later removed by hand.
- *Report* (`?action=report`) prints an 80 mm X/Z with the four reconciliation terms, takings by method, the sale list and signature lines.

**The POS gate**

With `pos_requires_shift` on (the default) a cashier cannot take payment at the register until a shift is open for them at that branch: the POS screen shows **Register locked**, the JSON status reports `allowed: false`, and `posCheckout()` refuses server-side even if someone posts a checkout directly. Cart building still works, so a queue can be rung up and paid as soon as the drawer is opened. Invoices raised from the normal Sales screen are never shift-bound — the gate applies to POS takings only.

Turn it off per company from **System Settings → Application Settings** by setting *Require an open shift for POS sales* (`pos_requires_shift`) to `0`; sales then record `shift_id = NULL` and the POS screen states that cash is not tied to a drawer. Use this only for companies without a cash register, because it removes the control rather than the nuisance.

**Operational notes**

- Idempotency: the POS sends a per-checkout key, and `sales_invoices` enforces uniqueness on `(company_id, idempotency_key)`. A retried or double-clicked sale returns the original invoice flagged `duplicate` and adds nothing — no second invoice, no second stock movement, no double receipt. Keys are nullable, so counter sales and all pre-Phase-C invoices are untouched.
- Tampering: the browser never supplies a price. Cart lines hold product id, quantity and discount only; `recalculatePosTotals()` re-reads `products` on every mutation and `createSalesInvoice()` re-prices the lines again when the invoice is written.
- Stock: POS adds and checkouts are refused when the requested quantity exceeds on-hand at the register's branch, unless `allow_negative_stock` is set. A refused checkout leaves stock alone.
- Audit: opening, movements and closing write `audit_log` rows under `cashier_shifts` / `shift_movements`. Nothing in this module deletes a financial record.
- Expect a burst of `Permission denied` warnings in `storage/logs/app.log` whenever the verification harnesses run their negative cases; that is the harness, not an attack.

---

## 12. Payment gateways — Paystack and Flutterwave (added 2026-11-06)

Open **Administration → Payment Gateways** (`/payment_gateways`). This is where a company lets a customer pay by card, bank transfer or USSD through a provider, instead of only taking cash and the tenders typed in by hand.

**As it stands today:** no provider account is connected on this installation. Both providers are configured to run in the built-in **simulator** when you allow it, which exercises the whole path — session, customer page, verification, invoice, refund, reconciliation — without any money moving. The code that talks to a real provider is the same code; switching means storing real keys and turning simulation off.

**Permissions**

| Permission | Grants | Controls |
|---|---|---|
| `gateways.view` | owner, manager, accountant, approver, auditor | reading the configuration, sessions, reconciliation and callbacks |
| `gateways.update` | owner | storing credentials, choosing test/live, enabling a provider, running the connection test |
| `gateways.verify` | owner, manager, accountant | re-verifying a payment against the provider, expiring stale sessions, running reconciliation |
| `gateways.refund` | owner, manager, accountant | issuing a refund |

`admin` bypasses all of them. **A cashier holds none of these** — the till can take a card payment, but a cashier cannot configure, verify or refund anything; those belong to the office.

**Before you store a key**

1. Set `APP_KEY` in `.env` (see `ENVIRONMENT.md` §1). Credentials are sealed with AES-256-GCM using that key; if it is later lost, the stored values cannot be read and every key must be re-entered.
2. Set `APP_URL` to the address the provider can actually reach, because the callback URL and the page the customer is returned to are built from it.
3. Decide about `GATEWAY_SANDBOX`. It is `true` on this workshop machine so the simulator can be demonstrated. On any host that takes real money it must be `false`; while it is `true` on a host that claims production, the workspace shows the warning from `gatewaySandboxWarning()` at the top of the screen.

**What a configuration does**

Each provider card holds: *Accept payments through this provider* (`is_enabled`), *mode* (Test or Live), *Simulate payments (test mode, no key)*, and six credential fields — public key, secret key and webhook secret for each mode. Three rules are worth knowing, because the screen enforces them rather than warning afterwards:

- A blank credential field means *keep what is stored*. To clear one you tick its forget flag. This is why a screen can show a hint instead of the key.
- Enabling a provider with nothing usable behind it is refused: either a secret key for the selected mode, or (test mode only) simulation allowed with a public key. Half-configured gateways are how money goes missing.
- A provider that holds a secret key **will call the real API** the moment a payment is taken. If you only want to see the flow, leave the secret key empty and let it run in the simulator.

The runtime the settings produce is shown on the card: `live`, `test`, `sandbox` or `unavailable`. Enabling a provider also adds (or reuses) an online tender in **Payment Methods** named after it, and switching the provider off takes that tender away from the till again — you do not edit the tender by hand, and a card tender whose provider has gone quiet is not offered.

**The customer's side, and why a redirect is not a receipt**

The cashier opens a session, the customer pays on the provider's page, the provider sends the browser back to this application. That return only *triggers* a check: the app asks the provider what it actually received, compares the amount and currency, and re-prices the cart. Only then is an invoice written. A session that came back unpaid, underpaid, in another currency, or against a price that moved while the customer was paying is held with the reason on the row — it is never posted, and it shows up under reconciliation.

Put the callback URL from the top of each provider card into the provider's dashboard (`…/gateway_webhook.php?gateway=paystack`, or `flutterwave`). Callbacks are journalled only once their signature verifies against your webhook secret; anything unsigned is refused at the door and stores nothing.

**Working the register of payments**

- **Payment sessions** lists every attempt with its provider, amount, status and the invoice it earned. Filters: status, provider, reference, date. `Pending` rows are sessions someone opened and did not finish.
- **Verify** (needs `gateways.verify`) asks the provider again — this is the button for "the customer says the app took the money but no receipt appeared". It is safe to press twice.
- **Expire pending older than N minutes** closes off abandoned sessions so they stop cluttering the register. It only ever touches rows that are still `pending` — a session that earned an invoice, or that the provider already answered, is left alone.
- **Refund this payment** records a refund against the payment. Over-refunding is refused; a partial refund leaves the invoice's history exactly as it happened and the payment's status becomes `partial_refund`. Refunds of a session that was never paid are refused.
- **Reconciliation** reports captured / refunded / posted / unposted money and **simulated captured** next to it, plus six buckets: paid-not-posted, posted-not-paid, amount drift, duplicate capture, stale pending, refund mismatch. Anything in a bucket is money or a record to look at, which is the point of the screen.

**What gets audited**

Configuration changes write `audit_log` rows under `payment_gateways` — the *shape* of the change only (`mode`, `enabled`, `sandbox_allowed`, which credential fields were added or cleared), never a value. Connection tests, session changes, refunds and finalised sales are audited under their own entities. A callback carries no session, so the audit entry is stamped with the company and cashier the payment belongs to rather than whoever is signed in.

**Operational notes**

- No credential is ever sent to a browser. Screens receive "set/not set" and a short hint; the POS receives only which tender is a card tender and who runs it.
- Simulated payments are marked permanently (`simulates_payment = 1`) and are totalled separately, so a demonstration can never be read as takings in the reports.
- A payment row keeps its invoice reference even if the invoice is later removed, and a refund keeps its payment row: the money trail does not depend on the sales document still existing.
- Nothing in this module deletes a posted sale. Cancelling an online-paid sale is a refund, and refunds are additive records.

---

## 13. QR scan-to-pay points (added 2026-11-07)

Open **Administration → QR Scan-to-Pay** (`/qr_payments`). A pay point is the printable or displayable code a customer scans to reach your payment destination — typically an https payment link from your provider or bank. The module stores the destination and the rules around it; it does **not** process the payment. The provider or bank behind the link does, and this application records no money from a scan.

**Permissions**

| Permission | Grants | Controls |
|---|---|---|
| `qr_payments.view` | owner, manager, accountant, sales_manager, cashier, staff, storekeeper, inventory_manager, procurement, approver, auditor | reading the list, previewing and downloading a code |
| `qr_payments.create` | owner, manager, sales_manager | creating a point |
| `qr_payments.update` | owner, manager, sales_manager | editing, activating and deactivating a point |
| `qr_payments.delete` | owner | deleting a point |

`admin` bypasses all of them. A cashier can display and print a code but cannot change one.

**What a point holds**

- **Name** and a lowercase **code** that is unique per company and is the stable handle used in printed material and support.
- **Scan destination** — the URI encoded in the QR. `http(s)` links are validated with the URL filter; other well-formed schemes such as `upi:` or `tel:` are accepted. A destination that cannot fit a QR (versions 1–10, roughly 213 bytes at ECC M) is refused before it is stored.
- **Branch** and **payment method** — optional; leave them at *All branches* / *Any tender* for a general code.
- **Merchant reference** — optional; for a fixed-amount point it is also sent as the `reference` query parameter.
- **Amount rule** — *open* (customer enters the amount, link unchanged) or *fixed* (the amount is appended to an http(s) link as `amount=…&reference=…`).
- **Error correction** — L/M/Q/H; M is the balanced default.

**Preview, print and retire**

- The list shows a thumbnail of every point; open one to see the full-size QR and the exact encoded destination. **Download SVG** is vector, so any printer DPI stays sharp.
- The QR is rendered on demand from the stored row — no image bytes are stored — so a reprint can never point somewhere the database does not say.
- **Deactivation** is the normal removal: the point stays listed and can be reactivated, so a printed code can still be explained later. **Delete** removes a point that has no payment history; if a future document ever references a point, the foreign key refuses the delete and the screen tells you to deactivate instead.

**Audit**

Create, update, activate/deactivate, delete and each SVG download write `audit_log` rows under `qr_payment_points`. The snapshot records the link, amount rule, branch and tender, never a rendered image.

**Boundary worth stating plainly**

This screen does not verify that a customer paid. A scan leaves the application for the provider's page; if the business needs the payment to post a sale automatically, that is the gateway module (§12), not this one. A QR code can coexist with the gateway, but it is a signpost, not a till.
