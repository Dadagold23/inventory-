<?php
/**
 * Phase D: online payment gateways (Paystack and Flutterwave).
 *
 * The rules this layer exists to enforce:
 *
 *   - A redirect is not proof of payment. A sale is posted only after the
 *     gateway's own verification endpoint (or its signed webhook) says the
 *     money arrived, so gateway_transactions.invoice_id stays NULL until
 *     then and nothing else can create that sale.
 *   - Credentials never reach a browser. Secret keys and webhook secrets are
 *     encrypted at rest (crypto_functions.php), are write-only on screen and
 *     are only ever decrypted inside a server-side request to the provider.
 *   - One event, one effect. Webhooks are deduplicated by a unique key and
 *     finalisation runs under a row lock, so a redelivered or concurrent
 *     callback cannot post a second invoice.
 *   - No mock money in production. The simulator answers only when the
 *     gateway is in test mode without real keys, the company allows it, and
 *     the environment is not production; every simulated row carries
 *     simulates_payment = 1 for the rest of its life.
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/crypto_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';

// ============================================================
// Provider metadata
// ============================================================

if (!function_exists('gatewayProviders')) {
    /**
     * Everything the rest of the app may assume about a provider, in one
     * table: labels, endpoint, key prefixes and the field names it uses.
     */
    function gatewayProviders(): array
    {
        return [
            'paystack' => [
                'label' => 'Paystack',
                'base_live' => 'https://api.paystack.co',
                'base_test' => 'https://api.test.paystack.co',
                'secret_prefixes' => ['sk_test_', 'sk_live_', 'SECRET_KEY'],
                'public_prefixes' => ['pk_test_', 'pk_live_'],
                'webhook_header' => 'x-paystack-signature',
                'webhook_algorithm' => 'sha512',
                'amount_unit' => 'minor',
                'success_statuses' => ['success', 'paid', 'completed'],
                'paid_events' => ['charge.success'],
                'failed_events' => ['payment.failed', 'charge.failed'],
            ],
            'flutterwave' => [
                'label' => 'Flutterwave',
                'base_live' => 'https://api.flutterwave.com/v3',
                'base_test' => 'https://api.flutterwave.com/v3',
                'secret_prefixes' => ['FLWSECK-', 'FLWSECK_TEST-', 'FLWSECK_LIVE-'],
                'public_prefixes' => ['FLWPUBK-', 'FLWPUBK_TEST-', 'FLWPUBK_LIVE-'],
                'webhook_header' => 'verif-hash',
                'webhook_algorithm' => 'plain',
                'amount_unit' => 'major',
                'success_statuses' => ['successful', 'success', 'completed'],
                'paid_events' => ['charge.success', 'completed_charge_transaction'],
                'failed_events' => ['charge.refunded', 'transaction.failed', 'failed_charge_transaction'],
            ],
        ];
    }
}

if (!function_exists('gatewayIsKnownProvider')) {
    function gatewayIsKnownProvider(string $gateway): bool
    {
        return isset(gatewayProviders()[$gateway]);
    }
}

if (!function_exists('gatewayProviderLabel')) {
    function gatewayProviderLabel(string $gateway): string
    {
        return gatewayProviders()[$gateway]['label'] ?? ucfirst($gateway);
    }
}

if (!function_exists('gatewayBaseUrl')) {
    function gatewayBaseUrl(string $gateway, string $mode): string
    {
        $provider = gatewayProviders()[$gateway] ?? null;
        if ($provider === null) {
            return '';
        }

        return $mode === 'live' ? $provider['base_live'] : $provider['base_test'];
    }
}

if (!function_exists('gatewayAmountInProviderUnits')) {
    /**
     * Paystack expects kobo, Flutterwave v3 expects naira. Nothing else in the
     * app deals in minor units, so the conversion lives at the edge.
     */
    function gatewayAmountInProviderUnits(string $gateway, float $amount): float
    {
        $unit = gatewayProviders()[$gateway]['amount_unit'] ?? 'major';

        return $unit === 'minor' ? (int) round($amount * 100) : round($amount, 2);
    }
}

if (!function_exists('gatewayCurrencyFor')) {
    function gatewayCurrencyFor(PDO $pdo, int $companyId): string
    {
        $stmt = $pdo->prepare('SELECT currency FROM companies WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $currency = strtoupper(trim((string) $stmt->fetchColumn()));

        return $currency !== '' ? $currency : 'NGN';
    }
}

// ============================================================
// Configuration
// ============================================================

if (!function_exists('gatewayConfigRow')) {
    /** The stored row, or a defaults row when the company has not saved yet. */
    function gatewayConfigRow(PDO $pdo, int $companyId, string $gateway): array
    {
        if (!gatewayIsKnownProvider($gateway)) {
            throw new RuntimeException('Unknown payment gateway "' . $gateway . '".');
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM payment_gateways WHERE company_id = ? AND gateway = ?'
        );
        $stmt->execute([$companyId, $gateway]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        return [
            'company_id' => $companyId,
            'gateway' => $gateway,
            'mode' => 'test',
            'is_enabled' => 0,
            'sandbox_allowed' => 1,
            'test_public_key' => null, 'test_secret_key' => null, 'test_webhook_secret' => null,
            'live_public_key' => null, 'live_secret_key' => null, 'live_webhook_secret' => null,
            'last_checked_at' => null, 'last_check_result' => null,
        ];
    }
}

if (!function_exists('gatewayResolvedConfig')) {
    /**
     * Configuration with the credentials for the selected mode decrypted and
     * with the runtime mode worked out. The result may contain live secrets -
     * it must never be rendered, only used to call the provider.
     */
    function gatewayResolvedConfig(PDO $pdo, int $companyId, string $gateway): array
    {
        $row = gatewayConfigRow($pdo, $companyId, $gateway);
        $mode = ($row['mode'] === 'live') ? 'live' : 'test';
        $suffix = $mode === 'live' ? 'live' : 'test';

        $decryptError = null;
        $decrypt = static function (?string $stored) use (&$decryptError): ?string {
            try {
                return secretDecrypt($stored);
            } catch (Throwable $e) {
                $decryptError = 'Stored gateway credentials could not be decrypted — the secret key has changed. Re-enter them.';
                return null;
            }
        };

        $config = $row + [
            'runtime_mode' => 'unavailable',
            'decrypt_error' => null,
            'public_key' => $decrypt($row[$suffix . '_public_key'] ?? null),
            'secret_key' => $decrypt($row[$suffix . '_secret_key'] ?? null),
            'webhook_secret' => $decrypt($row[$suffix . '_webhook_secret'] ?? null),
            'has_test_keys' => $decrypt($row['test_secret_key'] ?? null) !== null,
            'has_live_keys' => $decrypt($row['live_secret_key'] ?? null) !== null,
            'has_webhook_secret' => $decrypt($row[$suffix . '_webhook_secret'] ?? null) !== null,
        ];
        $config['decrypt_error'] = $decryptError;
        $config['runtime_mode'] = $decryptError !== null ? 'unavailable' : gatewayRuntimeMode($config);

        return $config;
    }
}

if (!function_exists('gatewaySimulationPermitted')) {
    /**
     * May this host record simulated payments at all?
     *
     * Default answer on a production host is no - a mock must never be able to
     * impersonate a receipt. The only way past that is the explicit
     * GATEWAY_SANDBOX=true in .env, which is an administrator stating that
     * this machine is a workshop, not a till. When both flags disagree the
     * screens say so loudly rather than quietly allowing it.
     */
    function gatewaySimulationPermitted(): bool
    {
        $override = strtolower(trim((string) (function_exists('app_env') ? app_env('GATEWAY_SANDBOX', '') : getenv('GATEWAY_SANDBOX'))));
        $explicitlyAllowed = in_array($override, ['1', 'true', 'yes', 'on'], true);

        return !appIsProduction() || $explicitlyAllowed;
    }
}

if (!function_exists('gatewaySandboxWarning')) {
    /** Non-empty when simulations are enabled on a host that claims to be production. */
    function gatewaySandboxWarning(): string
    {
        if (appIsProduction() && gatewaySimulationPermitted()) {
            return 'Simulated payments are enabled on a host whose APP_ENV says production (GATEWAY_SANDBOX). '
                . 'Remove that setting before this machine takes real money.';
        }

        return '';
    }
}

if (!function_exists('gatewayRuntimeMode')) {
    /**
     * 'live' / 'test'  - real HTTPS calls with the keys for that mode.
     * 'sandbox'        - the built-in simulator; only in test mode, only when
     *                    no secret key is configured, only if the company
     *                    allows it, and never on a production host.
     * 'unavailable'    - enabled but unusable, or switched off.
     */
    function gatewayRuntimeMode(array $config): string
    {
        if (!(int) ($config['is_enabled'] ?? 0)) {
            return 'unavailable';
        }
        $secret = $config['secret_key'] ?? null;
        if (is_string($secret) && trim($secret) !== '') {
            return ($config['mode'] ?? 'test') === 'live' ? 'live' : 'test';
        }
        if (($config['mode'] ?? 'test') === 'live') {
            return 'unavailable';
        }
        if ((int) ($config['sandbox_allowed'] ?? 0) === 1 && gatewaySimulationPermitted()) {
            return 'sandbox';
        }

        return 'unavailable';
    }
}

if (!function_exists('gatewayConfigViews')) {
    /**
     * What the settings screen may show: state and hints only. The stored
     * ciphertext is never handed back, so a leaked page or a browser extension
     * cannot read a key.
     */
    function gatewayConfigViews(PDO $pdo, int $companyId): array
    {
        $views = [];
        foreach (array_keys(gatewayProviders()) as $gateway) {
            $row = gatewayConfigRow($pdo, $companyId, $gateway);
            $views[$gateway] = [
                'gateway' => $gateway,
                'label' => gatewayProviderLabel($gateway),
                'mode' => $row['mode'],
                'is_enabled' => (int) $row['is_enabled'],
                'sandbox_allowed' => (int) $row['sandbox_allowed'],
                'runtime_mode' => gatewayRuntimeMode(gatewayResolvedConfig($pdo, $companyId, $gateway)),
                'last_checked_at' => $row['last_checked_at'],
                'last_check_result' => $row['last_check_result'],
                'fields' => [],
            ];
            foreach (['test_public_key', 'test_secret_key', 'test_webhook_secret',
                      'live_public_key', 'live_secret_key', 'live_webhook_secret'] as $field) {
                try {
                    $plain = secretDecrypt($row[$field] ?? null);
                } catch (Throwable $e) {
                    $plain = null;
                }
                $isSecret = strpos($field, 'public') === false;
                $views[$gateway]['fields'][$field] = [
                    'set' => $plain !== null && trim($plain) !== '',
                    'hint' => $isSecret
                        ? secretHint($plain)
                        : (strlen((string) $plain) > 12 ? substr((string) $plain, 0, 8) . '…' . substr((string) $plain, -4) : (string) $plain),
                    'secret' => $isSecret,
                    'stored_encrypted' => secretIsEncrypted($row[$field] ?? null),
                ];
            }
        }

        return $views;
    }
}

if (!function_exists('gatewaySaveConfig')) {
    /**
     * Save configuration. A blank credential field means "leave what is
     * stored alone"; an explicit forget flag clears it, because a form that
     * echoes nothing cannot distinguish "unchanged" from "cleared" otherwise.
     *
     * @return array{ok:bool,messages:string[]}
     */
    function gatewaySaveConfig(PDO $pdo, int $companyId, string $gateway, array $input, int $userId): array
    {
        $messages = [];
        if (!gatewayIsKnownProvider($gateway)) {
            return ['ok' => false, 'messages' => ['Unknown gateway.']];
        }

        $existing = gatewayConfigRow($pdo, $companyId, $gateway);
        $mode = ($input['mode'] ?? $existing['mode']) === 'live' ? 'live' : 'test';
        $enabled = !empty($input['is_enabled']);
        $sandboxAllowed = !empty($input['sandbox_allowed']);

        $values = [
            'mode' => $mode,
            'is_enabled' => $enabled ? 1 : 0,
            'sandbox_allowed' => $sandboxAllowed ? 1 : 0,
        ];

        $fields = ['test_public_key', 'test_secret_key', 'test_webhook_secret',
                   'live_public_key', 'live_secret_key', 'live_webhook_secret'];
        foreach ($fields as $field) {
            $supplied = trim((string) ($input[$field] ?? ''));
            $clear = !empty($input['clear_' . $field]);
            if ($clear) {
                $values[$field] = null;
                continue;
            }
            if ($supplied === '') {
                continue; // keep the stored credential
            }
            $error = gatewayValidateKey($gateway, $field, $supplied);
            if ($error !== '') {
                return ['ok' => false, 'messages' => [$error]];
            }
            $values[$field] = secretEncrypt($supplied);
        }

        // Turning a gateway on must not be possible with nothing behind it.
        // array_merge, not +: the new values must win over the stored ones.
        $prospective = array_merge($existing, $values);
        $keyColumn = ($prospective['mode'] === 'live' ? 'live' : 'test') . '_secret_key';
        $prospective['secret_key'] = secretDecrypt($prospective[$keyColumn] ?? null);
        if ($enabled && gatewayRuntimeMode($prospective) === 'unavailable') {
            // The public key is what a browser needs to talk to the provider's
            // checkout. Without it there is nothing to hand the frontend, so a
            // real connection is refused rather than half-configured.
            $publicKey = secretDecrypt($prospective[($prospective['mode'] === 'live' ? 'live' : 'test') . '_public_key'] ?? null);
            $simulates = (string) $prospective['mode'] === 'test'
                && (int) $prospective['sandbox_allowed'] === 1 && gatewaySimulationPermitted();
            if (!$simulates && trim((string) $publicKey) === '') {
                return ['ok' => false, 'messages' => [
                    'Store the ' . ($prospective['mode'] === 'live' ? 'live' : 'test') . ' public key as well - it is what the checkout page needs before '
                    . gatewayProviderLabel($gateway) . ' can take payments.',
                ]];
            }

            return ['ok' => false, 'messages' => [
                'Enable ' . gatewayProviderLabel($gateway) . ' only after a '
                . ($mode === 'live' ? 'live' : 'test') . ' secret key is stored, or allow sandbox simulation in test mode.',
            ]];
        }

        if (trim((string) $prospective[$keyColumn]) !== ''
            && trim((string) secretDecrypt($prospective[($prospective['mode'] === 'live' ? 'live' : 'test') . '_public_key'] ?? null)) === '') {
            return ['ok' => false, 'messages' => [
                'A ' . ($prospective['mode'] === 'live' ? 'live' : 'test') . ' secret key needs its matching public key, so the checkout has something to identify your account with.',
            ]];
        }

        $exists = isset($existing['gateway_config_id']);
        try {
            $pdo->beginTransaction();
            if ($exists) {
                $sql = [];
                $args = [];
                foreach ($values as $column => $value) {
                    $sql[] = "`$column` = ?";
                    $args[] = $value;
                }
                $sql[] = 'updated_by = ?';
                $args[] = $userId ?: null;
                $args[] = $companyId;
                $args[] = $gateway;
                $pdo->prepare('UPDATE payment_gateways SET ' . implode(', ', $sql)
                    . ' WHERE company_id = ? AND gateway = ?')->execute($args);
            } else {
                $insert = [
                    $companyId, $gateway, $values['mode'], $values['is_enabled'], $values['sandbox_allowed'],
                    $values['test_public_key'] ?? null, $values['test_secret_key'] ?? null, $values['test_webhook_secret'] ?? null,
                    $values['live_public_key'] ?? null, $values['live_secret_key'] ?? null, $values['live_webhook_secret'] ?? null,
                    $userId ?: null,
                ];
                $pdo->prepare(
                    'INSERT INTO payment_gateways
                     (company_id, gateway, mode, is_enabled, sandbox_allowed,
                      test_public_key, test_secret_key, test_webhook_secret,
                      live_public_key, live_secret_key, live_webhook_secret, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute($insert);
            }
            if ($enabled) {
                // The tender and the configuration that makes it usable are one
                // decision; neither should exist without the other.
                $method = gatewayEnsureOnlineMethod($pdo, $companyId, $gateway);
                if ($method['created']) {
                    $messages[] = 'Payment method "' . $method['code'] . '" was added so cashiers can take '
                        . gatewayProviderLabel($gateway) . ' payments.';
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('error', 'Gateway configuration could not be saved: ' . $e->getMessage());
            return ['ok' => false, 'messages' => ['The configuration could not be saved.']];
        }

        // Audit the shape of the change, never its content. A configuration
        // written for the first time has no id in $existing, so the row is read
        // back: an entry pointing at gateway #0 would name no configuration at all.
        $configId = (int) ($existing['gateway_config_id'] ?? 0);
        if ($configId === 0) {
            $known = $pdo->prepare('SELECT gateway_config_id FROM payment_gateways WHERE company_id = ? AND gateway = ?');
            $known->execute([$companyId, $gateway]);
            $configId = (int) $known->fetchColumn();
        }
        $changed = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $values)) {
                $changed[] = $values[$field] === null ? "-$field" : "+$field";
            }
        }
        $details = json_encode([
            'gateway' => $gateway,
            'mode' => $values['mode'],
            'enabled' => (bool) $values['is_enabled'],
            'sandbox_allowed' => (bool) $values['sandbox_allowed'],
            'credentials' => $changed === [] ? 'unchanged' : $changed,
        ], JSON_UNESCAPED_SLASHES);
        gatewayAudit($pdo, $companyId, 'UPDATE', 'payment_gateways', $configId, (string) $details, $userId);

        $messages[] = gatewayProviderLabel($gateway) . ' configuration saved.';

        return ['ok' => true, 'messages' => $messages];
    }
}

if (!function_exists('gatewayEnsureOnlineMethod')) {
    /**
     * An enabled gateway needs a tender the cashier can pick, and payment_methods
     * is the only tender registry. Creates the online method for this provider
     * when the company has none; an existing one is left exactly as the
     * payment-methods screen set it, and disabling the gateway needs no change
     * here because gatewayOnlineMethods() drops methods with no usable config.
     */
    function gatewayEnsureOnlineMethod(PDO $pdo, int $companyId, string $gateway): array
    {
        $stmt = $pdo->prepare('SELECT code FROM payment_methods WHERE company_id = ? AND gateway = ? ORDER BY payment_method_id LIMIT 1');
        $stmt->execute([$companyId, $gateway]);
        if (($code = $stmt->fetchColumn())) {
            return ['created' => false, 'code' => (string) $code];
        }

        $taken = $pdo->prepare('SELECT code FROM payment_methods WHERE company_id = ? AND code = ?');
        $code = $gateway;
        $taken->execute([$companyId, $code]);
        if ($taken->fetchColumn()) {
            // A company may already use the name for something else; the gateway
            // method then gets a code of its own rather than hijacking that tender.
            $code = $gateway . '_online';
        }

        $pdo->prepare(
            'INSERT INTO payment_methods (company_id, code, name, description, is_cash, is_online, gateway, is_active, sort_order)
             VALUES (?, ?, ?, ?, 0, 1, ?, 1, ?)'
        )->execute([
            $companyId,
            $code,
            gatewayProviderLabel($gateway) . ' (online)',
            'Card, bank transfer and USSD payments taken through ' . gatewayProviderLabel($gateway) . '. Settled by the provider, not the till.',
            $gateway,
            90,
        ]);

        return ['created' => true, 'code' => $code];
    }
}

if (!function_exists('gatewayValidateKey')) {
    /**
     * Light shape checks: they catch a key pasted into the wrong box, which is
     * the most common way this configuration breaks, and nothing else.
     */
    function gatewayValidateKey(string $gateway, string $field, string $value): string
    {
        $provider = gatewayProviders()[$gateway];
        $label = $provider['label'];

        if (strlen($value) > 512) {
            return "The $field value looks too long to be a $label credential.";
        }
        if (strpos($field, 'secret_key') !== false && str_contains($field, 'webhook') === false) {
            $matchesPrefix = false;
            foreach ($provider['secret_prefixes'] as $prefix) {
                if (stripos($value, $prefix) === 0) {
                    $matchesPrefix = true;
                    if (strpos($field, 'test_') === 0 && stripos($value, '_live') !== false) {
                        return "A live $label secret key cannot be stored as the test key.";
                    }
                    if (strpos($field, 'live_') === 0 && stripos($value, '_test') !== false) {
                        return "A test $label secret key cannot be stored as the live key.";
                    }
                }
            }
            if (!$matchesPrefix) {
                return "That does not look like a $label secret key (expected one of: " . implode(', ', $provider['secret_prefixes']) . ').';
            }
        }
        if (strpos($field, 'public_key') !== false) {
            foreach ($provider['public_prefixes'] as $prefix) {
                if (stripos($value, $prefix) === 0) {
                    return '';
                }
            }

            return "That does not look like a $label public key.";
        }

        return '';
    }
}

if (!function_exists('gatewayWebhookUrl')) {
    /**
     * The URL an administrator pastes into the provider's dashboard. It is a
     * plain route: authentication is the signature, not a session.
     */
    function gatewayWebhookUrl(string $gateway): string
    {
        $base = rtrim((string) (function_exists('app_env') ? app_env('APP_URL', '/inventory') : '/inventory'), '/');

        return $base . '/gateway_webhook.php?gateway=' . rawurlencode($gateway);
    }
}

// ============================================================
// HTTP to the provider
// ============================================================

if (!function_exists('gatewayHttpRequest')) {
    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    function gatewayHttpRequest(string $method, string $url, string $secretKey, ?array $body = null): array
    {
        if ($secretKey === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'No secret key is configured for this gateway.'];
        }

        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload ?? '');
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            app_log('error', 'Gateway HTTP request failed: ' . $error);
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'The gateway could not be reached.'];
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'status' => $status, 'data' => [], 'error' => 'The gateway returned an unreadable response.'];
        }
        // Never log a response body that could carry card or key material.
        if ($status >= 400) {
            app_log('warning', 'Gateway returned HTTP ' . $status, ['url' => $url, 'gateway_status' => $data['status'] ?? null]);
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'error' => ''];
    }
}

// ============================================================
// Transactions
// ============================================================

if (!function_exists('gatewayNewReference')) {
    /** Our merchant reference. Unique per company by index, so collisions are retried. */
    function gatewayNewReference(string $gateway): string
    {
        return strtoupper(substr($gateway, 0, 2)) . 'REF-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('gatewayCreateTransaction')) {
    /**
     * Record a payment attempt before anything is sent to the provider, so an
     * initialise that dies mid-flight still leaves a row an operator can see.
     */
    function gatewayCreateTransaction(PDO $pdo, int $companyId, array $intent): array
    {
        $gateway = (string) ($intent['gateway'] ?? '');
        if (!gatewayIsKnownProvider($gateway)) {
            throw new RuntimeException('Unknown payment gateway.');
        }
        $reference = trim((string) ($intent['reference'] ?? '')) ?: gatewayNewReference($gateway);
        $snapshot = $intent['snapshot'] ?? [];
        $amount = round((float) ($intent['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('A payment needs an amount greater than zero.');
        }

        $pdo->prepare(
            'INSERT INTO gateway_transactions
             (company_id, gateway, mode, reference, branch_id, shift_id, user_id, customer_email,
              amount, currency, status, simulates_payment, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $companyId,
            $gateway,
            (string) ($intent['runtime_mode'] ?? 'test'),
            $reference,
            (int) ($intent['branch_id'] ?? 0) ?: null,
            (int) ($intent['shift_id'] ?? 0) ?: null,
            (int) ($intent['user_id'] ?? 0) ?: null,
            (string) ($intent['customer_email'] ?? '') ?: null,
            $amount,
            (string) ($intent['currency'] ?? 'NGN'),
            'pending',
            (string) ($intent['runtime_mode'] ?? 'test') === 'sandbox' ? 1 : 0,
            json_encode($snapshot, JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'transaction_id' => (int) $pdo->lastInsertId(),
            'reference' => $reference,
        ];
    }
}

if (!function_exists('gatewayTransaction')) {
    function gatewayTransaction(PDO $pdo, int $companyId, int $transactionId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM gateway_transactions WHERE company_id = ? AND transaction_id = ?');
        $stmt->execute([$companyId, $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('gatewayTransactionByReference')) {
    function gatewayTransactionByReference(PDO $pdo, int $companyId, string $reference, ?string $gateway = null): ?array
    {
        $sql = 'SELECT * FROM gateway_transactions WHERE company_id = ? AND reference = ?';
        $args = [$companyId, $reference];
        if ($gateway !== null && $gateway !== '') {
            $sql .= ' AND gateway = ?';
            $args[] = $gateway;
        }
        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('gatewayCompanyIdForReference')) {
    /**
     * Webhook resolution: find whose payment this is from the merchant
     * reference alone. The payload is never trusted for authentication - the
     * caller still has to prove the signature with that company's secret.
     */
    function gatewayCompanyIdForReference(PDO $pdo, string $reference): array
    {
        $stmt = $pdo->prepare('SELECT company_id, gateway, transaction_id FROM gateway_transactions WHERE reference = ?');
        $stmt->execute([$reference]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('gatewayUpdateTransaction')) {
    function gatewayUpdateTransaction(PDO $pdo, int $transactionId, array $fields): void
    {
        $allowed = ['gateway_reference', 'provider_id', 'checkout_url', 'status', 'channel',
                    'authorization_code', 'failure_reason', 'provider_response', 'verified_at',
                    'finalised_at', 'invoice_id', 'mode', 'simulates_payment'];
        $set = [];
        $args = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $set[] = "`$column` = ?";
            $args[] = $value;
        }
        if ($set === []) {
            return;
        }
        $args[] = $transactionId;
        $pdo->prepare('UPDATE gateway_transactions SET ' . implode(', ', $set) . ' WHERE transaction_id = ?')->execute($args);
    }
}

if (!function_exists('gatewayListTransactions')) {
    function gatewayListTransactions(PDO $pdo, int $companyId, array $filters = [], int $limit = 100): array
    {
        $sql = 'SELECT t.*, i.invoice_number,
                       (SELECT SUM(r.amount) FROM gateway_refunds r WHERE r.transaction_id = t.transaction_id AND r.status = \'successful\') AS refunded_amount
                FROM gateway_transactions t
                LEFT JOIN sales_invoices i ON i.invoice_id = t.invoice_id
                WHERE t.company_id = ?';
        $args = [$companyId];
        if (!empty($filters['gateway'])) {
            $sql .= ' AND t.gateway = ?';
            $args[] = $filters['gateway'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND t.status = ?';
            $args[] = $filters['status'];
        }
        if (!empty($filters['reference'])) {
            $sql .= ' AND t.reference LIKE ?';
            $args[] = '%' . $filters['reference'] . '%';
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND t.created_at >= ?';
            $args[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND t.created_at <= ?';
            $args[] = $filters['to'] . ' 23:59:59';
        }
        $sql .= ' ORDER BY t.transaction_id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================================
// Initialise / verify / refund per provider
// ============================================================

if (!function_exists('gatewayInitialize')) {
    /**
     * Ask the provider to open a payment session for this transaction row.
     * Returns the URL the customer is sent to. Nothing is posted to the sales
     * ledger here.
     *
     * @return array{ok:bool,message:string,checkout_url:string,reference:string,transaction_id:int}
     */
    function gatewayInitialize(PDO $pdo, int $companyId, array $intent): array
    {
        $gateway = (string) ($intent['gateway'] ?? '');
        $config = gatewayResolvedConfig($pdo, $companyId, $gateway);
        $runtime = $config['runtime_mode'];
        if ($runtime === 'unavailable') {
            return ['ok' => false, 'message' => gatewayProviderLabel($gateway) . ' is not usable right now (disabled, or no key for ' . $config['mode'] . ' mode).',
                    'checkout_url' => '', 'reference' => '', 'transaction_id' => 0];
        }

        $intent['runtime_mode'] = $runtime;
        $intent['currency'] = $intent['currency'] ?? gatewayCurrencyFor($pdo, $companyId);
        $created = gatewayCreateTransaction($pdo, $companyId, $intent);
        $transactionId = $created['transaction_id'];
        $reference = $created['reference'];

        if ($runtime === 'sandbox') {
            // The simulator's "checkout page" is inside this app, so the POS
            // round trip exercises the same verify/finalise code as a real card.
            $url = rtrim((string) (function_exists('app_env') ? app_env('APP_URL', '/inventory') : '/inventory'), '/')
                . '/sales.php?action=gateway_sandbox&reference=' . rawurlencode($reference);
            gatewayUpdateTransaction($pdo, $transactionId, ['checkout_url' => $url, 'provider_response' => json_encode(['simulated' => true])]);

            return ['ok' => true, 'message' => 'Sandbox session opened. No real money will move.',
                    'checkout_url' => $url, 'reference' => $reference, 'transaction_id' => $transactionId];
        }

        $amount = (float) $intent['amount'];
        $currency = (string) $intent['currency'];
        $email = (string) ($intent['customer_email'] ?? '');
        if ($email === '') {
            $email = gatewayContactEmail($pdo, $companyId);
        }
        $callback = rtrim((string) (function_exists('app_env') ? app_env('APP_URL', '/inventory') : '/inventory'), '/')
            . '/sales.php?action=gateway_return&reference=' . rawurlencode($reference);

        if ($gateway === 'paystack') {
            $response = gatewayHttpRequest('POST', gatewayBaseUrl($gateway, $config['mode']) . '/transaction/initialize', (string) $config['secret_key'], [
                'email' => $email,
                'amount' => gatewayAmountInProviderUnits($gateway, $amount),
                'reference' => $reference,
                'callback_url' => $callback,
                'metadata' => [
                    'company_id' => $companyId,
                    'custom_fields' => [[
                        'display_name' => 'Branch', 'variable_name' => 'branch',
                        'value' => (string) ($intent['branch_id'] ?? ''),
                    ]],
                ],
            ]);
            $data = $response['data']['data'] ?? [];
            if (!$response['ok'] || empty($data['authorization_url'])) {
                gatewayUpdateTransaction($pdo, $transactionId, [
                    'status' => 'failed',
                    'failure_reason' => mb_substr((string) ($response['data']['message'] ?? $response['error'] ?? 'Initialise failed'), 0, 255),
                ]);

                return ['ok' => false, 'message' => 'Could not start the payment: ' . (string) ($response['data']['message'] ?? $response['error'] ?? 'gateway error'),
                        'checkout_url' => '', 'reference' => $reference, 'transaction_id' => $transactionId];
            }
            gatewayUpdateTransaction($pdo, $transactionId, [
                'checkout_url' => (string) $data['authorization_url'],
                'gateway_reference' => (string) ($data['reference'] ?? $reference),
                'provider_id' => isset($data['id']) ? (string) $data['id'] : null,
                'provider_response' => json_encode($response['data'], JSON_UNESCAPED_SLASHES),
            ]);

            return ['ok' => true, 'message' => 'Payment session opened.', 'checkout_url' => (string) $data['authorization_url'],
                    'reference' => $reference, 'transaction_id' => $transactionId];
        }

        // Flutterwave v3
        $response = gatewayHttpRequest('POST', gatewayBaseUrl($gateway, $config['mode']) . '/payments', (string) $config['secret_key'], [
            'tx_ref' => $reference,
            'amount' => gatewayAmountInProviderUnits($gateway, $amount),
            'currency' => $currency,
            'redirect_url' => $callback,
            'customer' => ['email' => $email],
            'customization' => ['title' => 'Payment for sale', 'description' => $reference],
            'meta' => ['company_id' => $companyId],
        ]);
        $data = $response['data']['data'] ?? [];
        if (!$response['ok'] || empty($data['link'])) {
            gatewayUpdateTransaction($pdo, $transactionId, [
                'status' => 'failed',
                'failure_reason' => mb_substr((string) ($response['data']['message'] ?? $response['error'] ?? 'Initialise failed'), 0, 255),
            ]);

            return ['ok' => false, 'message' => 'Could not start the payment: ' . (string) ($response['data']['message'] ?? $response['error'] ?? 'gateway error'),
                    'checkout_url' => '', 'reference' => $reference, 'transaction_id' => $transactionId];
        }
        gatewayUpdateTransaction($pdo, $transactionId, [
            'checkout_url' => (string) $data['link'],
            'provider_id' => isset($data['id']) ? (string) $data['id'] : null,
            'provider_response' => json_encode($response['data'], JSON_UNESCAPED_SLASHES),
        ]);

        return ['ok' => true, 'message' => 'Payment session opened.', 'checkout_url' => (string) $data['link'],
                'reference' => $reference, 'transaction_id' => $transactionId];
    }
}

if (!function_exists('gatewayContactEmail')) {
    /**
     * Both providers require a customer email. A walk-in sale has none, so the
     * company email stands in - recorded, not invented.
     */
    function gatewayContactEmail(PDO $pdo, int $companyId): string
    {
        $stmt = $pdo->prepare('SELECT email FROM companies WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $email = trim((string) $stmt->fetchColumn());

        return $email !== '' ? $email : 'no-reply@' . gethostname() . '.invalid';
    }
}

if (!function_exists('gatewayNormaliseVerify')) {
    /**
     * Fold a provider verification body into one shape so no caller has to
     * know which field names which provider uses.
     *
     * @return array{status:string,paid:bool,amount:float,currency:string,gateway_reference:string,provider_id:string,channel:string,authorization_code:string,reason:string}
     */
    function gatewayNormaliseVerify(string $gateway, array $body): array
    {
        $data = $body['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }
        $rawStatus = strtolower(trim((string) ($data['status'] ?? ($body['status'] ?? 'unknown'))));
        $successes = gatewayProviders()[$gateway]['success_statuses'] ?? ['success'];
        $paid = in_array($rawStatus, $successes, true);

        $amount = (float) ($data['amount'] ?? 0);
        if (($gateway === 'paystack') && $amount > 0) {
            $amount = $amount / 100;   // kobo
        }

        return [
            'status' => $rawStatus,
            'paid' => $paid,
            'amount' => round($amount, 2),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'gateway_reference' => (string) ($data['gateway_reference'] ?? $data['flw_ref'] ?? $data['reference'] ?? $data['tx_ref'] ?? ''),
            'provider_id' => isset($data['id']) ? (string) $data['id'] : '',
            'channel' => (string) ($data['channel'] ?? $data['type'] ?? ''),
            'authorization_code' => (string) ($data['authorization_code']['authorization_code'] ?? $data['authorization_code'] ?? ''),
            'reason' => (string) ($data['failure_reason'] ?? $data['message'] ?? $body['message'] ?? ''),
        ];
    }
}

if (!function_exists('gatewayVerify')) {
    /**
     * Ask the provider whether this reference was paid, record the answer and,
     * when it says yes, finalise the sale. This is the only door to a posted
     * online sale (besides the webhook, which calls the same code).
     *
     * @return array{ok:bool,message:string,status:string,paid:bool,transaction:?array,invoice_id:int}
     */
    function gatewayVerify(PDO $pdo, int $companyId, string $reference, ?string $gateway = null): array
    {
        $transaction = gatewayTransactionByReference($pdo, $companyId, $reference, $gateway);
        if ($transaction === null) {
            return ['ok' => false, 'message' => 'Unknown payment reference.', 'status' => 'unknown', 'paid' => false, 'transaction' => null, 'invoice_id' => 0];
        }

        $gateway = (string) $transaction['gateway'];
        if ($transaction['mode'] === 'sandbox') {
            // The simulator stores its own verdict on the row; the operator
            // pressed Approve/Decline on the sandbox page.
            $normal = [
                'status' => (string) $transaction['status'],
                'paid' => in_array($transaction['status'], ['paid', 'refunded', 'partial_refund'], true),
                'amount' => (float) $transaction['amount'],
                'currency' => (string) $transaction['currency'],
                'gateway_reference' => (string) ($transaction['gateway_reference'] ?? ''),
                'provider_id' => (string) ($transaction['provider_id'] ?? ''),
                'channel' => 'sandbox',
                'authorization_code' => '',
                'reason' => (string) ($transaction['failure_reason'] ?? ''),
            ];
            if (!$normal['paid']) {
                return ['ok' => $normal['status'] === 'pending', 'message' => 'Sandbox says the payment is still ' . $normal['status'] . '.',
                        'status' => $normal['status'], 'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
            }
        } else {
            $config = gatewayResolvedConfig($pdo, $companyId, $gateway);
            if ($config['runtime_mode'] === 'unavailable') {
                return ['ok' => false, 'message' => gatewayProviderLabel($gateway) . ' is not usable right now.', 'status' => 'unknown',
                        'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
            }
            $path = $gateway === 'paystack'
                ? '/transaction/verify/' . rawurlencode($reference)
                : '/transactions/verify/' . rawurlencode($reference);
            $response = gatewayHttpRequest('GET', gatewayBaseUrl($gateway, $config['mode']) . $path, (string) $config['secret_key']);
            if (!$response['ok']) {
                return ['ok' => false, 'message' => 'The gateway could not be reached: '
                    . (string) ($response['data']['message'] ?? $response['error'] ?? 'error'), 'status' => 'unknown',
                    'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
            }
            $normal = gatewayNormaliseVerify($gateway, $response['data']);
            gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                'provider_response' => json_encode($response['data'], JSON_UNESCAPED_SLASHES),
                'gateway_reference' => $normal['gateway_reference'] ?: $transaction['gateway_reference'],
                'provider_id' => $normal['provider_id'] ?: $transaction['provider_id'],
                'channel' => $normal['channel'] ?: $transaction['channel'],
                'authorization_code' => $normal['authorization_code'] ?: $transaction['authorization_code'],
                'verified_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$normal['paid']) {
                // A provider that says "still waiting" must not be recorded as a failure.
                $failure = !in_array($normal['status'], ['pending', 'abandoned', ''], true);
                if ($failure) {
                    gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                        'status' => 'failed',
                        'failure_reason' => mb_substr($normal['reason'] ?: $normal['status'], 0, 255),
                    ]);
                }
                $transaction = gatewayTransaction($pdo, $companyId, (int) $transaction['transaction_id']);
                return ['ok' => !$failure, 'message' => $normal['reason'] !== '' ? $normal['reason'] : 'The gateway reports: ' . $normal['status'],
                        'status' => $normal['status'], 'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) ($transaction['invoice_id'] ?? 0)];
            }

            // Amount and currency are checked before anything is posted: a
            // partial or wrong-currency settlement is a reconciliation item,
            // not a paid sale.
            $verdict = gatewaySettlementVerdict($transaction, $normal);
            gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], $verdict['write']);
            $transaction = gatewayTransaction($pdo, $companyId, (int) $transaction['transaction_id']);
            if (!$verdict['post']) {
                return ['ok' => false, 'message' => $verdict['message'], 'status' => $verdict['status'],
                        'paid' => false, 'transaction' => $transaction, 'invoice_id' => 0];
            }
        }

        $finalised = gatewayFinaliseSale($pdo, $companyId, (int) $transaction['transaction_id']);

        return $finalised + ['paid' => true, 'status' => 'paid'];
    }
}

if (!function_exists('gatewaySettlementVerdict')) {
    /**
     * Does the provider's answer describe the payment we asked for? Stated as a
     * pure comparison so a settlement that is short, over-paid or in the wrong
     * currency can be tested without a live gateway - money the provider took
     * for a session we cannot honour is captured, held and never posted.
     *
     * @return array{post:bool,status:string,message:string,write:array}
     */
    function gatewaySettlementVerdict(array $transaction, array $normal): array
    {
        $session = round((float) $transaction['amount'], 2);
        $settled = round((float) ($normal['amount'] ?? 0), 2);
        $held = [
            'status' => 'paid',   // the provider did take it; it is ours to reconcile, not to spend
            'write' => ['status' => 'paid'],
        ];

        if (abs($settled - $session) > 0.005) {
            $reason = 'Settled ' . number_format($settled, 2) . ' but the session was for ' . number_format($session, 2) . '.';

            return ['post' => false, 'status' => 'amount_mismatch',
                    'message' => 'The amount settled does not match the payment session - held for reconciliation.',
                    'write' => $held['write'] + ['failure_reason' => $reason]];
        }

        $currency = strtoupper((string) ($normal['currency'] ?? ''));
        $sessionCurrency = strtoupper((string) $transaction['currency']);
        if ($currency !== '' && $currency !== $sessionCurrency) {
            $reason = 'Settled in ' . $currency . ' but the session was in ' . $sessionCurrency . '.';

            return ['post' => false, 'status' => 'currency_mismatch',
                    'message' => 'Settled in a currency other than the one quoted - held for reconciliation.',
                    'write' => $held['write'] + ['failure_reason' => $reason]];
        }

        return ['post' => true, 'status' => 'paid', 'message' => '', 'write' => ['status' => 'paid', 'failure_reason' => null]];
    }
}

// ============================================================
// Finalisation - the one door to a posted online sale
// ============================================================

if (!function_exists('gatewayFinaliseSale')) {
    /**
     * Turn a verified payment into an invoice. Called by gatewayVerify() and by
     * the webhook handler; both paths converge here so the rules are stated
     * once. The session row is claimed under a lock and released before the
     * sale is written, because createSalesInvoice() opens its own transaction
     * and MySQL has no nested BEGIN. What keeps a duplicate from costing
     * anything is the idempotency key derived from the payment reference.
     *
     * @return array{ok:bool,message:string,invoice_id:int,status:string,transaction:?array}
     */
    function gatewayFinaliseSale(PDO $pdo, int $companyId, int $transactionId): array
    {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM gateway_transactions WHERE transaction_id = ? AND company_id = ? FOR UPDATE');
            $stmt->execute([$transactionId, $companyId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $shiftId = 0;
            if ($transaction && (int) ($transaction['shift_id'] ?? 0) > 0) {
                $status = $pdo->prepare('SELECT status FROM cashier_shifts WHERE shift_id = ?');
                $status->execute([(int) $transaction['shift_id']]);
                // Attribution only: an online receipt is not drawer cash, and a
                // closed shift's reconciliation must not move after it was counted.
                $shiftId = (string) $status->fetchColumn() === 'open' ? (int) $transaction['shift_id'] : 0;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('error', 'Gateway session claim failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'The payment session could not be read: ' . $e->getMessage(),
                    'invoice_id' => 0, 'status' => 'error', 'transaction' => null];
        }

        if (!$transaction) {
            return ['ok' => false, 'message' => 'Payment session not found.', 'invoice_id' => 0, 'status' => 'missing', 'transaction' => null];
        }
        if ((int) $transaction['invoice_id'] > 0) {
            $invoiceId = (int) $transaction['invoice_id'];

            return ['ok' => true, 'message' => 'Already recorded on invoice #' . $invoiceId . '.', 'invoice_id' => $invoiceId,
                    'status' => (string) $transaction['status'], 'transaction' => $transaction];
        }
        if (!in_array($transaction['status'], ['paid', 'refunded', 'partial_refund'], true)) {
            return ['ok' => false, 'message' => 'This payment is ' . $transaction['status'] . ', so no sale can be posted against it.',
                    'invoice_id' => 0, 'status' => (string) $transaction['status'], 'transaction' => $transaction];
        }

        $snapshot = json_decode((string) ($transaction['payload'] ?? ''), true);
        if (!is_array($snapshot) || empty($snapshot['lines'])) {
            gatewayUpdateTransaction($pdo, $transactionId, ['failure_reason' => 'The payment session has no cart snapshot.']);

            return ['ok' => false, 'message' => 'The payment session carries no cart snapshot, so the sale cannot be rebuilt.',
                    'invoice_id' => 0, 'status' => 'no_snapshot',
                    'transaction' => gatewayTransaction($pdo, $companyId, $transactionId)];
        }

        // Prices can move while the customer is at the gateway. The cart is
        // re-priced from the catalogue before anything is written, so paying the
        // old price holds the payment for reconciliation instead of silently
        // posting a differently-priced sale.
        $quote = gatewayQuoteSnapshot($pdo, $companyId, $snapshot, (int) ($transaction['user_id'] ?? 0));
        if (!$quote['ok']) {
            gatewayUpdateTransaction($pdo, $transactionId, ['failure_reason' => mb_substr($quote['message'], 0, 255)]);

            return ['ok' => false, 'message' => $quote['message'], 'invoice_id' => 0,
                    'status' => 'no_snapshot', 'transaction' => gatewayTransaction($pdo, $companyId, $transactionId)];
        }
        if (abs($quote['total'] - (float) $transaction['amount']) > 0.005) {
            gatewayUpdateTransaction($pdo, $transactionId, [
                'failure_reason' => 'The catalogue now totals ' . number_format($quote['total'], 2)
                    . ' but ' . number_format((float) $transaction['amount'], 2) . ' was paid.',
            ]);

            return ['ok' => false, 'message' => 'Prices changed while the payment was in progress - held for reconciliation.',
                    'invoice_id' => 0, 'status' => 'price_drift', 'transaction' => gatewayTransaction($pdo, $companyId, $transactionId)];
        }

        $method = (string) ($snapshot['method'] ?? ((string) $transaction['gateway'] . '_online'));
        $invoice = gatewayInCompanyContext($pdo, $companyId, (int) ($transaction['user_id'] ?? 0), static function () use ($pdo, $companyId, $transaction, $snapshot, $quote, $method, $shiftId) {
            return createSalesInvoice($pdo, [
                'company_id' => $companyId,
                'customer_id' => (int) ($snapshot['customer_id'] ?? 0),
                'branch_id' => (int) ($transaction['branch_id'] ?? 0),
                'shift_id' => $shiftId,
                'created_by' => (int) ($transaction['user_id'] ?? 0),
                'idempotency_key' => 'gw:' . $transaction['reference'],
                'issue_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d'),
                'tax_rate' => $quote['tax_rate'],
                'payment_method' => $method,
                'reference_number' => (string) $transaction['reference'],
                'notes' => trim((string) ($snapshot['notes'] ?? '')) ?: ('Online payment via ' . gatewayProviderLabel((string) $transaction['gateway'])),
                'lines' => $quote['lines'],
                'payments' => [[
                    'method' => $method,
                    'amount' => round((float) $transaction['amount'], 2),
                    'reference' => (string) $transaction['reference'],
                ]],
            ]);
        });

        if (!($invoice['success'] ?? false)) {
            gatewayUpdateTransaction($pdo, $transactionId, ['failure_reason' => mb_substr((string) ($invoice['message'] ?? 'Post failed'), 0, 255)]);

            return ['ok' => false, 'message' => (string) ($invoice['message'] ?? 'The sale could not be posted.'),
                    'invoice_id' => 0, 'status' => (string) $transaction['status'], 'transaction' => gatewayTransaction($pdo, $companyId, $transactionId)];
        }

        $invoiceId = (int) $invoice['invoice_id'];
        $held = abs((float) $invoice['total'] - (float) $transaction['amount']) > 0.005;
        $pdo->prepare('UPDATE gateway_transactions SET invoice_id = ?, finalised_at = NOW() WHERE transaction_id = ? AND COALESCE(invoice_id, 0) = 0')
            ->execute([$invoiceId, $transactionId]);
        if ($held) {
            gatewayUpdateTransaction($pdo, $transactionId, [
                'failure_reason' => 'The sale posted at ' . number_format((float) $invoice['total'], 2)
                    . ' but ' . number_format((float) $transaction['amount'], 2) . ' was paid.',
            ]);
        }

        gatewayAudit($pdo, $companyId, 'UPDATE', 'gateway_transactions', $transactionId, json_encode([
            'reference' => $transaction['reference'],
            'invoice_id' => $invoiceId,
            'amount' => (float) $transaction['amount'],
            'gateway' => $transaction['gateway'],
            'simulated' => (bool) $transaction['simulates_payment'],
        ], JSON_UNESCAPED_SLASHES), (int) ($transaction['user_id'] ?? 0));

        if ($held) {
            return ['ok' => false, 'message' => 'The sale is posted but the amount paid does not match its total - held for reconciliation.',
                    'invoice_id' => $invoiceId, 'status' => 'price_drift',
                    'transaction' => gatewayTransaction($pdo, $companyId, $transactionId)];
        }

        return ['ok' => true, 'message' => (string) ($invoice['message'] ?? 'Sale recorded.'),
                'invoice_id' => $invoiceId, 'status' => 'paid',
                'transaction' => gatewayTransaction($pdo, $companyId, $transactionId),
                'invoice' => $invoice];
    }
}

if (!function_exists('gatewayQuoteSnapshot')) {
    /**
     * Price a cart snapshot against the catalogue as it stands right now, using
     * the same maths createSalesInvoice() will apply. Nothing is written here -
     * the point is to learn the total before an invoice exists to be wrong.
     *
     * @return array{ok:bool,message:string,lines:array,tax_rate:float,total:float}
     */
    function gatewayQuoteSnapshot(PDO $pdo, int $companyId, array $snapshot, int $userId = 0): array
    {
        $quote = gatewayInCompanyContext($pdo, $companyId, $userId, static function () use ($pdo, $snapshot) {
            // Lines arrive without prices on purpose: salesNormaliseLines() reads
            // each one from products, so a stale or hand-written price in the
            // snapshot cannot survive into the sale.
            $lines = salesNormaliseLines($pdo, $snapshot['lines'] ?? []);
            if ($lines === []) {
                return ['ok' => false, 'message' => 'The saved cart has no sellable lines left.', 'lines' => [], 'tax_rate' => 0.0, 'total' => 0.0];
            }
            $taxRate = array_key_exists('tax_rate', $snapshot) && $snapshot['tax_rate'] !== ''
                ? (float) $snapshot['tax_rate']
                : (float) salesSetting($pdo, 'default_tax_rate', '0');

            $subtotal = 0.0;
            foreach ($lines as $line) {
                $subtotal += $line['line_total'];
            }
            $subtotal = salesRound($subtotal);

            return [
                'ok' => true,
                'message' => '',
                'lines' => $lines,
                'tax_rate' => $taxRate,
                'total' => salesRound($subtotal + salesRound($subtotal * $taxRate)),
            ];
        });

        return $quote;
    }
}

if (!function_exists('gatewayInCompanyContext')) {
    /**
     * createSalesInvoice() consults company-scoped settings, which are read
     * through the session. A webhook has no session, so the tenant is scoped
     * for the duration of the call and restored afterwards.
     */
    function gatewayInCompanyContext(PDO $pdo, int $companyId, int $userId, callable $work)
    {
        $previousCompany = $_SESSION['company_id'] ?? null;
        $previousUser = $_SESSION['user_id'] ?? null;
        $_SESSION['company_id'] = $companyId;
        if ($userId > 0) {
            $_SESSION['user_id'] = $userId;
        }
        try {
            return $work();
        } finally {
            if ($previousCompany === null) {
                unset($_SESSION['company_id']);
            } else {
                $_SESSION['company_id'] = $previousCompany;
            }
            if ($previousUser === null) {
                unset($_SESSION['user_id']);
            } else {
                $_SESSION['user_id'] = $previousUser;
            }
        }
    }
}

if (!function_exists('gatewayAudit')) {
    /**
     * auth_audit() stamps the company taken from the session and drops the row
     * when there is no user to attribute it to. A provider callback has
     * neither, so the tenant and the cashier who owns the payment are named
     * here and the entry lands in the trail it belongs to.
     */
    function gatewayAudit(PDO $pdo, int $companyId, string $action, string $entityType, ?int $entityId, string $details, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        gatewayInCompanyContext($pdo, $companyId, $userId, static function () use ($pdo, $action, $entityType, $entityId, $details, $userId) {
            auth_audit($pdo, $action, $entityType, $entityId, $details, $userId);
        });
    }
}

// ============================================================
// Sandbox simulator
// ============================================================

if (!function_exists('gatewaySandboxRespond')) {
    /**
     * The sandbox "gateway verdict". Only a sandbox-mode row can be answered
     * this way, and only on a non-production host; in every case the row keeps
     * simulates_payment = 1 so the money is never mistaken for a real receipt.
     *
     * @return array{ok:bool,message:string,status:string,paid:bool,transaction:?array,invoice_id:int}
     */
    function gatewaySandboxRespond(PDO $pdo, int $companyId, string $reference, string $outcome, int $userId): array
    {
        $transaction = gatewayTransactionByReference($pdo, $companyId, $reference);
        if ($transaction === null) {
            return ['ok' => false, 'message' => 'Unknown payment reference.', 'status' => 'missing', 'paid' => false, 'transaction' => null, 'invoice_id' => 0];
        }
        if ($transaction['mode'] !== 'sandbox') {
            return ['ok' => false, 'message' => 'This session was opened against a real gateway and must be answered by that gateway.',
                    'status' => (string) $transaction['status'], 'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
        }
        if (!gatewaySimulationPermitted()) {
            return ['ok' => false, 'message' => 'Simulated payments cannot be recorded on a production host.',
                    'status' => (string) $transaction['status'], 'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
        }
        if (gatewayRuntimeMode(gatewayResolvedConfig($pdo, $companyId, (string) $transaction['gateway'])) !== 'sandbox') {
            return ['ok' => false, 'message' => 'Sandbox simulation is no longer allowed for this gateway.',
                    'status' => (string) $transaction['status'], 'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
        }

        $outcome = strtolower($outcome);
        if (in_array($outcome, ['approve', 'paid', 'success'], true)) {
            gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                'status' => 'paid',
                'gateway_reference' => 'SANDBOX-' . $reference,
                'provider_id' => 'SANDBOX-' . $transaction['transaction_id'],
                'channel' => 'sandbox',
                'verified_at' => date('Y-m-d H:i:s'),
                'failure_reason' => null,
                'provider_response' => json_encode(['simulated' => true, 'outcome' => 'paid'], JSON_UNESCAPED_SLASHES),
            ]);

            return gatewayVerify($pdo, $companyId, $reference);
        }
        if (in_array($outcome, ['decline', 'failed', 'fail'], true)) {
            gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                'status' => 'failed',
                'failure_reason' => 'Declined in the sandbox',
                'provider_response' => json_encode(['simulated' => true, 'outcome' => 'failed'], JSON_UNESCAPED_SLASHES),
            ]);

            return ['ok' => true, 'message' => 'Sandbox declined the payment. Nothing was posted.',
                    'status' => 'failed', 'paid' => false, 'transaction' => gatewayTransaction($pdo, $companyId, (int) $transaction['transaction_id']),
                    'invoice_id' => 0];
        }
        if (in_array($outcome, ['timeout', 'expire', 'pending'], true)) {
            gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                'status' => $outcome === 'pending' ? 'pending' : 'expired',
                'failure_reason' => $outcome === 'pending' ? null : 'Left unpaid in the sandbox',
            ]);

            return ['ok' => true, 'message' => 'Sandbox session left unanswered.',
                    'status' => $outcome === 'pending' ? 'pending' : 'expired', 'paid' => false,
                    'transaction' => gatewayTransaction($pdo, $companyId, (int) $transaction['transaction_id']), 'invoice_id' => 0];
        }

        return ['ok' => false, 'message' => 'Unknown sandbox outcome.', 'status' => (string) $transaction['status'],
                'paid' => false, 'transaction' => $transaction, 'invoice_id' => (int) $transaction['invoice_id']];
    }
}

// ============================================================
// Webhooks
// ============================================================

if (!function_exists('gatewayVerifySignature')) {
    /**
     * Does this raw body carry the signature this company's secret produces?
     * Paystack: HMAC-SHA512 of the body. Flutterwave: the shared verif-hash.
     */
    function gatewayVerifySignature(string $gateway, string $secret, string $rawBody, string $signature): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        $provider = gatewayProviders()[$gateway] ?? null;
        if ($provider === null) {
            return false;
        }
        if ($provider['webhook_algorithm'] === 'plain') {
            return hash_equals($secret, $signature);
        }
        $expected = hash_hmac($provider['webhook_algorithm'], $rawBody, $secret);

        return hash_equals($expected, strtolower($signature));
    }
}

if (!function_exists('gatewayNormaliseEvent')) {
    /**
     * One shape for a provider event body. Returns the merchant reference and
     * whether this event means "the money arrived".
     *
     * @return array{event_id:string,event_type:string,reference:string,paid:bool,failed:bool,amount:float,currency:string,gateway_reference:string,provider_id:string,channel:string}
     */
    function gatewayNormaliseEvent(string $gateway, array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $eventType = (string) ($body['event'] ?? $data['event'] ?? $data['type'] ?? '');

        $reference = (string) ($data['reference'] ?? $data['tx_ref'] ?? $data['order_ref']
            ?? ($data['transaction']['reference'] ?? '') ?? ($data['transaction']['tx_ref'] ?? '') ?? '');

        $status = strtolower((string) ($data['status'] ?? ''));
        $successes = gatewayProviders()[$gateway]['success_statuses'] ?? ['success'];
        $paidEvents = gatewayProviders()[$gateway]['paid_events'] ?? [];
        $failedEvents = gatewayProviders()[$gateway]['failed_events'] ?? [];

        $amount = (float) ($data['amount'] ?? $data['charge_amount'] ?? 0);
        if ($gateway === 'paystack' && $amount > 0) {
            $amount /= 100;
        }

        return [
            'event_id' => isset($data['id']) ? (string) $data['id'] : '',
            'event_type' => $eventType,
            'reference' => $reference,
            'paid' => in_array($eventType, $paidEvents, true) || ($reference !== '' && in_array($status, $successes, true) && stripos($eventType, 'refund') === false),
            'failed' => in_array($eventType, $failedEvents, true),
            'amount' => round($amount, 2),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'gateway_reference' => (string) ($data['flw_ref'] ?? $data['gateway_reference'] ?? ''),
            'provider_id' => isset($data['id']) ? (string) $data['id'] : '',
            'channel' => (string) ($data['channel'] ?? $data['type'] ?? ''),
        ];
    }
}

if (!function_exists('gatewayHandleWebhook')) {
    /**
     * Accept one provider callback. Authentication is the signature: the
     * merchant reference in the body only tells us which company's secret to
     * check it against, and an unsigned or mis-signed body is refused before
     * anything is read.
     *
     * @return array{status:int,body:string}  HTTP answer for the provider
     */
    function gatewayHandleWebhook(PDO $pdo, string $gateway, string $rawBody, array $headers): array
    {
        if (!gatewayIsKnownProvider($gateway)) {
            return ['status' => 404, 'body' => 'Unknown gateway.'];
        }
        $headerName = gatewayProviders()[$gateway]['webhook_header'];
        $signature = trim((string) ($headers[$headerName] ?? $headers[strtolower($headerName)] ?? ''));
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            return ['status' => 400, 'body' => 'Unreadable payload.'];
        }
        $event = gatewayNormaliseEvent($gateway, $body);

        // Which tenant? Narrow by the reference when it is ours, otherwise try
        // every configured secret; either way the signature decides.
        $candidates = [];
        if ($event['reference'] !== '') {
            foreach (gatewayCompanyIdForReference($pdo, $event['reference']) as $hit) {
                if ((string) $hit['gateway'] === $gateway) {
                    $candidates[] = (int) $hit['company_id'];
                }
            }
        }
        if ($candidates === []) {
            foreach ($pdo->query('SELECT company_id FROM payment_gateways WHERE gateway = ' . $pdo->quote($gateway)) as $row) {
                $candidates[] = (int) $row['company_id'];
            }
        }

        $companyId = 0;
        foreach (array_unique($candidates) as $candidate) {
            $secret = (string) (gatewayResolvedConfig($pdo, $candidate, $gateway)['webhook_secret'] ?? '');
            if (gatewayVerifySignature($gateway, $secret, $rawBody, $signature)) {
                $companyId = $candidate;
                break;
            }
        }
        if ($companyId === 0) {
            app_log('warning', 'Rejected gateway webhook', ['gateway' => $gateway, 'reason' => 'signature']);
            return ['status' => 401, 'body' => 'Invalid signature.'];
        }

        $dedupe = hash('sha256', $gateway . '|' . ($event['event_id'] !== '' ? $event['event_id'] : $rawBody));
        try {
            $pdo->prepare(
                'INSERT INTO gateway_events (company_id, gateway, provider_event_id, dedupe_key, event_type, transaction_ref, signature_valid, payload)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
            )->execute([
                $companyId, $gateway, $event['event_id'] ?: null, $dedupe, $event['event_type'],
                $event['reference'] ?: null, mb_substr($rawBody, 0, 60000),
            ]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_gateway_event_dedupe')) {
                return ['status' => 200, 'body' => 'Already received.'];  // redelivery: acknowledge, do nothing
            }
            throw $e;
        }
        $eventId = (int) $pdo->lastInsertId();

        $result = 'no matching transaction';
        $transaction = $event['reference'] !== '' ? gatewayTransactionByReference($pdo, $companyId, $event['reference'], $gateway) : null;
        if ($transaction !== null) {
            if ($event['paid']) {
                gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                    'status' => 'paid',
                    'gateway_reference' => $event['gateway_reference'] ?: $transaction['gateway_reference'],
                    'provider_id' => $event['provider_id'] ?: $transaction['provider_id'],
                    'channel' => $event['channel'] ?: $transaction['channel'],
                    'verified_at' => date('Y-m-d H:i:s'),
                    'provider_response' => mb_substr($rawBody, 0, 60000),
                    'failure_reason' => null,
                ]);
                // Always re-ask the gateway. A webhook announces; it does not prove.
                $answer = gatewayVerify($pdo, $companyId, (string) $transaction['reference'], $gateway);
                $result = $answer['ok'] ? 'posted: ' . $answer['message'] : 'held: ' . $answer['message'];
            } elseif ($event['failed']) {
                gatewayUpdateTransaction($pdo, (int) $transaction['transaction_id'], [
                    'status' => 'failed',
                    'failure_reason' => mb_substr((string) ($body['data']['failure_reason'] ?? 'Failed at the gateway'), 0, 255),
                ]);
                $result = 'marked failed';
            } elseif (stripos($event['event_type'], 'refund') !== false) {
                $result = gatewayApplyRefundEvent($pdo, $companyId, $transaction, $body, $event['event_type']);
            }
        } else {
            // A refund or charge event for a reference we do not know is still
            // worth keeping: it is stored above, and reconciliation reports it.
            $result = stripos($event['event_type'], 'refund') !== false
                ? gatewayApplyRefundEvent($pdo, $companyId, null, $body, $event['event_type'])
                : $result;
        }

        $pdo->prepare('UPDATE gateway_events SET processed = 1, result = ? WHERE log_id = ?')->execute([mb_substr($result, 0, 255), $eventId]);

        return ['status' => 200, 'body' => 'Received.'];
    }
}

if (!function_exists('gatewayApplyRefundEvent')) {
    /** Match a provider refund callback onto its refund row. */
    function gatewayApplyRefundEvent(PDO $pdo, int $companyId, ?array $transaction, array $body, string $eventType): string
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $providerRefund = (string) ($data['id'] ?? $data['refund_id'] ?? $data['reference'] ?? $data['flw_ref'] ?? '');
        if ($providerRefund === '') {
            return 'refund event with no refund id';
        }
        $stmt = $pdo->prepare(
            'SELECT refund_id, status FROM gateway_refunds WHERE company_id = ? AND (provider_refund_reference = ? OR refund_reference = ?)'
        );
        $stmt->execute([$companyId, $providerRefund, $providerRefund]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            return 'refund event for an unknown refund';
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        $newStatus = in_array($status, ['success', 'successful', 'processed', 'completed'], true) ? 'successful'
            : (in_array($status, ['failed', 'error'], true) ? 'failed' : (string) $refund['status']);
        $pdo->prepare('UPDATE gateway_refunds SET status = ?, provider_response = ?, updated_at = NOW() WHERE refund_id = ?')
            ->execute([$newStatus, json_encode($data, JSON_UNESCAPED_SLASHES), (int) $refund['refund_id']]);
        if ($transaction !== null && $newStatus === 'successful') {
            gatewayRefreshRefundStatus($pdo, (int) $transaction['transaction_id']);
        }

        return 'refund ' . $newStatus;
    }
}

// ============================================================
// Refunds
// ============================================================

if (!function_exists('gatewayRefundedTotal')) {
    function gatewayRefundedTotal(PDO $pdo, int $transactionId): float
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM gateway_refunds WHERE transaction_id = ? AND status IN ('pending','successful')");
        $stmt->execute([$transactionId]);

        return round((float) $stmt->fetchColumn(), 2);
    }
}

if (!function_exists('gatewayIssueRefund')) {
    /**
     * Send money back through the gateway that took it. The invoice is not
     * edited - it was paid, and this is the record that money left afterwards.
     *
     * @return array{ok:bool,message:string,refund:?array}
     */
    function gatewayIssueRefund(PDO $pdo, int $companyId, int $transactionId, float $amount, string $reason, int $userId): array
    {
        $transaction = gatewayTransaction($pdo, $companyId, $transactionId);
        if ($transaction === null) {
            return ['ok' => false, 'message' => 'Payment session not found.', 'refund' => null];
        }
        if (!in_array($transaction['status'], ['paid', 'partial_refund', 'refunded'], true)) {
            return ['ok' => false, 'message' => 'Only a paid payment can be refunded; this one is ' . $transaction['status'] . '.', 'refund' => null];
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'A refund needs an amount greater than zero.', 'refund' => null];
        }
        $already = gatewayRefundedTotal($pdo, $transactionId);
        $ceiling = round((float) $transaction['amount'], 2);
        if ($already + $amount > $ceiling + 0.005) {
            return ['ok' => false, 'message' => 'That would refund ' . number_format($already + $amount, 2)
                . ' of a ' . number_format($ceiling, 2) . ' payment.', 'refund' => null];
        }

        $gateway = (string) $transaction['gateway'];
        $refundReference = 'RFD-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $simulated = $transaction['mode'] === 'sandbox';
        if ($simulated && !gatewaySimulationPermitted()) {
            return ['ok' => false, 'message' => 'A simulated payment cannot be refunded on a production host.', 'refund' => null];
        }

        $provider = gatewayResolvedConfig($pdo, $companyId, $gateway);
        if (!$simulated && $provider['runtime_mode'] === 'unavailable') {
            return ['ok' => false, 'message' => gatewayProviderLabel($gateway) . ' is not usable right now, so the refund cannot be sent.', 'refund' => null];
        }

        $status = 'pending';
        $providerRefund = null;
        $responseBody = ['simulated' => true];
        if ($simulated) {
            $status = 'successful';
            $providerRefund = 'SANDBOX-' . $refundReference;
        } else {
            $amountForProvider = gatewayAmountInProviderUnits($gateway, $amount);
            $response = $gateway === 'paystack'
                ? gatewayHttpRequest('POST', gatewayBaseUrl($gateway, $provider['mode']) . '/transaction/' . rawurlencode((string) $transaction['provider_id']) . '/refund',
                    (string) $provider['secret_key'], ['amount' => $amountForProvider, 'refund_reason' => $reason ?: 'Customer refund'])
                : gatewayHttpRequest('POST', gatewayBaseUrl($gateway, $provider['mode']) . '/refunds',
                    (string) $provider['secret_key'], ['tx_id' => (int) $transaction['provider_id'], 'amount' => $amountForProvider, 'comments' => $reason ?: 'Customer refund']);
            $data = is_array($response['data']['data'] ?? null) ? $response['data']['data'] : [];
            if (!$response['ok']) {
                return ['ok' => false, 'message' => 'The gateway refused the refund: '
                    . (string) ($response['data']['message'] ?? $response['error'] ?? 'error'), 'refund' => null];
            }
            $status = in_array(strtolower((string) ($data['status'] ?? 'pending')), ['success', 'successful', 'pending', 'processed'], true)
                ? (in_array(strtolower((string) ($data['status'] ?? '')), ['success', 'successful', 'processed'], true) ? 'successful' : 'pending')
                : 'failed';
            $providerRefund = (string) ($data['id'] ?? $data['reference'] ?? $data['flw_ref'] ?? '');
            $responseBody = $response['data'];
        }

        $pdo->prepare(
            'INSERT INTO gateway_refunds
             (company_id, gateway, transaction_id, refund_reference, provider_refund_reference, amount, currency,
              status, reason, simulates_payment, provider_response, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $companyId, $gateway, $transactionId, $refundReference, $providerRefund ?: null, $amount,
            $transaction['currency'], $status, $reason ?: null, $simulated ? 1 : 0,
            json_encode($responseBody, JSON_UNESCAPED_SLASHES), $userId ?: null,
        ]);
        $refundId = (int) $pdo->lastInsertId();
        if ($status === 'successful') {
            gatewayRefreshRefundStatus($pdo, $transactionId);
        }

        gatewayAudit($pdo, $companyId, 'CREATE', 'gateway_refunds', $refundId, json_encode([
            'reference' => $refundReference, 'payment_reference' => $transaction['reference'],
            'amount' => $amount, 'gateway' => $gateway, 'status' => $status, 'simulated' => $simulated,
        ], JSON_UNESCAPED_SLASHES), $userId);

        return ['ok' => true, 'message' => 'Refund ' . $refundReference . ' ' . $status . '.',
                'refund' => ['refund_id' => $refundId, 'refund_reference' => $refundReference, 'amount' => $amount, 'status' => $status]];
    }
}

if (!function_exists('gatewayRefreshRefundStatus')) {
    /** Keep the payment row honest: fully refunded, partly refunded, or paid. */
    function gatewayRefreshRefundStatus(PDO $pdo, int $transactionId): void
    {
        $stmt = $pdo->prepare('SELECT amount FROM gateway_transactions WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);
        $total = round((float) $stmt->fetchColumn(), 2);
        $refunded = round((float) $pdo->query(
            'SELECT COALESCE(SUM(amount), 0) FROM gateway_refunds WHERE transaction_id = ' . (int) $transactionId . " AND status = 'successful'"
        )->fetchColumn(), 2);

        $status = $refunded <= 0 ? 'paid' : ($refunded + 0.005 >= $total ? 'refunded' : 'partial_refund');
        $pdo->prepare('UPDATE gateway_transactions SET status = ? WHERE transaction_id = ?')->execute([$status, $transactionId]);
    }
}

if (!function_exists('gatewayListRefunds')) {
    function gatewayListRefunds(PDO $pdo, int $companyId, int $transactionId = 0): array
    {
        $sql = 'SELECT * FROM gateway_refunds WHERE company_id = ?';
        $args = [$companyId];
        if ($transactionId > 0) {
            $sql .= ' AND transaction_id = ?';
            $args[] = $transactionId;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY refund_id DESC');
        $stmt->execute($args);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('gatewayListEvents')) {
    /**
     * Provider callbacks as they arrived, newest first. The payload is stored
     * when it is received, so a dispute is settled from the app's own record.
     */
    function gatewayListEvents(PDO $pdo, int $companyId, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM gateway_events WHERE company_id = ? ORDER BY log_id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute([$companyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('gatewayTestConnection')) {
    /**
     * Prove the stored credentials work, without touching money: one read-only
     * call that lists past charges. The verdict is stored on the configuration
     * so an operator can see when the link last answered, from any screen.
     *
     * @return array{ok:bool,message:string,checked_at:string}
     */
    function gatewayTestConnection(PDO $pdo, int $companyId, string $gateway, int $userId = 0): array
    {
        $config = gatewayResolvedConfig($pdo, $companyId, $gateway);
        $runtime = gatewayRuntimeMode($config);
        $checkedAt = date('Y-m-d H:i:s');

        if ($runtime === 'sandbox') {
            $message = 'Sandbox simulation is active, so no provider was contacted. Store a '
                . ($config['mode'] === 'live' ? 'live' : 'test') . ' secret key and turn simulation off to test a real connection.';
        } elseif ($runtime === 'unavailable') {
            $message = 'Nothing to test: this gateway is disabled or has no secret key for ' . $config['mode'] . ' mode.';
        } else {
            $path = $gateway === 'paystack' ? '/transaction?perPage=1' : '/transactions';
            $response = gatewayHttpRequest('GET', gatewayBaseUrl($gateway, (string) $config['mode']) . $path, (string) $config['secret_key']);
            $status = (int) $response['status'];
            if ($response['ok'] && $status >= 200 && $status < 300) {
                $listed = is_array($response['data']['data'] ?? null)
                    ? (isset($response['data']['data'][0]) ? count($response['data']['data']) : 1)
                    : 0;
                $message = 'Connected to ' . gatewayProviderLabel($gateway) . ' in ' . $runtime . ' mode'
                    . ($listed > 0 ? '; the account reports ' . $listed . ' charge' . ($listed === 1 ? '' : 's') . '.' : '.');
            } elseif ($status === 401 || $status === 403) {
                $message = 'The key was rejected (HTTP ' . $status . '). Check that it belongs to the ' . $config['mode'] . ' account.';
            } else {
                $message = 'The provider did not answer: ' . mb_substr((string) ($response['data']['message'] ?? $response['error'] ?? 'HTTP ' . $status), 0, 200);
            }
        }

        $stmt = $pdo->prepare('UPDATE payment_gateways SET last_checked_at = ?, last_check_result = ? WHERE company_id = ? AND gateway = ?');
        $stmt->execute([$checkedAt, mb_substr($message, 0, 255), $companyId, $gateway]);

        gatewayAudit($pdo, $companyId, 'READ', 'payment_gateways', (int) ($config['gateway_config_id'] ?? 0), json_encode([
            'gateway' => $gateway, 'mode' => $config['mode'], 'runtime' => $runtime, 'result' => $message,
        ], JSON_UNESCAPED_SLASHES), $userId);

        return ['ok' => $runtime !== 'unavailable', 'message' => $message, 'checked_at' => $checkedAt];
    }
}

// ============================================================
// Reconciliation and hygiene
// ============================================================

if (!function_exists('gatewayExpireStale')) {
    /**
     * Sessions nobody finished. Left pending forever they hide in reports, so
     * they are expired by age (the providers themselves expire theirs too).
     */
    function gatewayExpireStale(PDO $pdo, int $companyId, int $minutes = 60): int
    {
        $stmt = $pdo->prepare(
            "UPDATE gateway_transactions SET status = 'expired', failure_reason = COALESCE(failure_reason, 'No payment received')
             WHERE company_id = ? AND status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL " . max(1, $minutes) . " MINUTE)"
        );
        $stmt->execute([$companyId]);

        return $stmt->rowCount();
    }
}

if (!function_exists('gatewayReconcile')) {
    /**
     * Where the ledger and the gateway disagree, in the order an operator
     * triages them: money that arrived but was never posted, money posted
     * without a payment behind it, wrong amounts, and double captures.
     *
     * @return array{buckets:array<string,array<int,array>>, totals:array<string,float>}
     */
    function gatewayReconcile(PDO $pdo, int $companyId, ?string $from = null, ?string $to = null): array
    {
        $where = 't.company_id = ?';
        $args = [$companyId];
        if ($from !== null && $from !== '') {
            $where .= ' AND t.created_at >= ?';
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $where .= ' AND t.created_at <= ?';
            $args[] = $to . ' 23:59:59';
        }

        $buckets = [
            'paid_not_posted' => [],
            'posted_not_paid' => [],
            'amount_drift' => [],
            'duplicate_capture' => [],
            'stale_pending' => [],
            'refund_mismatch' => [],
        ];

        $stmt = $pdo->prepare(
            "SELECT t.* FROM gateway_transactions t WHERE $where AND t.status IN ('paid','partial_refund','refunded') AND t.invoice_id IS NULL
             ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $buckets['paid_not_posted'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.* FROM gateway_transactions t WHERE $where AND t.invoice_id IS NOT NULL AND t.status NOT IN ('paid','partial_refund','refunded')
             ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $buckets['posted_not_paid'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.*, i.total_amount, i.amount_paid, i.status AS invoice_status
             FROM gateway_transactions t JOIN sales_invoices i ON i.invoice_id = t.invoice_id
             WHERE $where AND (ABS(t.amount - i.total_amount) > 0.005 OR ABS(t.amount - i.amount_paid) > 0.005)
             ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $buckets['amount_drift'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.*, i.invoice_number FROM gateway_transactions t
             JOIN sales_invoices i ON i.invoice_id = t.invoice_id
             WHERE $where AND t.invoice_id IN (
                SELECT invoice_id FROM gateway_transactions WHERE company_id = ? AND invoice_id IS NOT NULL GROUP BY invoice_id HAVING COUNT(*) > 1)
             ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute(array_merge($args, [$companyId]));
        $buckets['duplicate_capture'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.* FROM gateway_transactions t WHERE $where AND t.status = 'pending'
             AND t.created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $buckets['stale_pending'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT t.*, COALESCE((SELECT SUM(r.amount) FROM gateway_refunds r WHERE r.transaction_id = t.transaction_id AND r.status = 'successful'), 0) AS refunded
             FROM gateway_transactions t
             WHERE $where AND ((t.status IN ('refunded','partial_refund') AND COALESCE((SELECT SUM(r.amount) FROM gateway_refunds r WHERE r.transaction_id = t.transaction_id AND r.status = 'successful'), 0) <= 0.005)
                OR (t.status = 'paid' AND COALESCE((SELECT SUM(r.amount) FROM gateway_refunds r WHERE r.transaction_id = t.transaction_id AND r.status = 'successful'), 0) > 0.005))
             ORDER BY t.transaction_id DESC LIMIT 200"
        );
        $stmt->execute($args);
        $buckets['refund_mismatch'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = array_fill_keys(['captured', 'refunded', 'posted', 'unposted', 'simulated_captured'], 0.0);
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN t.status IN ('paid','partial_refund','refunded') THEN t.amount ELSE 0 END), 0) AS captured,
                COALESCE(SUM(CASE WHEN t.simulates_payment = 1 AND t.status IN ('paid','partial_refund','refunded') THEN t.amount ELSE 0 END), 0) AS simulated_captured,
                COALESCE(SUM(CASE WHEN t.invoice_id IS NOT NULL THEN t.amount ELSE 0 END), 0) AS posted,
                COALESCE(SUM(CASE WHEN t.status IN ('paid','partial_refund','refunded') AND t.invoice_id IS NULL THEN t.amount ELSE 0 END), 0) AS unposted
             FROM gateway_transactions t WHERE $where"
        );
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['captured', 'simulated_captured', 'posted', 'unposted'] as $key) {
            $totals[$key] = round((float) ($row[$key] ?? 0), 2);
        }
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM gateway_refunds WHERE company_id = ? AND status = \'successful\'');
        $stmt->execute([$companyId]);
        $totals['refunded'] = round((float) $stmt->fetchColumn(), 2);
        $totals['simulated_captured'] = round((float) ($row['simulated_captured'] ?? 0), 2);

        return ['buckets' => $buckets, 'totals' => $totals];
    }
}

// ============================================================
// Payment method bridge
// ============================================================

if (!function_exists('gatewayOnlineMethods')) {
    /**
     * Active payment methods that are actually takeable online: the method must
     * be flagged online and name a gateway that is currently usable. The POS
     * offers exactly this list, so no customer is ever sent to a dead gateway.
     *
     * @return array<string,array{code:string,name:string,gateway:string,runtime_mode:string}>
     */
    function gatewayOnlineMethods(PDO $pdo, int $companyId): array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT code, name, gateway FROM payment_methods
                 WHERE company_id = ? AND is_active = 1 AND is_online = 1 AND gateway IS NOT NULL
                 ORDER BY sort_order, payment_method_id'
            );
            $stmt->execute([$companyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $methods = [];
        foreach ($rows as $row) {
            $gateway = (string) $row['gateway'];
            $runtime = gatewayRuntimeMode(gatewayResolvedConfig($pdo, $companyId, $gateway));
            if ($runtime === 'unavailable') {
                continue;
            }
            $methods[(string) $row['code']] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'gateway' => $gateway,
                'runtime_mode' => $runtime,
            ];
        }

        return $methods;
    }
}

if (!function_exists('gatewaySummaryForUser')) {
    /** Small numbers strip for the gateway dashboard. */
    function gatewaySummaryForUser(PDO $pdo, int $companyId): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status IN ('paid','partial_refund','refunded') THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN status IN ('paid','partial_refund','refunded') AND invoice_id IS NULL THEN 1 ELSE 0 END) AS unposted,
                SUM(CASE WHEN simulates_payment = 1 THEN 1 ELSE 0 END) AS simulated
             FROM gateway_transactions WHERE company_id = ?"
        );
        $stmt->execute([$companyId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

// ============================================================
// The till: opening and following a payment session for a cart
// ============================================================

if (!function_exists('gatewayPosSession')) {
    /**
     * Open a payment session for the cart this cashier is holding. The amount
     * and the lines come from the server-side cart, never from the browser,
     * and the snapshot saved here is what the sale is rebuilt from - the
     * customer may finish paying after this screen has moved on.
     *
     * Pressing the button twice reuses the session that is still waiting at
     * the same total. A cart that has changed supersedes the old session,
     * because two live sessions for one basket is how a customer gets charged
     * twice for the same sale.
     *
     * @return array{ok:bool,message:string,gateway:string,tender:string,transaction_id:int,reference:string,checkout_url:string,amount:float,reused:bool}
     */
    function gatewayPosSession(PDO $pdo, int $companyId, string $tender, int $branchId, int $userId): array
    {
        $fail = static function (string $message): array {
            return ['ok' => false, 'message' => $message, 'gateway' => '', 'tender' => '', 'transaction_id' => 0,
                    'reference' => '', 'checkout_url' => '', 'amount' => 0.0, 'reused' => false];
        };

        $methods = gatewayOnlineMethods($pdo, $companyId);
        if (!isset($methods[$tender])) {
            return $fail('"' . $tender . '" is not an online payment method you can take right now.');
        }
        $gateway = (string) $methods[$tender]['gateway'];

        $cart = $_SESSION['pos_cart'] ?? [];
        if ($cart === []) {
            return $fail('The cart is empty, so there is nothing to pay for.');
        }
        recalculatePosTotals($pdo);
        $amount = round((float) ($_SESSION['pos_total'] ?? 0), 2);
        if ($amount <= 0) {
            return $fail('The cart total is zero, so no payment is needed.');
        }

        require_once APP_ROOT . '/includes/shift_functions.php';
        $shiftId = 0;
        if (shiftEnforcementEnabled($pdo)) {
            try {
                $shift = shiftRequireOpen($pdo, $userId, $companyId, $branchId);
            } catch (RuntimeException $e) {
                return $fail($e->getMessage());
            }
            $shiftId = (int) $shift['shift_id'];
        }

        $waiting = $pdo->prepare(
            "SELECT transaction_id, reference, amount, checkout_url FROM gateway_transactions
             WHERE company_id = ? AND gateway = ? AND user_id = ? AND branch_id = ? AND status = 'pending'
               AND COALESCE(invoice_id, 0) = 0
             ORDER BY transaction_id DESC"
        );
        $waiting->execute([$companyId, $gateway, $userId, $branchId]);
        foreach ($waiting->fetchAll(PDO::FETCH_ASSOC) as $stale) {
            if (abs((float) $stale['amount'] - $amount) > 0.005) {
                gatewayUpdateTransaction($pdo, (int) $stale['transaction_id'], [
                    'status' => 'expired',
                    'failure_reason' => 'Superseded by a new payment session for this cart.',
                ]);
                continue;
            }
            return [
                'ok' => true, 'message' => 'The payment already waiting at this total was reopened.',
                'gateway' => $gateway, 'tender' => $tender, 'transaction_id' => (int) $stale['transaction_id'],
                'reference' => (string) $stale['reference'], 'checkout_url' => (string) $stale['checkout_url'],
                'amount' => $amount, 'reused' => true,
            ];
        }

        $intent = gatewayInitialize($pdo, $companyId, [
            'gateway' => $gateway,
            'amount' => $amount,
            'branch_id' => $branchId,
            'shift_id' => $shiftId,
            'user_id' => $userId,
            'customer_email' => gatewayContactEmail($pdo, $companyId),
            'snapshot' => [
                'lines' => array_map(static function ($item) {
                    return [
                        'product_id' => (int) $item['product_id'],
                        'description' => (string) $item['product_name'],
                        'quantity' => (float) $item['quantity'],
                        'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                    ];
                }, array_values($cart)),
                'customer_id' => getOrCreateWalkInCustomer($pdo, $companyId),
                'method' => $tender,
                'tax_rate' => (float) ($_SESSION['pos_tax_rate'] ?? 0),
                'notes' => 'POS card sale',
            ],
        ]);

        return [
            'ok' => (bool) $intent['ok'],
            'message' => (string) $intent['message'],
            'gateway' => $gateway,
            'tender' => $tender,
            'transaction_id' => (int) $intent['transaction_id'],
            'reference' => (string) $intent['reference'],
            'checkout_url' => (string) $intent['checkout_url'],
            'amount' => $amount,
            'reused' => false,
        ];
    }
}

if (!function_exists('gatewayPosStatus')) {
    /**
     * Where this payment stands, for the till to poll. This reads our own
     * record and never calls the provider - the return from the gateway and
     * the "Ask the provider again" button are the two moments that do, so a
     * cashier watching the screen cannot turn polling into a denial of
     * service against someone else's API.
     *
     * @return array{found:bool,reference:string,status:string,paid:bool,posted:bool,invoice_id:int,invoice_number:string,receipt_url:string,amount:float,currency:string,gateway:string,simulated:bool,message:string}
     */
    function gatewayPosStatus(PDO $pdo, int $companyId, string $reference): array
    {
        $transaction = $reference === '' ? null : gatewayTransactionByReference($pdo, $companyId, $reference);
        if ($transaction === null) {
            return ['found' => false, 'reference' => $reference, 'status' => 'unknown', 'paid' => false, 'posted' => false,
                    'invoice_id' => 0, 'invoice_number' => '', 'receipt_url' => '', 'amount' => 0.0, 'currency' => '',
                    'gateway' => '', 'simulated' => false, 'message' => 'That payment reference is not known to this company.'];
        }

        $invoiceId = (int) ($transaction['invoice_id'] ?? 0);
        $invoiceNumber = '';
        if ($invoiceId > 0) {
            $stmt = $pdo->prepare('SELECT invoice_number FROM sales_invoices WHERE invoice_id = ? AND company_id = ?');
            $stmt->execute([$invoiceId, $companyId]);
            $number = $stmt->fetchColumn();
            $invoiceNumber = $number === false ? '' : (string) $number;
        }

        $paid = in_array((string) $transaction['status'], ['paid', 'partial_refund', 'refunded'], true);
        $messages = [
            'pending' => 'Still waiting for the customer at the gateway.',
            'paid' => $invoiceId > 0 ? 'Paid and recorded.' : 'Paid, but the sale has not been recorded yet - verify it from the payment screen.',
            'failed' => 'The gateway did not take the money.',
            'expired' => 'The payment session ran out before anyone paid.',
            'refunded' => 'Refunded.',
            'partial_refund' => 'Partly refunded.',
        ];

        return [
            'found' => true,
            'reference' => (string) $transaction['reference'],
            'status' => (string) $transaction['status'],
            'paid' => $paid,
            'posted' => $invoiceId > 0,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'receipt_url' => $invoiceId > 0 ? url('/sales?action=receipt&sale_id=' . $invoiceId) : '',
            'amount' => (float) $transaction['amount'],
            'currency' => (string) $transaction['currency'],
            'gateway' => (string) $transaction['gateway'],
            'simulated' => (int) ($transaction['simulates_payment'] ?? 0) === 1,
            'message' => $messages[(string) $transaction['status']] ?? 'The session is ' . $transaction['status'] . '.',
        ];
    }
}
