<?php
/**
 * Module 12: General Ledger Functions
 * Double-entry journal entries backed by journal_entries,
 * journal_entry_lines and chart_of_accounts.
 */

defined('LEDGER_MODULE') || define('LEDGER_MODULE', 'ledger');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/config/database.php';

// ============================================================
// Chart of accounts
// ============================================================

function getChartOfAccounts(PDO $pdo, int $companyId = 1, bool $activeOnly = false): array
{
    $cond = $activeOnly ? 'AND a.is_active = 1' : '';
    $stmt = $pdo->prepare("
        SELECT a.*, p.account_name AS parent_name
        FROM chart_of_accounts a
        LEFT JOIN chart_of_accounts p ON a.parent_id = p.account_id
        WHERE a.company_id = ? $cond
        ORDER BY a.account_type, a.sort_order, a.account_code
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAccountById(PDO $pdo, int $accountId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM chart_of_accounts WHERE account_id = ?');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function createAccount(PDO $pdo, array $accountData): int
{
    $code = trim((string) ($accountData['account_code'] ?? ''));
    $name = trim((string) ($accountData['account_name'] ?? ''));
    $type = (string) ($accountData['account_type'] ?? '');

    if ($code === '' || $name === '' || !in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO chart_of_accounts
                (company_id, account_code, account_name, account_type, parent_id, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) ($accountData['company_id'] ?? 1),
            $code,
            $name,
            $type,
            !empty($accountData['parent_id']) ? (int) $accountData['parent_id'] : null,
            array_key_exists('is_active', $accountData) ? (int) (bool) $accountData['is_active'] : 1,
            (int) ($accountData['sort_order'] ?? 0),
        ]);
    } catch (PDOException $e) {
        return 0;
    }

    return (int) $pdo->lastInsertId();
}

function updateAccount(PDO $pdo, int $accountId, array $accountData): bool
{
    $allowed = ['account_code', 'account_name', 'account_type', 'parent_id', 'is_active', 'sort_order'];
    $sets = [];
    $params = [];
    foreach ($allowed as $column) {
        if (array_key_exists($column, $accountData)) {
            $sets[] = $column . ' = ?';
            $params[] = $accountData[$column];
        }
    }
    if ($sets === []) {
        return true;
    }

    $params[] = $accountId;
    $stmt = $pdo->prepare('UPDATE chart_of_accounts SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE account_id = ?');
    return $stmt->execute($params);
}

// ============================================================
// Journal entries
// ============================================================

function getJournalEntries(PDO $pdo, int $companyId = 1, ?string $status = null): array
{
    $cond = $status ? 'AND je.status = ?' : '';
    $params = $status ? [$status] : [];

    $stmt = $pdo->prepare("
        SELECT je.*, u.full_name AS created_by_name,
               (SELECT COALESCE(SUM(jl.debit_amount), 0) FROM journal_entry_lines jl
                 WHERE jl.entry_id = je.entry_id) AS total_debit,
               (SELECT COALESCE(SUM(jl.credit_amount), 0) FROM journal_entry_lines jl
                 WHERE jl.entry_id = je.entry_id) AS total_credit,
               (SELECT COUNT(*) FROM journal_entry_lines jl
                 WHERE jl.entry_id = je.entry_id) AS line_count
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.user_id
        WHERE je.company_id = ? $cond
        ORDER BY je.entry_date DESC, je.entry_id DESC
    ");
    array_unshift($params, $companyId);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getJournalEntryById(PDO $pdo, int $entryId): ?array
{
    $stmt = $pdo->prepare("
        SELECT je.*, u.full_name AS created_by_name,
               (SELECT COALESCE(SUM(jl.debit_amount), 0) FROM journal_entry_lines jl
                 WHERE jl.entry_id = je.entry_id) AS total_debit,
               (SELECT COALESCE(SUM(jl.credit_amount), 0) FROM journal_entry_lines jl
                 WHERE jl.entry_id = je.entry_id) AS total_credit
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.user_id
        WHERE je.entry_id = ?
    ");
    $stmt->execute([$entryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getJournalEntryLines(PDO $pdo, int $entryId): array
{
    $stmt = $pdo->prepare("
        SELECT jl.*, a.account_code, a.account_name, a.account_type
        FROM journal_entry_lines jl
        LEFT JOIN chart_of_accounts a ON jl.account_id = a.account_id
        WHERE jl.entry_id = ?
        ORDER BY jl.sort_order, jl.line_id
    ");
    $stmt->execute([$entryId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a balanced journal entry. Returns the entry id, or 0 when invalid.
 *
 * Lines accept account_id, debit/debit_amount, credit/credit_amount,
 * description and sort_order keys.
 */
function createJournalEntry(PDO $pdo, array $entryData): int
{
    $companyId = (int) ($entryData['company_id'] ?? 1);
    $lines = $entryData['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2) {
        return 0;
    }

    $normalized = [];
    $debitTotal = 0.0;
    $creditTotal = 0.0;
    $sort = 0;

    foreach ($lines as $line) {
        $accountId = (int) ($line['account_id'] ?? 0);
        $debit = round((float) ($line['debit'] ?? $line['debit_amount'] ?? 0), 4);
        $credit = round((float) ($line['credit'] ?? $line['credit_amount'] ?? 0), 4);
        $description = trim((string) ($line['description'] ?? ''));

        if ($accountId <= 0 || $debit < 0 || $credit < 0 || ($debit <= 0 && $credit <= 0)) {
            continue;
        }

        $account = getAccountById($pdo, $accountId);
        if (!$account || (int) $account['company_id'] !== $companyId) {
            return 0;
        }

        $normalized[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'sort_order' => $sort++,
        ];
        $debitTotal += $debit;
        $creditTotal += $credit;
    }

    if (count($normalized) < 2 || $debitTotal <= 0 || abs($debitTotal - $creditTotal) > 0.0001) {
        return 0;
    }

    $entryNumber = trim((string) ($entryData['entry_number'] ?? ''));
    if ($entryNumber === '') {
        $entryNumber = 'JE-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    $posted = !empty($entryData['posted']);
    $status = $posted ? 'posted' : 'draft';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO journal_entries
                (company_id, entry_number, entry_date, description, reference_number,
                 reference_type, status, posted_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $entryNumber,
            $entryData['entry_date'] ?? date('Y-m-d'),
            trim((string) ($entryData['description'] ?? '')),
            $entryData['reference_number'] ?? null,
            $entryData['reference_type'] ?? 'journal',
            $status,
            $posted ? date('Y-m-d H:i:s') : null,
            (int) ($entryData['created_by'] ?? ($_SESSION['user_id'] ?? 0)),
        ]);
        $entryId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO journal_entry_lines
                (entry_id, account_id, debit_amount, credit_amount, description, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($normalized as $line) {
            $lineStmt->execute([
                $entryId,
                $line['account_id'],
                $line['debit'],
                $line['credit'],
                $line['description'] !== '' ? $line['description'] : null,
                $line['sort_order'],
            ]);
        }

        $pdo->commit();
        return $entryId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Post a balanced draft entry.
 */
function postJournalEntry(PDO $pdo, int $entryId): bool
{
    $entry = getJournalEntryById($pdo, $entryId);
    if (!$entry) {
        return false;
    }

    if (abs((float) $entry['total_debit'] - (float) $entry['total_credit']) > 0.0001
        || (float) $entry['total_debit'] <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE journal_entries SET status = 'posted', posted_date = NOW(), updated_at = NOW() WHERE entry_id = ?"
    );
    return $stmt->execute([$entryId]);
}

/**
 * Trial balance from posted entries.
 */
function getTrialBalance(PDO $pdo, int $companyId = 1): array
{
    $stmt = $pdo->prepare("
        SELECT a.account_id, a.account_code, a.account_name, a.account_type,
               (SELECT COALESCE(SUM(jl.debit_amount), 0)
                  FROM journal_entry_lines jl
                  INNER JOIN journal_entries je ON je.entry_id = jl.entry_id
                 WHERE jl.account_id = a.account_id AND je.company_id = ? AND je.status = 'posted') AS debit_total,
               (SELECT COALESCE(SUM(jl.credit_amount), 0)
                  FROM journal_entry_lines jl
                  INNER JOIN journal_entries je ON je.entry_id = jl.entry_id
                 WHERE jl.account_id = a.account_id AND je.company_id = ? AND je.status = 'posted') AS credit_total
        FROM chart_of_accounts a
        WHERE a.company_id = ?
        ORDER BY a.account_type, a.account_code
    ");
    $stmt->execute([$companyId, $companyId, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
