<?php
/**
 * Single database configuration and connection service.
 * Reads .env (APP_ROOT/.env) with sane defaults for XAMPP.
 * All modules must obtain PDO through get_db_connection()/db().
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!function_exists('app_env_vars')) {
    /** Parsed configuration, loaded once and shared by reference. */
    function &app_env_vars(): array
    {
        static $vars = null;

        if ($vars === null) {
            $vars = [];
            $file = APP_ROOT . DIRECTORY_SEPARATOR . '.env';
            if (is_file($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    if (strlen($value) >= 2) {
                        $first = $value[0];
                        $last = $value[strlen($value) - 1];
                        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                            $value = substr($value, 1, -1);
                        }
                    }
                    $vars[$name] = $value;
                }
            }
            foreach (['APP_URL', 'APP_ENV', 'APP_DEBUG', 'APP_KEY', 'GATEWAY_SANDBOX', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
                if (!array_key_exists($name, $vars)) {
                    $value = getenv($name);
                    if ($value !== false) {
                        $vars[$name] = $value;
                    }
                }
            }
        }

        return $vars;
    }
}

if (!function_exists('app_env')) {
    /**
     * Read a configuration value from .env, then the process environment.
     */
    function app_env(string $key, $default = null)
    {
        $vars = &app_env_vars();

        return array_key_exists($key, $vars) ? $vars[$key] : $default;
    }
}

if (!function_exists('app_env_has')) {
    /**
     * True when a variable is present at all, even empty. Secrets must be
     * distinguishable from "absent": an empty APP_KEY is a deployment mistake
     * that must be reported, while no APP_KEY at all means the key file under
     * storage/keys/ is in use.
     */
    function app_env_has(string $key): bool
    {
        $vars = &app_env_vars();

        return array_key_exists($key, $vars);
    }
}

defined('DB_HOST') || define('DB_HOST', (string) app_env('DB_HOST', '127.0.0.1'));
defined('DB_PORT') || define('DB_PORT', (int) app_env('DB_PORT', 3306));
defined('DB_NAME') || define('DB_NAME', (string) app_env('DB_NAME', 'inventory'));
defined('DB_USER') || define('DB_USER', (string) app_env('DB_USER', 'root'));
defined('DB_PASSWORD') || define('DB_PASSWORD', (string) app_env('DB_PASSWORD', ''));
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('DB_COLLATION') || define('DB_COLLATION', 'utf8mb4_unicode_ci');

const DB_OPTIONS = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

defined('DSN') || define('DSN', 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);

/**
 * Shared PDO connection. Returns null if the database is unreachable.
 */
function get_db_connection(): ?PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(DSN, DB_USER, DB_PASSWORD, DB_OPTIONS);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Shared PDO connection that throws when unavailable.
 */
function db(): PDO
{
    $pdo = get_db_connection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }
    return $pdo;
}

/**
 * Check that all supplied tables exist in the current database.
 */
function tables_exist(PDO $pdo, array $table_names): bool
{
    if ($table_names === []) {
        return true;
    }

    $placeholders = implode(',', array_fill(0, count($table_names), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $stmt->execute(array_values($table_names));

    return (int) $stmt->fetchColumn() === count($table_names);
}

/**
 * Execute a .sql file statement by statement.
 * Intended for the migration runner only; records nothing itself.
 */
function execute_schema(PDO $pdo, string $sql_path): bool
{
    if (!is_file($sql_path)) {
        error_log('Schema file not found: ' . $sql_path);
        return false;
    }

    $sql = file_get_contents($sql_path);
    if ($sql === false) {
        error_log('Failed to read schema file: ' . $sql_path);
        return false;
    }

    foreach (split_sql_statements($sql) as $statement) {
        $pdo->exec($statement);
    }

    return true;
}

/**
 * Split a SQL script into statements without breaking quoted strings.
 * Intended for simple DDL migrations (no stored procedures).
 */
function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $in_single = false;
    $in_double = false;
    $in_line_comment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($in_line_comment) {
            if ($char === "\n") {
                $in_line_comment = false;
                $buffer .= $char;
            }
            continue;
        }

        if (!$in_single && !$in_double && $char === '-' && $next === '-') {
            $in_line_comment = true;
            $i++;
            continue;
        }

        if ($char === "'" && !$in_double) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $in_single = !$in_single;
            }
        } elseif ($char === '"' && !$in_single) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $in_double = !$in_double;
            }
        } elseif ($char === ';' && !$in_single && !$in_double) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}
