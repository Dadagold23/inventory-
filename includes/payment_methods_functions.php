<?php
/**
 * Module 21: Payment Method Functions
 * Configurable payment methods per company (payment_methods table, migration 008).
 *
 * Rules:
 *  - Methods are never hard-deleted: historical payments reference the code,
 *    so deactivation (is_active = 0) is the only "removal".
 *  - Codes are validated against ^[a-z][a-z0-9_]{1,49}$ and must be unique
 *    per company.
 */

defined('PAYMENT_METHODS_MODULE') || define('PAYMENT_METHODS_MODULE', 'payment_methods');

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

if (!function_exists('paymentMethodsCodeIsValid')) {
    /**
     * Code format check: lowercase letter, then lowercase letters/digits/underscore.
     */
    function paymentMethodsCodeIsValid(string $code): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{1,49}$/', $code) === 1;
    }
}

if (!function_exists('getPaymentMethods')) {
    /**
     * All payment methods for a company (active and inactive by default).
     */
    function getPaymentMethods(PDO $pdo, int $companyId, bool $activeOnly = false): array
    {
        $sql = 'SELECT payment_method_id, company_id, code, name, description,
                       is_cash, is_online, gateway, is_active, sort_order, created_at, updated_at
                  FROM payment_methods
                 WHERE company_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name, code';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$companyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            app_log('warning', 'Payment method listing failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getPaymentMethodById')) {
    /**
     * One payment method, scoped to the company so other companies are invisible.
     */
    function getPaymentMethodById(PDO $pdo, int $paymentMethodId, int $companyId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT payment_method_id, company_id, code, name, description,
                        is_cash, is_online, gateway, is_active, sort_order, created_at, updated_at
                   FROM payment_methods
                  WHERE payment_method_id = ? AND company_id = ?'
            );
            $stmt->execute([$paymentMethodId, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            app_log('warning', 'Payment method lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('savePaymentMethod')) {
    /**
     * Create or update a payment method. Returns ['success' => bool, 'message' => string].
     */
    function savePaymentMethod(PDO $pdo, int $companyId, array $data): array
    {
        $methodId = (int) ($data['payment_method_id'] ?? 0);
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $isCash = !empty($data['is_cash']) ? 1 : 0;
        $isOnline = !empty($data['is_online']) ? 1 : 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $sortOrder = max(0, min(9999, (int) ($data['sort_order'] ?? 0)));
        // The provider link is only meaningful for an online tender, and only
        // when the gateway module is loaded; callers that know nothing about it
        // simply omit the key, and an omitted key must not clear a stored link.
        $hasGateway = array_key_exists('gateway', $data);
        $gateway = trim((string) ($data['gateway'] ?? ''));
        if ($gateway === '') {
            $gateway = null;
        } elseif ($isOnline !== 1) {
            return ['success' => false, 'message' => 'Only an online payment method can be linked to a payment gateway.'];
        } elseif (function_exists('gatewayIsKnownProvider') && !gatewayIsKnownProvider($gateway)) {
            return ['success' => false, 'message' => 'Unknown payment gateway "' . $gateway . '".'];
        } elseif (!function_exists('gatewayIsKnownProvider')) {
            return ['success' => false, 'message' => 'Payment gateway support is not loaded, so no gateway can be linked.'];
        }

        if ($code === '' || !paymentMethodsCodeIsValid($code)) {
            return [
                'success' => false,
                'message' => 'Code is required and must start with a lowercase letter, using only lowercase letters, numbers and underscores (2-50 characters).',
            ];
        }
        if ($name === '') {
            return ['success' => false, 'message' => 'Payment method name is required.'];
        }
        if (mb_strlen($name) > 80) {
            return ['success' => false, 'message' => 'Payment method name must be 80 characters or fewer.'];
        }

        try {
            // Enforce uniqueness per company (mirrors the DB unique key, with a
            // friendly message; the unique index still guards against races).
            $sql = 'SELECT payment_method_id FROM payment_methods WHERE company_id = ? AND code = ?';
            $args = [$companyId, $code];
            if ($methodId > 0) {
                $sql .= ' AND payment_method_id <> ?';
                $args[] = $methodId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            if ($stmt->fetchColumn() !== false) {
                return ['success' => false, 'message' => 'A payment method with code "' . $code . '" already exists.'];
            }

            if ($methodId > 0) {
                if (getPaymentMethodById($pdo, $methodId, $companyId) === null) {
                    return ['success' => false, 'message' => 'Payment method not found.'];
                }
                $gatewaySql = $hasGateway ? ', gateway = ?' : '';
                $stmt = $pdo->prepare('
                    UPDATE payment_methods
                       SET code = ?, name = ?, description = ?, is_cash = ?, is_online = ?,
                           is_active = ?, sort_order = ?' . $gatewaySql . ',
                           updated_at = NOW()
                      WHERE payment_method_id = ? AND company_id = ?
                ');
                $args = [$code, $name, $description, $isCash, $isOnline, $isActive, $sortOrder];
                if ($hasGateway) {
                    $args[] = $gateway;
                }
                $args[] = $methodId;
                $args[] = $companyId;
                $stmt->execute($args);
                return ['success' => true, 'message' => 'Payment method updated successfully.'];
            }

            $stmt = $pdo->prepare('
                INSERT INTO payment_methods (company_id, code, name, description, is_cash, is_online, gateway, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$companyId, $code, $name, $description, $isCash, $isOnline, $gateway, $isActive, $sortOrder]);
            return ['success' => true, 'message' => 'Payment method saved successfully.'];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['success' => false, 'message' => 'A payment method with code "' . $code . '" already exists.'];
            }
            app_log('warning', 'Payment method save failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save the payment method. Please try again.'];
        }
    }
}

if (!function_exists('setPaymentMethodActive')) {
    /**
     * Soft toggle of is_active (never deletes; historical payments reference codes).
     */
    function setPaymentMethodActive(PDO $pdo, int $paymentMethodId, int $companyId, int $isActive): ?array
    {
        $method = getPaymentMethodById($pdo, $paymentMethodId, $companyId);
        if ($method === null) {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE payment_methods SET is_active = ?, updated_at = NOW()
                 WHERE payment_method_id = ? AND company_id = ?'
            );
            $stmt->execute([$isActive ? 1 : 0, $paymentMethodId, $companyId]);
            $method['is_active'] = $isActive ? 1 : 0;
            return $method;
        } catch (PDOException $e) {
            app_log('warning', 'Payment method toggle failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getActivePaymentMethodCodes')) {
    /**
     * Active codes for a company, for validation and dropdowns.
     * Returns [] when the table is missing or empty so callers can fall back
     * to the legacy hardcoded list.
     */
    function getActivePaymentMethodCodes(PDO $pdo, int $companyId): array
    {
        $codes = [];
        foreach (getPaymentMethods($pdo, $companyId, true) as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return $codes;
    }
}

if (!function_exists('getActivePaymentMethodNames')) {
    /**
     * Active methods as an ordered code => name map (dropdown source).
     */
    function getActivePaymentMethodNames(PDO $pdo, int $companyId): array
    {
        $options = [];
        foreach (getPaymentMethods($pdo, $companyId, true) as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $options[$code] = (string) ($row['name'] ?? $code);
        }
        return $options;
    }
}
