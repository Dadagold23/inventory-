# User Guide

**Updated:** 2026-11-07

---

## 1. Navigation

The sidebar is available on every page and only shows what you are allowed to open.

- **Operations** — Dashboard, Inventory, Categories, Barcodes, Sales, **Shifts & Drawer**, Receivables, Suppliers & Purchases.
- **Finance** — Expenses, Income, Payments Received, Payments Made, Banking, General Ledger, Tax, Payroll.
- **Control** — Approvals, Reports, Audit Log.
- **Administration** — Companies & Branches, Users & Roles, Payment Methods, Payment Gateways, QR Scan-to-Pay, System Settings.

Amounts are formatted with the company currency. Dates follow the server locale. Every save button submits a CSRF token; if a form has been open for a long time and the session expires, reload the page and submit again. Signing out is available in the user menu, and an idle session is ended automatically after the configured lifetime.

## 2. Dashboard

- Cards show live totals: sales, expenses, outstanding receivables, stock value, counts, etc.
- The bar chart shows the last six months of sales and expenses; the doughnut chart shows invoice counts by status.
- On an empty database, all figures show zero and the charts render empty — this is expected, not an error.

## 3. Inventory

1. **Create a product:** Inventory → **Add Product**. Name is required; SKU is generated when left blank. Choose category, cost, price, reorder level and whether stock is tracked.
2. **Open a product** to see its stock by branch and current valuation.
3. **Receive stock:** record a receipt with quantity and unit cost. On-hand quantity and value increase and an inventory transaction is appended.
4. **Sell stock:** either use **Record Sale** in the product panel or create a sales invoice. Both deduct stock and block the movement when stock is insufficient (unless the company setting `allow_negative_stock` is enabled).
5. **Adjust stock:** enter a positive or negative quantity. An adjustment cannot take on-hand below zero; when unit cost is omitted, the current average cost is used.
6. All stock mutations run in a database transaction with row locking; the inventory movement log records the type, quantity, unit cost and user.

## 4. Sales and receivables

1. **Customer:** create a customer with contact, credit limit and payment terms.
2. **Invoice:** choose customer, date, due date and line items. The server calculates subtotal, discount, tax and total. Stock is locked and deducted.
3. **Payment received:** record a payment against an invoice. The system prevents overpayment and updates the invoice status (`pending`, `partial` or `paid`).
4. **Cancel invoice:** only allowed when no payment has been recorded against the invoice. Stock is restored exactly once and a cancellation movement is recorded.
5. **Receivables:** statements summarise outstanding invoices; communications record contact with the customer.
6. **Point of sale:** walk-in sales are taken at the register, which needs an open shift — see §13. Counter invoices raised here from the customer list are not shift-bound.

## 5. Expenses and income

- **Expenses:** choose a category, date, amount, payment method and optional vendor. Vendor is optional (migration 006). The expense is recorded in the company ledger.
- **Expense categories:** create categories needed for reporting.
- **Income:** choose a source, date, amount and method. Income and sources are company-scoped.
- **Payments made:** standalone vendor payments can be recorded outside an invoice.

## 6. Banking

- Create a bank account with name, account number, type, currency and opening balance.
- Record deposits, withdrawals, transfers, fees or interest. Transfers, fees and withdrawals reduce the selected account; deposits and interest increase it. The server calculates and stores the new balance; you never enter it manually.
- Edit updates only the fields you submit; deactivation keeps history.
- Transactions appear in the account detail page and roll up to the bank list balance.

## 7. Suppliers and purchases (accounts payable)

1. **Supplier:** create a vendor.
2. **Purchase order:** choose vendor, date and line items; the total is calculated.
3. **Invoice receipt:** record the vendor invoice against a purchase order.
4. **Payment made:** pay a vendor invoice or record a standalone payment.

## 8. General ledger and reports

- **Ledger:** browse journal entries, post a balanced entry and review the general ledger and trial balance.
- **Reports:** generate Trial Balance, General Ledger, Balance Sheet, Income Statement, Cash Flow and Sales Analytics for a date range.
  - The Balance Sheet includes a balance check (`assets − liabilities − equity − retained earnings`); review it before relying on the report.
  - Re-generating an existing report refreshes its data and `generated_at`; it does not duplicate the definition.
  - **Sales Analytics** breaks a period down by day, product, category, branch or cashier, with an optional branch filter. It shows gross sales, discounts, net, tax, cost of goods, profit and margin, plus how the money was paid (cash versus non-cash) and how much of the period's billing is still outstanding. Draft and cancelled bills are excluded. The breakdown and branch are view choices and are not saved with the report.
- Posting a journal entry requires equal debits and credits. Account types are asset, liability, equity, income and expense.

## 9. Tax and payroll

- **Tax:** maintain tax rates and record tax transactions.
- **Payroll:** maintain employees and pay rates, create payroll runs, and review run lines. Payroll runs are company-scoped.

## 10. Approvals

- Submit a request from **Approvals → Requests** by selecting an active workflow, entity type, entity ID and optional notes.
- Approvers approve or reject with an optional comment. A request can require multiple approvals; the detail page shows the steps, approvals received and remaining count.
- A user cannot approve the same request twice.

## 11. Company and settings

- **Company** lets you maintain the company profile, branches and departments.
- **System settings** maintains company-scoped configuration values.

## 12. Barcodes, labels and scanning

- Products already carry a code: an in-store (2xx) EAN-13 when nothing was supplied, or the manufacturer's EAN-13 / EAN-8 / UPC printed on the packaging. Open **Operations → Barcodes** to attach a supplier code, keep several codes on one product, choose which one prints as primary, or detach an alternate. Codes that fail their check digit, or that already belong to another product in your company, are refused.
- Tick products and print labels on a 58 mm or 80 mm roll, or three-up on Avery L7162 A4 sheets; choose the symbology and how many copies, or download the labels as SVG (vector, so any printer DPI stays sharp). In the browser print dialog set the paper size to the label width and scaling to 100 %.
- A lookup never writes anything: mis-scanning cannot create a document.

## 13. Working the register (shifts and POS)

Cash is only provable when it is counted into a known drawer, so the register works in a fixed order.

1. **Open a shift** — **Operations → Shifts & Drawer → Open shift**, pick your Branch/Store, enter the opening float and start. Until a shift is open for you at that branch, the POS shows **Register locked** and refuses payment; you can still scan items into the cart.
2. **Sell** — **Sales → Point of Sale**. Scan a barcode (or a SKU) to add one unit, or search and click. Set quantities and line discounts, check the cart total, then enter what the customer actually hands over. Add a second payment row for split tender (for example part cash, part transfer). Cash above the bill is returned as change; the machine shows it and does not treat it as a payout. If the network hiccups mid-sale, retry: the sale is idempotent, so a double click or a resent request cannot create a second invoice or move stock twice.
3. **Move money in or out of the drawer** — on the shift page, record *Paid in*, *Paid out* or *Cash refund* with an amount and a reason. These are the only events that change Expected besides sales.
4. **Close the shift** — count the drawer and enter the physical total. The page shows Expected and the variance as you work; the printed **Report** is the X/Z you sign. Leaving the count empty closes without asserting a counted figure.
5. **Hand over** — a manager can force-close a register left open by someone else; that is flagged and audited. A shift that has taken sales can never be deleted, only closed.

```
Expected = Float + Cash Receipts + Paid In − Cash Refunds − Paid Out
```

Cash receipts are the payments whose method is marked *cash* in Payment Methods; card, transfer and other takings are listed next to them but never sit inside the drawer figure.

### Taking a card payment (added 2026-11-06)

A card tender is not typed into the drawer — the machine has to ask the provider whether the money arrived before the sale exists.

1. Ring up the basket as usual, then choose the card method (it is named after its provider, for example *Paystack (online)*) in the payment row. The panel **Card payment** appears under the tender list.
2. Press **Take card payment**. The provider's page opens for the customer with the amount this application quoted — that amount cannot be changed on the payment page. If your browser blocks the popup, press the **Open the payment page** link that appears instead.
3. The customer pays, and the provider sends the browser back here. The till keeps asking where the payment stands every few seconds; **Ask the gateway again** forces a check straight away.
4. When the provider confirms the payment, the sale completes and you get the receipt link as with any other sale. Until it confirms, the cart stays where it is and the payment stays *pending* — nothing has been sold yet.

What you will see when it does not work, and what it means:

- **“… is a card payment: take it with the online payment button”** — you pressed *Complete Sale* with a card row in the list. Card money never goes through that button; use **Take card payment**.
- **The payment page shows a different amount than the till** — do not take it. Close it, tell the customer, and check the cart. The quoted amount is always the server's total; if the price changed while the customer was paying, the money is held for the office to reconcile rather than posted at the old price.
- **“A payment is already waiting for this basket”** — the session you opened a moment ago is reopened, not duplicated. One basket can only ever have one card payment waiting.
- **The customer changed their mind mid-payment** — add or remove an item and open the payment again; the earlier session is retired automatically.
- **Paid, but no receipt appeared** — press **Ask the gateway again**. If it still does not post, leave the session as it is and tell the office: the payment is recorded and visible under Payment Gateways → Reconciliation, and the refund or verification is an administrator's action, not a till action.
- **No JavaScript / a frozen screen** — the classic form at the bottom of the POS page has a *Take card payment* button of its own; it opens the same session and returns you to the register with the payment reference.

You cannot configure a provider, verify a payment by force, or issue a refund from the register — those belong to **Administration → Payment Gateways** and need the office's permissions. If the card option is missing from the tender list, the provider has been switched off and the office needs to turn it back on.

## 14. QR scan-to-pay codes

**Administration → QR Scan-to-Pay** lists the codes a customer can scan to reach your payment destination — usually a payment link from your provider or bank. A point can be limited to one branch and one tender, and can either leave the amount open (the customer types it) or carry a fixed amount.

1. **Create a point** with a name staff will recognise (for example *Main counter — Paystack*), a short lowercase code, and the **scan destination** (the link the phone should open). Optionally choose a branch, a payment method, a merchant reference and a description.
2. **Amount:** *Open* uses the link unchanged. *Fixed* embeds the amount in the QR by appending `amount` and `reference` query parameters to an http(s) link — useful for deposits or a single-price item.
3. **Preview and print:** open a point to see its QR and the exact destination it encodes, then **Download SVG** for the printer. The code is drawn from the record every time, so a reprint always matches the list.
4. **Retire a point** by deactivating it: it stays listed so a printed copy can still be identified. Delete only removes a code that was never used.

The QR does not take the payment itself — the provider or bank behind the link does. A link too long to fit a QR code is refused when you save, with a message to shorten it or lower the error-correction level.

## 15. Everyday troubleshooting

- **"Invalid or expired security token":** reload the page and submit again.
- **"Insufficient stock":** receive stock or reduce the invoice quantity.
- **"Only pending requests can be actioned":** the request has already been approved or rejected; refresh the list.
- **Duplicate SKU:** SKUs are unique per company; choose another.
- **“Register locked” at the POS:** no shift is open for you at this branch. Open one from Shifts & Drawer; the cart you already built is still there.
- **“The payments entered total … but the sale is …”:** a split tender that does not cover the bill. Add the remainder or reduce a payment row.
- **“Already recorded: INV-…” after a slow checkout:** the retry found the sale your first attempt created. Nothing was duplicated — carry on with the next customer.
- If a page unexpectedly fails, note the time and contact the administrator; details are written to `storage/logs/app.log` without exposing SQL to the browser.
