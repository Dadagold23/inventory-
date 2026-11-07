# Environment Configuration Guide

**Updated:** 2026-11-06

This guide describes every environment value the application actually reads and the server settings required for correct behaviour. Installation steps are in `INSTALLATION.md`; migration commands are in `MIGRATIONS.md`.

---

## 1. `.env` file

The file lives in the project root and is parsed by `config/database.php` and `bootstrap.php`. Copy `.env.example` and edit it. `.htaccess` denies HTTP access to `.env`.

| Key | Example | Required | Behaviour |
|---|---|---|---|
| `DB_HOST` | `127.0.0.1` | Yes | PDO MySQL host |
| `DB_PORT` | `3306` | Yes | PDO MySQL port |
| `DB_NAME` | `inventory` | Yes | Database name |
| `DB_USER` | `inventory_app` | Yes | Database user; avoid `root` outside local testing |
| `DB_PASSWORD` | `change-me` | Yes | Password; may be empty on stock XAMPP |
| `APP_URL` | `http://localhost/inventory` | Yes | Base used by `url()` and `asset()`; the path part becomes the session cookie path |
| `APP_ENV` | `production` | Read | Environment label; does not itself enable debug. `production`/`prod` is what `appIsProduction()` tests, and that is what `GATEWAY_SANDBOX` has to overcome |
| `APP_DEBUG` | `false` | Yes | `true` prints exception messages and enables detailed PHP error display. Use only locally. |
| `APP_KEY` | 64 hex characters | Strongly recommended | The AES-256-GCM key that seals gateway credentials at rest (`includes/crypto_functions.php`). Generate with `php -r "echo bin2hex(random_bytes(32));"`. Absent: the app mints `storage/keys/app.key` so a local install still works. Present but **empty**: refused — `secretEncrypt()` throws rather than minting a key behind the operator's back, which is why `.env.example`'s blank line must be filled in before a gateway is configured. Losing the key makes stored credentials unreadable: re-enter them. Never rotate it silently. |
| `GATEWAY_SANDBOX` | `false` | No | Allow the built-in payment simulator (`runtime_mode = sandbox`) on a host whose `APP_ENV` says production. Leave `false` anywhere real money is taken; set `true` only on a workshop machine, and note that the gateway workspace then shows the warning `gatewaySandboxWarning()` produces. Simulating is refused outright when the gateway holds a secret key for the selected mode. |
| `SESSION_LIFETIME` | `120` | Yes | Session lifetime in **minutes**; drives cookie lifetime and `session.gc_maxlifetime` |
| `SESSION_DRIVER` | `files` | No | Reserved; PHP file sessions are always used |
| `FEATURE_APPROVALS`, `FEATURE_PAYROLL`, `FEATURE_TAX`, `FEATURE_DASHBOARD` | `true` | No | Reserved; not read by current code |

Parser rules: lines are `KEY=VALUE`; blank lines and `#` comments are ignored. Quote values only if you need to preserve spaces. Do not add a trailing semicolon.

## 2. Application URL and subdirectories

`url('/dashboard')` returns `APP_URL`'s path plus `/dashboard`. With `APP_URL=http://localhost/inventory`:

- `/dashboard` → `http://localhost/inventory/dashboard`
- assets → `http://localhost/inventory/assets/...`
- session cookie path → `/inventory/`

If the application moves to `/` or another subdirectory, change **both**:

1. `APP_URL` in `.env`
2. `RewriteBase /inventory/` in the root `.htaccess`

A mismatch causes redirect loops, 404s on extensionless routes, or a login page that cannot persist the session.

## 3. PHP settings

| Setting | Recommended | Why |
|---|---|---|
| `date.timezone` | Your business timezone | Timestamps and report dates; the tested server used `Europe/Berlin` |
| `session.gc_maxlifetime` | At least `SESSION_LIFETIME × 60` | The bootstrap aligns this automatically to the configured lifetime |
| `session.use_strict_mode` | `1` | Set by bootstrap |
| `session.use_only_cookies` | `1` | Set by bootstrap |
| `display_errors` | `Off` in production | Controlled by `APP_DEBUG` |
| `upload_max_filesize` / `post_max_size` | Sized to any receipts you attach | Receipt upload fields exist in expenses |
| `memory_limit` | `128M` or more | Report generation |

Required extensions: `pdo_mysql`, `mbstring`, `openssl`. `curl` is required as soon as a gateway runs against a real provider (`live`, or `test` with a test secret key) — `gatewayHttpRequest()` is the only outbound call in the application. A gateway in the simulator makes no network call at all.

## 4. Apache settings

- Enable `mod_rewrite`.
- Set `AllowOverride All` for the project directory so `.htaccess` and the per-directory deny files apply.
- Confirm `.htaccess` is honoured. If `AllowOverride` is disabled, extensionless routes 404 and internal directories become readable.

The project ships deny rules for:

```text
config/  controllers/  db/  docs/  includes/  migrations/  models/  scripts/  storage/  views/
```

The root `.htaccess` additionally denies `.env`, `.sql`, `.md`, `.log`, `.bak`, `.ini`, `.lock`, `.sh`, `.bat`, `prompt.txt`, `composer.*`, and the legacy setup/test scripts.

## 5. Filesystem permissions

The web server user needs write access to:

- `storage/logs/` — application log
- `storage/` — any uploads/session artifacts
- `storage/keys/` — only when `APP_KEY` is unset and the app must mint its own data key. The file is written `0600` where the filesystem supports it (a no-op on Windows) and the directory is denied to the web server either way.
- `backups/` — if backups are created by the web/CLI user

Do not make `.env`, `config/`, `db/` or `migrations/` world-writable. On Windows/XAMPP the default ACLs are usually sufficient.

## 6. Database environment

- MariaDB 10.4+ or MySQL 8, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
- The database must already exist; the application does not create it.
- The database user needs `SELECT, INSERT, UPDATE, DELETE` on the application schema and `CREATE, ALTER, INDEX, REFERENCES` for migrations. It does not need `DROP` for normal operation.
- Do not use `root` for daily operation.

## 7. Front-end dependencies

| Asset | Source | Notes |
|---|---|---|
| Bootstrap 5.3.2 | jsDelivr CDN | Layout and modals |
| Font Awesome 6.4 (minimal) | cdnjs | Icons |
| Chart.js | CDN, dashboard only | Charts fail gracefully without it |
| `assets/css/foundation.css` | Local | Application styling |
| `assets/js/foundation.js` | Local | Shared behaviour |

For offline or hardened deployments, vendor these files locally and update the view tags, or add SRI hashes.

## 8. Payment gateways (added 2026-11-06)

Two providers are supported — Paystack and Flutterwave — and each company configures its own in **Payment Gateways** (`payment_gateways.php`, permission `gateways.update`).

**Which runtime a configuration produces** (`gatewayRuntimeMode()`):

| `is_enabled` | `mode` | Secret key stored for that mode | `sandbox_allowed` | Host | Runtime |
|---|---|---|---|---|---|
| off | – | – | – | – | `unavailable` — no card tender is offered |
| on | `live` | live secret key | – | – | `live` — real HTTPS calls, real money |
| on | `test` | test secret key | – | – | `test` — real HTTPS calls against the provider's test account |
| on | `live` | none | – | – | `unavailable` — saving is refused: a live gateway with no key cannot take money |
| on | `test` | none | on | non-production, or `GATEWAY_SANDBOX=true` | `sandbox` — the built-in simulator, no network call |
| on | `test` | none | off | – | `unavailable` — and saving that combination is refused while no public key makes a real connection possible |

**The callback.** Each provider is told `https://<your host>/inventory/gateway_webhook.php?gateway=paystack` (the exact value is printed on the configuration card), and the same secret you paste into the webhook field is what signs the events. The route holds no session and no CSRF token; the signature is checked first, and a missing or wrong one is answered 401 **without** an event row being written — nothing an unverified caller says is stored. Only a payload that carries a valid signature for some company enters `gateway_events`.

**Environment needs:**
- `APP_URL` must be the publicly reachable base, because it forms both the callback URL and the return URL a provider redirects the customer back to. A host behind a tunnel or a LAN-only address cannot complete a live payment.
- `APP_KEY` should be set before the first credential is stored. `.env.example` ships it blank on purpose — a blank `APP_KEY` that is *present* is treated as a deployment mistake and gateway credentials cannot be sealed until it is filled in.
- `curl` (see §3) and outbound HTTPS to `api.paystack.co` / `api.flutterwave.com`.
- `GATEWAY_SANDBOX=false` in anything resembling production. While it is `true` on a host that claims production, the workspace shows the warning from `gatewaySandboxWarning()` on every screen.
- Set the company currency (`companies.currency`) before taking a payment: settlement in another currency is held as `currency_mismatch`, not converted.

## 9. Production checklist

1. `APP_ENV=production`, `APP_DEBUG=false`.
2. Dedicated database user with a strong password.
3. HTTPS enabled; the session cookie is marked `secure` automatically when HTTPS is detected.
4. `storage/logs/app.log` is writable and not web-accessible.
5. Backups scheduled **before** every migration and on a regular interval.
6. Legacy root scripts removed or kept blocked.
7. CDN assets pinned or vendored.
8. Admin password rotated from the installation default; test accounts removed.
