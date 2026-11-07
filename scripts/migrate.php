<?php
/**
 * Migration runner. CLI only.
 *
 * Usage:
 *   php scripts/migrate.php            Apply pending migrations
 *   php scripts/migrate.php --status   Show applied/pending migrations
 *   php scripts/migrate.php --reconcile-017
 *       Reconcile the known 017 currency-sweep checksum mismatch after 018.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Unable to connect to the database.\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(191) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration_id),
        UNIQUE KEY uk_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$statusOnly = in_array('--status', $argv, true);
$reconcile017 = in_array('--reconcile-017', $argv, true);
if ($statusOnly && $reconcile017) {
    fwrite(STDERR, "Use --status or --reconcile-017, not both.\n");
    exit(1);
}
$knownAppliedChecksum = 'a5e958b51225ae9038cf7440fb491df0435ee82d544d0c3e4504837b5fdec1a6';
$applied = [];
foreach ($pdo->query('SELECT migration, checksum, applied_at FROM schema_migrations ORDER BY migration') as $row) {
    $applied[$row['migration']] = $row;
}
$completed = array_fill_keys(array_keys($applied), true);
$reconcileMigration = null;

$files = glob(APP_ROOT . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "No migration files found in migrations/.\n";
    exit(0);
}

$pending = 0;
$failed = false;

foreach ($files as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read $name\n");
        exit(1);
    }
    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if (!hash_equals($applied[$name]['checksum'], $checksum)) {
            if ($reconcile017 && $name === '017_currency_sweep.sql'
                && hash_equals($knownAppliedChecksum, $applied[$name]['checksum'])) {
                $reconcileMigration = ['name' => $name, 'checksum' => $checksum];
                echo "[reconcile] $name checksum will be updated only after 018 is applied and verified.\n";
                continue;
            }
            fwrite(STDERR, "ERROR: $name was modified after being applied. Create a new migration instead.\n");
            exit(1);
        }
        if ($statusOnly) {
            echo "[applied] $name ({$applied[$name]['applied_at']})\n";
        }
        continue;
    }

    $pending++;
    if ($statusOnly) {
        echo "[pending] $name\n";
        continue;
    }

    echo "Applying $name ...\n";

    try {
        $statements = split_sql_statements($sql);
        foreach ($statements as $index => $statement) {
            if (trim($statement) === '') {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $preview = preg_replace('/\s+/', ' ', substr($statement, 0, 180));
                fwrite(STDERR, "FAILED in $name at statement " . ($index + 1) . ": " . $e->getMessage() . "\n");
                fwrite(STDERR, "Statement: $preview\n");
                throw $e;
            }
        }

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)');
        $stmt->execute([$name, $checksum]);
        $completed[$name] = true;
        echo "  OK\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "Migration $name did not complete. Fix the issue and rerun.\n");
        $failed = true;
        break;
    }
}

if ($statusOnly) {
    echo $pending === 0 ? "All migrations are applied.\n" : "$pending migration(s) pending.\n";
    exit(0);
}

if ($failed) {
    exit(1);
}

if ($reconcileMigration !== null) {
    if (empty($completed['018_shift_department_access.sql'])) {
        fwrite(STDERR, "Cannot reconcile 017 until 018_shift_department_access.sql is applied.\n");
        exit(1);
    }

    $requiredColumns = [
        'customers' => ['currency'],
        'sales_invoices' => [
            'currency', 'exchange_rate', 'base_subtotal', 'base_discount_amount',
            'base_tax_amount', 'base_total_amount', 'base_amount_paid', 'base_amount_owed',
        ],
        'customer_payments' => ['currency', 'exchange_rate', 'base_amount'],
        'quotes' => ['currency', 'exchange_rate'],
        'users' => ['department_id'],
        'cashier_shifts' => ['department_id', 'created_by'],
    ];
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnCheck->execute([$table, $column]);
            if ((int) $columnCheck->fetchColumn() !== 1) {
                fwrite(STDERR, "Cannot reconcile 017: $table.$column is missing.\n");
                exit(1);
            }
        }
    }

    $defaultCheck = $pdo->query(
        "SELECT column_default FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'currency'"
    )->fetchColumn();
    if (strtoupper(trim((string) $defaultCheck, "'\"")) !== 'NGN') {
        fwrite(STDERR, "Cannot reconcile 017: companies.currency does not default to NGN.\n");
        exit(1);
    }

    $companyCurrencyCheck = (int) $pdo->query(
        "SELECT COUNT(*) FROM companies WHERE currency <> 'NGN'"
    )->fetchColumn();
    if ($companyCurrencyCheck !== 0) {
        fwrite(STDERR, "Cannot reconcile 017: one or more company currencies are not NGN.\n");
        exit(1);
    }

    $companyRateCheck = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM companies c
         LEFT JOIN settings s ON s.company_id = c.company_id AND s.`key` = 'usd_to_ngn'
         WHERE s.company_id IS NULL"
    )->fetchColumn();
    if ($companyRateCheck !== 0) {
        fwrite(STDERR, "Cannot reconcile 017: a company is missing its USD rate setting.\n");
        exit(1);
    }

    $systemSettingCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM system_settings WHERE company_id = 1 AND `key` = 'usd_to_ngn'"
    );
    $systemSettingCheck->execute();
    if ((int) $systemSettingCheck->fetchColumn() !== 1) {
        fwrite(STDERR, "Cannot reconcile 017: the legacy USD rate setting is missing.\n");
        exit(1);
    }

    $rebase = $pdo->prepare('UPDATE schema_migrations SET checksum = ? WHERE migration = ? AND checksum = ?');
    $rebase->execute([
        $reconcileMigration['checksum'],
        $reconcileMigration['name'],
        $knownAppliedChecksum,
    ]);
    if ($rebase->rowCount() !== 1) {
        fwrite(STDERR, "Cannot reconcile 017: the stored checksum changed during migration.\n");
        exit(1);
    }
    echo "  Reconciled {$reconcileMigration['name']} after verifying its state.\n";
}

echo $pending === 0 ? "Nothing to migrate.\n" : "Applied $pending migration(s).\n";
