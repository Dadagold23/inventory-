# Troubleshooting Guide

**Updated:** 2026-11-02

Never paste database credentials, SQL traces or full `.env` contents into a support ticket or chat. Details are written to `storage/logs/app.log`; the browser intentionally receives a generic message when `APP_DEBUG=false`.

---

## 1. The site shows "Something went wrong" or a blank page

1. Check `storage/logs/app.log` and the Apache error log.
2. Confirm PHP 8.1+ is active (`php -v`) and the `pdo_mysql` extension is loaded.
3. Temporarily set `APP_DEBUG=true` **only on a local machine** to see the exception message; set it back to `false` before exposing the site.
4. Reproduce the page once and read the newest log entry; it includes file and line.

## 2. Cannot log in

| Symptom | Cause / action |
|---|---|
| Generic "invalid credentials" | Username or password is wrong. The message is intentionally generic. |
| "Account locked" | Five failed attempts lock the account for 30 minutes. Wait, or have an administrator clear `failed_login_attempts` and `locked_until` for that user. |
| Login form returns to itself | Session cookies are blocked or the CSRF token expired. Allow cookies for the app path, reload the form, and submit again. |
| Login succeeds but every page redirects to login | The session cookie is not being stored, or a stale duplicate cookie exists. The app now clears legacy `inventory_session` cookies scoped to `/` and `/inventory` automatically. If the loop persists, clear cookies for `localhost` (or the site host) and sign in again. Also confirm the app is reached through the configured `APP_URL` path and that PHP's session save path is writable. |
| Suddenly signed out after a while | `session.gc_maxlifetime` now follows `SESSION_LIFETIME` (default 120 minutes), so this should no longer happen within that window. If it does, check the PHP session save path and that `SESSION_LIFETIME` is not too short. |

## 3. HTTP 403

- **403 on a normal page:** the request may be hitting a protected directory (`views/`, `includes/`, `config/`, `scripts/`, `db/`, `docs/`, `storage/`). Use the extensionless route (for example `/inventory/dashboard`), not the internal file path.
- **403 on form submit with "Invalid or expired CSRF token":** reload the page, then submit. Do not keep a form open across a long idle period. Confirm cookies are enabled and that the site is not opened through two different hostnames (`localhost` vs `127.0.0.1`).
- **403 on `.env`, `.sql`, `.md`, logs:** intentional. These files must never be served.

## 4. Database unavailable

Symptoms: 503 page, or "The database is unavailable".

1. Start MySQL/MariaDB (XAMPP Control Panel).
2. Check `.env`: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
3. Test connectivity:
   ```bash
   C:/xampp/mysql/bin/mysql.exe -u root inventory -e "SELECT 1;"
   ```
4. Confirm the database exists and the user has privileges on it.
5. In production use a dedicated database user, not `root`.

## 5. Database schema errors after an update

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

- A **checksum mismatch** means an already-applied migration file was edited. Restore the original file, or add a new numbered migration; do not force re-run an edited file.
- If a migration fails, read the error, restore the pre-migration dump from `backups/`, fix the migration, and retry. The runner is not silently continue-on-error.
- Never apply legacy `apply_schema.php`, `check_schema.php`, `create_payment_received.php` or `fix_module13.php`. They are blocked over HTTP and superseded by `migrations/`.

## 6. Stock or sales errors

| Message / situation | Action |
|---|---|
| "No active branch is configured" | Create/activate a branch for the company (Company → Branches). |
| "No stock record exists for ... at this branch" | Receive stock for that product at the invoice branch, or enable the intended setting only if negative stock is truly allowed. |
| "Insufficient stock" | Receive more stock or reduce the quantity. Sales creation locks the location row and checks on-hand. |
| Adjustment rejected | An adjustment cannot take on-hand below zero, and a zero change is not a movement. |
| Sale in the inventory panel does not appear in receivables | The product-panel sale is a stock issue only; it does not create a sales invoice. Use **Sales → Invoice** for customer billing. |
| "Unable to cancel this sale. Refund or remove recorded payments first." | Payments exist for the invoice. There is no automatic refund workflow; resolve the payment according to company policy before cancelling. |

## 7. Banking

- A transfer is recorded as an outflow from the selected account; there is no linked destination account. Record the matching inflow on the other account separately.
- Balances are updated under a row lock. If a transaction fails, the balance is rolled back with it.
- Deactivating an account keeps its transactions and balance for history; it only removes it from new-entry choices.

## 8. Reports and ledger

- Journal entries require equal total debits and credits.
- The Balance Sheet returns a `balance_check` value (`assets − liabilities − equity − retained earnings`). A non-zero value means the underlying postings need review; generation itself is not blocked.
- If a report looks stale, regenerate it; regeneration refreshes `generated_at` without creating a duplicate definition.
- Account types must be `asset`, `liability`, `equity`, `income` or `expense`.

## 9. Assets or navigation look broken

- Confirm `APP_URL` matches the path used in the browser (for example `http://localhost/inventory`). Links and assets are built from this base.
- If the app is served from a different subdirectory, update `APP_URL` **and** `RewriteBase` in `.htaccess`.
- Bootstrap, Font Awesome and Chart.js load from CDNs. If the site is offline, layout or charts may be missing while the server-rendered data still works.
- If extensionless routes return 404, enable `mod_rewrite` and `AllowOverride All` for the project directory.

## 10. Logs, diagnostics and safe support

| Item | Location |
|---|---|
| Application log | `storage/logs/app.log` |
| Database backups | `backups/` |
| Migration history | `schema_migrations` table, `php scripts/migrate.php --status` |
| Apache/PHP errors | XAMPP Apache `logs/error.log` |

When reporting an issue, include the time, the route, what you clicked, and the newest log line with credentials removed. Do not enable `APP_DEBUG` on a public server.

## 11. Restoring a backup

Only do this with explicit approval and after stopping writes:

```bash
# Verify the dump is readable first
C:/xampp/mysql/bin/mysql.exe -u root inventory < backups/inventory_pre_006_20261102_060807.sql
```

Restoring replaces the current database contents. Take a fresh dump of the current state first. The 003 and 004 migrations were applied before pre-migration backups were established; only the 005 and 006 dumps exist (see `DATABASE.md`).
