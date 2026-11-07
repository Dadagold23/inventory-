<?php
/**
 * Secret storage at rest.
 *
 * Gateway credentials are encrypted with AES-256-GCM before they reach the
 * database and are never rendered back into a form. The data key comes from
 * APP_KEY in .env; when that is absent the app mints one under storage/keys/
 * (a directory the web server is already told to refuse) so a local XAMPP
 * install works without manual setup while still never storing a credential
 * in the clear.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!function_exists('appSecretKeyPath')) {
    function appSecretKeyPath(): string
    {
        return APP_ROOT . '/storage/keys/app.key';
    }
}

if (!function_exists('appSecretKey')) {
    /**
     * The 32-byte data key, derived once per request.
     *
     * @throws RuntimeException when the configured or stored key is unusable
     */
    function appSecretKey(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }

        $hasKey = function_exists('app_env_has') ? app_env_has('APP_KEY') : (getenv('APP_KEY') !== false);
        $configured = $hasKey ? trim((string) app_env('APP_KEY', '')) : '';
        if ($configured !== '') {
            $raw = appSecretDecodeKey($configured);
            if ($raw === null || strlen($raw) !== 32) {
                throw new RuntimeException('APP_KEY must be 32 random bytes, as 64 hex characters or 44 base64 characters.');
            }
            return $key = $raw;
        }
        if ($hasKey) {
            // Present but empty is a deployment mistake, not a request to mint
            // a key behind the operator's back.
            throw new RuntimeException('APP_KEY is set but empty in .env - gateway credentials cannot be sealed without it.');
        }

        $path = appSecretKeyPath();
        if (!is_file($path)) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Could not create the key directory " . $directory . '.');
            }
            $generated = bin2hex(random_bytes(32));
            if (file_put_contents($path, $generated) === false) {
                throw new RuntimeException("Could not write the secret key to " . $path . '.');
            }
            @chmod($path, 0600);
        }
        $stored = trim((string) file_get_contents($path));
        $raw = appSecretDecodeKey($stored);
        if ($raw === null || strlen($raw) !== 32) {
            throw new RuntimeException('The stored secret key is not a usable 32-byte key.');
        }

        return $key = $raw;
    }
}

if (!function_exists('appSecretDecodeKey')) {
    /** Accept hex, base64 or raw-length material and return 32 bytes, or null. */
    function appSecretDecodeKey(string $value): ?string
    {
        if (preg_match('/^[0-9a-fA-F]{64}$/', $value)) {
            return hex2bin($value);
        }
        if (preg_match('#^[A-Za-z0-9+/=]{43,48}$#', $value)) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }
        return strlen($value) === 32 ? $value : null;
    }
}

if (!function_exists('secretEncrypt')) {
    /**
     * Encrypt a credential for storage. Empty input stays empty so an unset
     * key is distinguishable from an encrypted one.
     */
    function secretEncrypt(?string $plain): ?string
    {
        $plain = (string) $plain;
        if (trim($plain) === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', appSecretKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the credential.');
        }

        return 'v2:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }
}

if (!function_exists('secretDecrypt')) {
    /**
     * Decrypt a stored credential. Values written before this helper existed
     * are returned unchanged, so an existing plaintext key keeps working until
     * it is saved again.
     */
    function secretDecrypt(?string $stored): ?string
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return null;
        }
        if (strncmp($stored, 'v2:', 3) !== 0) {
            return $stored;
        }
        $parts = explode(':', $stored);
        if (count($parts) !== 4) {
            throw new RuntimeException('A stored gateway credential is malformed and must be entered again.');
        }
        [$version, $ivB64, $tagB64, $cipherB64] = $parts;
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $cipher = base64_decode($cipherB64, true);
        if ($iv === false || $tag === false || $cipher === false) {
            throw new RuntimeException('A stored gateway credential is malformed and must be entered again.');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', appSecretKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('A stored gateway credential could not be decrypted — the secret key has changed. Re-enter it on the Payment Gateways screen.');
        }

        return $plain;
    }
}

if (!function_exists('secretIsEncrypted')) {
    function secretIsEncrypted(?string $stored): bool
    {
        return $stored !== null && strncmp($stored, 'v2:', 3) === 0;
    }
}

if (!function_exists('secretHint')) {
    /**
     * The only form a secret may take on screen: the final four characters, so
     * an administrator can tell which key is stored without ever reading it.
     */
    function secretHint(?string $plain): string
    {
        $plain = trim((string) $plain);
        if ($plain === '') {
            return '';
        }

        return str_repeat('•', 6) . substr($plain, -4);
    }
}

if (!function_exists('appIsProduction')) {
    /**
     * True unless the environment says otherwise. Sandbox payment
     * simulation is refused here, so a production host can never post a
     * sale that no real gateway approved.
     */
    function appIsProduction(): bool
    {
        $env = strtolower(trim((string) (function_exists('app_env') ? app_env('APP_ENV', 'production') : getenv('APP_ENV'))));
        return $env === '' || $env === 'production' || $env === 'prod';
    }
}
