# Installation, Configuration and Migration Guide

**Updated:** 2026-11-02

---

## 1. Requirements

| Component | Tested version | Notes |
|---|---|---|
| PHP | 8.2.12 | 8.1+ recommended; requires PDO MySQL |
| Apache | 2.4 | `mod_rewrite` enabled, `AllowOverride All` |
| MariaDB / MySQL | MariaDB 10.4.32 | InnoDB, `utf8mb4` |
| Extensions | `pdo_mysql`, `mbstring`, `openssl` | Standard XAMPP build |

No Composer dependencies are required. Chart.js and Bootstrap are loaded from CDNs; the application also works offline for all non-chart pages.

Related guides: `ENVIRONMENT.md` (every environment/server setting), `MIGRATIONS.md` (runner, backups, failure recovery), `TROUBLESHOOTING.md`.

## 2. Environment configuration

1. Copy `.env.example` to `.env` in the project root.
2. Set the database values and application URL:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=inventory
DB_USER=inventory_app
DB_PASSWORD=change-me

APP_URL=http://localhost/inventory
APP_ENV=production
APP_DEBUG=false

SESSION_LIFETIME=120
```

| Key | Default | Consumed | Purpose |
|---|---|---|---|
| `DB_HOST` | `127.0.0.1` | Yes | Database host |
| `DB_PORT` | `3306` | Yes | Database port |
| `DB_NAME` | `inventory` | Yes | Database name |
| `DB_USER` | `root` | Yes | Database user |
| `DB_PASSWORD` | empty | Yes | Database password |
| `APP_URL` | current base path | Yes | Base URL used by `url()` |
| `APP_ENV` | `production` | Read | Environment label |
| `APP_DEBUG` | `false` | Yes | When true, PHP errors display and are logged verbosely |
| `SESSION_LIFETIME` | `120` | Yes | Session lifetime in minutes |

`SESSION_DRIVER` and the `FEATURE_*` keys in `.env.example` are reserved for future use and are not currently read by the application.

**Security:** keep `.env` out of version control and web access. `.htaccess` already denies `.env`; on cPanel, place it outside `public_html` if the hosting layout allows.

## 3. Fresh installation (new database)

The live schema is assembled from the original per-module files in `db/`, then brought up to date by `migrations/`.

1. Create the database:

```sql
CREATE DATABASE inventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Import the base schema and module schemas **in numeric order**:

```text
db/schema.sql
db/schema_module1.sql
db/schema_module2.sql
...
db/schema_module21.sql
```

3. Configure `.env` as described above.
4. Apply pending migrations:

```bash
php scripts/migrate.php
php scripts/migrate.php --status
```

`scripts/migrate.php` refuses to run from the web; it must be executed from the command line.

5. Set the administrator password:

```bash
php scripts/set_admin_password.php admin "A-strong-password-here"
```

6. Ensure `storage/logs/` and `backups/` are writable by Apache.
7. Open `http://localhost/inventory/` and sign in.

Do not import `migration_complete.sql` on top of an existing database. It is a legacy consolidated dump whose `users` table still uses the old `password` column; migration 001 renames it.

## 4. Upgrading an existing installation

1. **Back up first**, including the database and `.env`:

```bash
mysqldump -u root inventory > backups/inventory_pre_upgrade_$(date +%Y%m%d_%H%M%S).sql
```

2. Copy the updated application files over the existing installation (keep `.env`, `storage/`, `backups/`).
3. Review `migrations/` for new files and take a pre-migration dump into `backups/`.
4. Run:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

5. Check `storage/logs/app.log` for errors and open each module once.

Migrations are additive and tracked by checksum. Never edit an already-applied migration; create a new numbered file.

## 5. XAMPP setup specifics

- Place the project at `C:\xampp\htdocs\inventory`.
- `httpd.conf` must include `LoadModule rewrite_module modules/mod_rewrite.so` and `<Directory "C:/xampp/htdocs">` must have `AllowOverride All`.
- `.htaccess` uses `RewriteBase /inventory/`. Change this value if the project is served from a different subdirectory.
- Start Apache and MySQL from the XAMPP Control Panel.
- If the database password is empty (default XAMPP), leave `DB_PASSWORD=` empty.

## 6. cPanel deployment notes

1. Upload the project outside `public_html` or into a protected subdirectory, then point the domain/subdomain document root at it.
2. Create a database and a dedicated MySQL user with only the required privileges; update `.env`.
3. Import a database dump through phpMyAdmin, then run migrations from cPanel Terminal or via SSH.
4. Set the PHP version to 8.1+ and enable `pdo_mysql`.
5. Because the app is normally served from a subdirectory, keep `RewriteBase /inventory/` only if the URL path matches; otherwise set it to `/` (or the deployed subdirectory).
6. Confirm `.env`, `backups/`, `storage/`, `migrations/`, `docs/` and `db/` are not downloadable. The bundled `.htaccess` files deny direct access, but verify on the host.
7. Point the document root at the project root, not at `views/`.

## 7. Post-install verification

- `GET /inventory/` serves the landing page with the sign-in card; a signed-in user is redirected to the dashboard.
- Login succeeds; an invalid password shows a generic error and increments the failure counter.
- `php scripts/migrate.php --status` reports zero pending migrations.
- `storage/logs/app.log` contains no new errors after browsing each module.
- Dashboard cards and charts show zeroes (not errors) on an empty database.
