<?php
/**
 * Module 11: Bank Functions
 * Handles bank accounts, transactions, and cash management.
 * Extends Modules 1-10 foundation.
 */

// Prevent direct access
defined('BANK_MODULE') || define('BANK_MODULE', 'bank');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

// ============================================================
// Bank Functions
// ============================================================

/**
 * Get all banks for a company
 */
function getBanksByCompany(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT b.*, (SELECT SUM(amount) FROM bank_transactions WHERE bank_id = b.bank_id AND transaction_type = 'deposit') as total_deposits,
               (SELECT SUM(amount) FROM bank_transactions WHERE bank_id = b.bank_id AND transaction_type = 'withdrawal') as total_withdrawals
        FROM banks b
        WHERE b.company_id = ?
        ORDER BY b.sort_order, b.name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific bank
 */
function getBankById(PDO $pdo, int $bankId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM banks WHERE bank_id = ?");
    $stmt->execute([$bankId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create bank
 */
function createBank(PDO $pdo, array $bankData): int {
    $stmt = $pdo->prepare("
        INSERT INTO banks (company_id, name, account_number, account_type, currency, balance, is_active, sort_order, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $params = [
        $bankData['company_id'] ?? 1,
        $bankData['name'] ?? '',
        $bankData['account_number'] ?? '',
        $bankData['account_type'] ?? 'checking',
        $bankData['currency'] ?? 'USD',
        $bankData['balance'] ?? 0.0000,
        $bankData['is_active'] ?? 1,
        $bankData['sort_order'] ?? 0,
    ];
    
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

/**
 * Update bank. Only supplied fields change so status toggles never blank a record.
 */
function updateBank(PDO $pdo, int $bankId, array $bankData): bool {
    $allowed = ['name', 'account_number', 'account_type', 'currency', 'balance', 'is_active', 'sort_order'];
    $sets = [];
    $params = [];

    foreach ($allowed as $column) {
        if (array_key_exists($column, $bankData)) {
            $sets[] = $column . ' = ?';
            $params[] = $bankData[$column];
        }
    }

    if ($sets === []) {
        return true;
    }

    $params[] = $bankId;
    $stmt = $pdo->prepare(
        'UPDATE banks SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE bank_id = ?'
    );
    return $stmt->execute($params);
}

/**
 * Record bank transaction and update the account balance atomically.
 */
function recordBankTransaction(PDO $pdo, array $transactionData): int {
    $companyId = (int) ($transactionData['company_id'] ?? 1);
    $bankId = (int) ($transactionData['bank_id'] ?? 0);
    $amount = (float) ($transactionData['amount'] ?? 0);
    $type = (string) ($transactionData['transaction_type'] ?? 'deposit');

    if ($bankId <= 0 || $amount <= 0 || !in_array($type, ['deposit', 'withdrawal', 'transfer', 'fee', 'interest'], true)) {
        return 0;
    }

    try {
        $pdo->beginTransaction();

        $lock = $pdo->prepare('SELECT balance FROM banks WHERE bank_id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([$bankId, $companyId]);
        $currentBalance = $lock->fetchColumn();
        if ($currentBalance === false) {
            $pdo->rollBack();
            return 0;
        }

        $delta = in_array($type, ['deposit', 'interest'], true) ? $amount : -$amount;
        $newBalance = round((float) $currentBalance + $delta, 4);

        $stmt = $pdo->prepare("
            INSERT INTO bank_transactions (company_id, bank_id, transaction_type, transaction_number, transaction_date, description, amount, balance_after, reference_number, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $bankId,
            $type,
            $transactionData['transaction_number'] ?? 'BT-' . date('YmdHis') . '-' . mt_rand(100, 999),
            $transactionData['transaction_date'] ?? date('Y-m-d'),
            $transactionData['description'] ?? '',
            $amount,
            $newBalance,
            $transactionData['reference_number'] ?? '',
            $transactionData['status'] ?? 'completed',
            (int) ($_SESSION['user_id'] ?? 1),
        ]);
        $newTxnId = (int) $pdo->lastInsertId();

        $update = $pdo->prepare('UPDATE banks SET balance = ?, updated_at = NOW() WHERE bank_id = ? AND company_id = ?');
        $update->execute([$newBalance, $bankId, $companyId]);

        $pdo->commit();
        return $newTxnId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        app_log('error', 'Bank transaction failed: ' . $e->getMessage());
        return 0;
    }
}

// ============================================================
// Cash Summary Functions
// ============================================================

/**
 * Get cash summary by bank
 */
function getCashSummary(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare("
        SELECT b.bank_id, b.name, b.balance, b.currency,
               (SELECT SUM(amount) FROM bank_transactions WHERE bank_id = b.bank_id AND transaction_type = 'deposit') as total_deposits,
               (SELECT SUM(amount) FROM bank_transactions WHERE bank_id = b.bank_id AND transaction_type = 'withdrawal') as total_withdrawals
        FROM banks b
        WHERE b.company_id = ?
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get bank transaction history with optional bank and type filters.
 */
function getBankTransactions(PDO $pdo, ?int $bankId = null, ?string $type = null): array {
    $where = [];
    $params = [];

    if ($bankId) {
        $where[] = 'bt.bank_id = ?';
        $params[] = $bankId;
    }
    if ($type) {
        $where[] = 'bt.transaction_type = ?';
        $params[] = $type;
    }

    $sql = "
        SELECT bt.*, b.name AS bank_name
        FROM bank_transactions bt
        LEFT JOIN banks b ON bt.bank_id = b.bank_id
    ";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY bt.transaction_date DESC, bt.transaction_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}