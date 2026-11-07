<?php
/**
 * Module 12: General Ledger Controller
 * Chart of accounts, journal entries and trial balance.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('LEDGER_MODULE') || define('LEDGER_MODULE', 'ledger');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/ledger_functions.php';

auth_require();

$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $ledgerWriteAction = match ($postAction) {
        'create_account', 'create_entry' => 'create',
        'post_entry' => 'update',
        default => 'update',
    };
    require_permission('ledger', $ledgerWriteAction);
} else {
    require_permission('ledger', 'view');
}

$view = (string) ($_GET['view'] ?? 'entries');
if (!in_array($view, ['entries', 'accounts', 'trial'], true)) {
    $view = 'entries';
}

$entryId = (int) ($_GET['entry_id'] ?? 0);
$pageTitle = 'General Ledger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'create_account') {
        $type = (string) ($_POST['account_type'] ?? 'asset');
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parent = getAccountById($pdo, $parentId);
            if (!$parent || (int) $parent['company_id'] !== $companyId) {
                $parentId = 0;
            }
        }

        $accountId = createAccount($pdo, [
            'company_id' => $companyId,
            'account_code' => trim((string) ($_POST['account_code'] ?? '')),
            'account_name' => trim((string) ($_POST['account_name'] ?? '')),
            'account_type' => $type,
            'parent_id' => $parentId,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ]);

        $_SESSION['auth_message'] = $accountId > 0
            ? 'Account created successfully.'
            : 'Unable to create the account. Check the code, name and type.';
        redirect('/ledger?view=accounts');
    }

    if ($postAction === 'create_entry') {
        $accountIds = $_POST['line_account_id'] ?? [];
        $debits = $_POST['line_debit'] ?? [];
        $credits = $_POST['line_credit'] ?? [];
        $descriptions = $_POST['line_description'] ?? [];

        $lines = [];
        foreach ((array) $accountIds as $index => $accountId) {
            $lines[] = [
                'account_id' => (int) $accountId,
                'debit' => moneyInput($debits[$index] ?? 0),
                'credit' => moneyInput($credits[$index] ?? 0),
                'description' => trim((string) ($descriptions[$index] ?? '')),
            ];
        }

        $entryId = createJournalEntry($pdo, [
            'company_id' => $companyId,
            'entry_date' => (string) ($_POST['entry_date'] ?? date('Y-m-d')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'reference_number' => trim((string) ($_POST['reference_number'] ?? '')),
            'reference_type' => 'journal',
            'created_by' => $_SESSION['user_id'] ?? null,
            'lines' => $lines,
        ]);

        $_SESSION['auth_message'] = $entryId > 0
            ? 'Journal entry created successfully.'
            : 'The entry could not be created. Debits must equal credits and every line needs an account.';
        redirect('/ledger?view=entries' . ($entryId > 0 ? '&entry_id=' . $entryId : ''));
    }

    if ($postAction === 'post_entry') {
        $targetId = (int) ($_POST['entry_id'] ?? 0);
        $entry = $targetId > 0 ? getJournalEntryById($pdo, $targetId) : null;

        if ($entry && (int) $entry['company_id'] === $companyId) {
            $_SESSION['auth_message'] = postJournalEntry($pdo, $targetId)
                ? 'Journal entry posted successfully.'
                : 'The entry is not balanced and cannot be posted.';
        } else {
            $_SESSION['auth_message'] = 'Journal entry not found.';
        }

        redirect('/ledger?view=entries');
    }
}

$accounts = getChartOfAccounts($pdo, $companyId);
$activeAccounts = array_values(array_filter($accounts, static fn(array $row): bool => (int) $row['is_active'] === 1));
$entries = getJournalEntries($pdo, $companyId);
$trialBalance = getTrialBalance($pdo, $companyId);

$entry = [];
$entryLines = [];
if ($entryId > 0) {
    $entry = getJournalEntryById($pdo, $entryId) ?? [];
    if ($entry !== [] && (int) $entry['company_id'] !== $companyId) {
        $entry = [];
        $entryId = 0;
    } else {
        $entryLines = getJournalEntryLines($pdo, $entryId);
    }
}

$totalDebit = array_sum(array_map(static fn(array $row): float => (float) $row['debit_total'], $trialBalance));
$totalCredit = array_sum(array_map(static fn(array $row): float => (float) $row['credit_total'], $trialBalance));
$postedCount = count(array_filter($entries, static fn(array $row): bool => $row['status'] === 'posted'));
$draftCount = count($entries) - $postedCount;

if ($view === 'accounts') {
    $pageTitle = 'Chart of Accounts';
} elseif ($view === 'trial') {
    $pageTitle = 'Trial Balance';
} else {
    $pageTitle = 'Journal Entries';
}

$companyCurrency = getCompanyCurrency($pdo);

require APP_ROOT . '/views/ledger.html';
