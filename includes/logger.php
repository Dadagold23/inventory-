<?php
/**
 * Structured file logging and global error handling.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!function_exists('app_log')) {
    function app_log(string $level, string $message, array $context = []): void
    {
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('log_app_exception')) {
    function log_app_exception(Throwable $exception): void
    {
        app_log('error', $exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

if (!function_exists('app_debug')) {
    function app_debug(): bool
    {
        return in_array(strtolower((string) app_env('APP_DEBUG', 'false')), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('app_install_error_handlers')) {
    function app_install_error_handlers(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', app_debug() ? '1' : '0');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            app_log('warning', $message, ['file' => $file, 'line' => $line]);
            return true;
        });

        set_exception_handler(function (Throwable $exception): void {
            log_app_exception($exception);
            http_response_code(500);
            if (app_debug()) {
                echo '<h1>Application error</h1><pre>' . e($exception->getMessage()) . '</pre>';
            } else {
                echo '<h1>Something went wrong</h1><p>Please contact your administrator.</p>';
            }
            exit;
        });
    }
}
