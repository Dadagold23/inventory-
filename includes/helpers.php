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

if (!function_exists('currencySymbol')) {
    /**
     * Return the display symbol for an ISO 4217 currency code.
     * Nigerian Naira (NGN) always shows ₦.
     * For other currencies the ISO code is returned so it is always clear
     * which foreign currency is being used (e.g. "USD 1,234.56").
     */
    function currencySymbol(string $code): string
    {
        $symbols = [
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'JPY' => '¥',
            'CNY' => '¥',
            'INR' => '₹',
        ];
        $upper = strtoupper(trim($code));
        return $symbols[$upper] ?? $upper;
    }
}

if (!function_exists('currencyLabel')) {
    /**
     * Human-readable label for a currency code (for dropdowns and headers).
     */
    function currencyLabel(string $code): string
    {
        $labels = [
            'NGN' => 'Nigerian Naira (₦)',
            'USD' => 'US Dollar ($)',
            'EUR' => 'Euro (€)',
            'GBP' => 'Pound Sterling (£)',
            'GHS' => 'Ghanaian Cedi (GH₵)',
            'KES' => 'Kenyan Shilling (KSh)',
            'ZAR' => 'South African Rand (R)',
            'CAD' => 'Canadian Dollar (C$)',
            'AUD' => 'Australian Dollar (A$)',
            'JPY' => 'Japanese Yen (¥)',
            'CNY' => 'Chinese Yuan (¥)',
            'INR' => 'Indian Rupee (₹)',
        ];
        $upper = strtoupper(trim($code));
        return $labels[$upper] ?? $upper;
    }
}

if (!function_exists('isNigerianCurrency')) {
    /**
     * True when a currency code resolves to Nigerian Naira.
     */
    function isNigerianCurrency(string $code): bool
    {
        return strtoupper(trim($code)) === 'NGN';
    }
}

if (!function_exists('customerCurrencyCode')) {
    /**
     * Determine the currency to use for a customer row.
     *
     * Rules (in order):
     *  1. If customers.currency is set, use it (explicit override).
     *  2. Blank country, "Nigeria" or "NG" means a Nigerian customer → NGN.
     *  3. Any other country means a foreign customer → USD.
     *
     * @param array  $customer  Row from the customers table (may be empty).
     * @param string $fallback  Retained for backwards compatibility; the
     *                          country rule above decides the result.
     */
    function customerCurrencyCode(array $customer, string $fallback = 'NGN'): string
    {
        // Explicit per-customer currency wins.
        $explicit = strtoupper(trim((string) ($customer['currency'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $country = strtolower(trim((string) ($customer['country'] ?? '')));
        if ($country === '' || in_array($country, ['nigeria', 'ng'], true)) {
            return 'NGN';
        }

        return 'USD';
    }
}

if (!function_exists('currencyExchangeRate')) {
    /**
     * How many Naira one unit of $currency is worth.
     *
     * NGN is the company's home currency, so its rate is always 1. Foreign
     * rates are read from the per-company settings table, keyed
     * `<code>_to_ngn` (so USD uses `usd_to_ngn`, seeded at 1500). Missing
     * USD settings use the documented default; invalid or unsupported rates
     * fail instead of silently posting a foreign sale at 1:1.
     */
    function currencyExchangeRate(PDO $pdo, string $currency, ?int $companyId = null): float
    {
        $code = strtoupper(trim($currency));
        if ($code === '' || $code === 'NGN') {
            return 1.0;
        }

        $companyId = $companyId ?? (function_exists('auth_company_id') ? max(1, auth_company_id()) : 1);
        $keys = [strtolower($code) . '_to_ngn', 'exchange_rate_' . strtolower($code)];

        foreach ($keys as $key) {
            try {
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = ? LIMIT 1");
                $stmt->execute([$key, $companyId]);
                $value = $stmt->fetchColumn();
                if ($value === false && $companyId !== 1) {
                    $fallback = $pdo->prepare("SELECT value FROM settings WHERE `key` = ? AND company_id = 1 LIMIT 1");
                    $fallback->execute([$key]);
                    $value = $fallback->fetchColumn();
                }
            } catch (Throwable $e) {
                $value = false;
            }

            if ($value !== false) {
                if (is_numeric($value) && (float) $value > 0) {
                    return (float) $value;
                }
                throw new RuntimeException('The configured ' . $code . ' to NGN exchange rate must be greater than zero.');
            }
        }

        if ($code === 'USD') {
            return 1500.0;
        }

        throw new RuntimeException('No NGN exchange rate is configured for ' . $code . '.');
    }
}

if (!function_exists('toBaseAmount')) {
    /**
     * Translate an amount in a sale's currency into the Naira ledger.
     * Base money is whole Naira, so the result is rounded to the unit.
     */
    function toBaseAmount(float $amount, float $exchangeRate): float
    {
        return round($amount * $exchangeRate, 0);
    }
}

if (!function_exists('moneyRound')) {
    /**
     * Round a monetary value to whole units. Quantities keep their decimals;
     * money does not.
     */
    function moneyRound(float $value): float
    {
        return round($value, 0);
    }
}

if (!function_exists('moneyInput')) {
    /**
     * Normalise a posted money value to a whole number. Every money input in
     * the app is an integer, so a crafted decimal POST must not slip through.
     */
    function moneyInput(mixed $value): float
    {
        return round((float) $value, 0);
    }
}

if (!function_exists('formatPrice')) {
    /**
     * Format a monetary amount with the correct currency symbol/code prefix.
     *
     * Signatures (all supported for backwards-compatibility):
     *   formatPrice($amount)
     *   formatPrice($pdo, $amount)          — legacy two-arg form (pdo ignored)
     *   formatPrice($amount, null, $currency)
     *   formatPrice($pdo, $amount, $currency)
     *
     * When $currency is supplied it must be an ISO 4217 code string ('NGN',
     * 'USD', …). Pass an empty string '' to suppress the symbol entirely and
     * get a bare number (needed by JS data-attributes and hidden inputs).
     *
     * Nigerian Naira uses prefix "₦" with no space (₦1,234.56).
     * All other currencies use prefix "CODE " with a space (USD 1,234.56).
     */
    function formatPrice($pdoOrAmount = 0, $amountOrNull = null, string $currency = 'NGN'): string
    {
        // Normalise arguments.
        if ($amountOrNull !== null && !is_string($amountOrNull)) {
            // formatPrice($pdo, $amount) or formatPrice($pdo, $amount, $currency)
            $value = (float) $amountOrNull;
        } elseif (is_numeric($pdoOrAmount)) {
            // formatPrice($amount) or formatPrice($amount, null, $currency)
            $value = (float) $pdoOrAmount;
        } else {
            $value = 0.0;
        }

        $formatted = number_format($value, 2);

        // No symbol requested.
        if ($currency === '') {
            return $formatted;
        }

        $upper = strtoupper(trim($currency));

        // Naira is a whole-number currency in this app: no kobo on screen.
        $formatted = number_format($value, $upper === 'NGN' ? 0 : 2);

        if ($upper === 'NGN') {
            return '₦' . $formatted;
        }

        // Foreign currencies: CODE prefix so it is always unambiguous.
        $sym = currencySymbol($upper);
        // If the symbol IS the code (no special glyph), show "USD 1,234.56".
        // If a real glyph exists (€, £, $) show the glyph directly: "€1,234.56".
        $isBareCode = ($sym === $upper);
        return $isBareCode ? ($sym . ' ' . $formatted) : ($sym . $formatted);
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
