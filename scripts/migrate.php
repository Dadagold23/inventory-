<?php
/**
 * Migration runner. CLI only.
 *
 * Usage:
 *   php scripts/migrate.php            Apply pending migrations
 *   php scripts/migrate.php --status   Show applied/pending migrations
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
$applied = [];
foreach ($pdo->query('SELECT migration, checksum, applied_at FROM schema_migrations ORDER BY migration') as $row) {
    $applied[$row['migration']] = $row;
}

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

echo $pending === 0 ? "Nothing to migrate.\n" : "Applied $pending migration(s).\n";
