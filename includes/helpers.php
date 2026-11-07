<?php
/**
 * Shared view and request helpers.
 */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_base_path')) {
    /**
     * Base path of the application, e.g. "/inventory" (never a trailing slash).
     */
    function app_base_path(): string
    {
        $url = (string) app_env('APP_URL', '');
        $path = $url !== '' ? (string) parse_url($url, PHP_URL_PATH) : '';
        return rtrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Build an application URL that works when installed in a subdirectory.
     */
    function url(string $path = ''): string
    {
        $base = app_base_path();
        if ($path === '') {
            return $base === '' ? '/' : $base . '/';
        }
        if ($path[0] === '/') {
            return $base . $path;
        }
        return ($base === '' ? '' : $base) . '/' . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }
}

if (!function_exists('json_response')) {
    /**
     * Emit a JSON payload and stop. Keeps every API-ish endpoint consistent.
     */
    function json_response(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('formatPrice')) {
    /**
     * Single currency formatter.
     * Accepts either formatPrice($amount) or the legacy formatPrice($pdo, $amount).
     */
    function formatPrice($pdoOrAmount = 0, ?float $amount = null): string
    {
        if ($amount !== null) {
            $value = $amount;
        } elseif (is_numeric($pdoOrAmount)) {
            $value = (float) $pdoOrAmount;
        } else {
            $value = 0.0;
        }

        return number_format($value, 2);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token = null): bool
    {
        $token = $token ?? ($_POST['csrf_token'] ?? '');
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_post_csrf')) {
    function require_post_csrf(): void
    {
        if (!csrf_verify()) {
            http_response_code(403);
            exit('Invalid or expired CSRF token. Please reload the page and try again.');
        }
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}

if (!function_exists('old')) {
    function old(string $key, $default = '')
    {
        return $_SESSION['_old'][$key] ?? $default;
    }
}

if (!function_exists('input')) {
    function input(string $key, $default = '')
    {
        return trim((string) ($_POST[$key] ?? $_GET[$key] ?? $default));
    }
}
