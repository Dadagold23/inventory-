<?php
/**
 * Module 11: Cash and Bank Management Controller
 * Handles bank accounts, transactions, and cash management.
 * Extends Modules 1-10 authentication and foundation.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('BANK_MODULE') || define('BANK_MODULE', 'bank');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and bank functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/bank_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    $bankWriteAction = match ($postAction) {
        'save_bank' => (int) ($_POST['bank_id'] ?? 0) > 0 ? 'update' : 'create',
        'record_transaction' => 'create',
        'delete_bank' => 'delete',
        default => 'update',
    };
    require_permission('bank', $bankWriteAction);
} else {
    require_permission('bank', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'list';
$bankId = $_GET['bank_id'] ?? 0;
$transactionId = $_GET['transaction_id'] ?? 0;

// Initialize variables
$banks = [];
$bank = [];
$transactions = [];
$cashSummary = [];
$pageTitle = 'Cash Management';
$formAction = '/bank';

// ============================================================
// Bank Listing
// ============================================================

if ($action === 'list' || $action === 'manage_banks') {
    $pageTitle = 'Bank Accounts';
    $banks = getBanksByCompany($pdo, $companyId);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();
        $saveAction = $_POST['action'];
        
        if ($saveAction === 'save_bank') {
            $bankId = (int) ($_POST['bank_id'] ?? 0);
            $name = trim((string) ($_POST['bank_name'] ?? ''));
            $accountNumber = trim((string) ($_POST['account_number'] ?? ''));
            $accountType = trim((string) ($_POST['account_type'] ?? 'checking'));
            $currency = strtoupper(substr(trim((string) ($_POST['currency'] ?? 'USD')), 0, 3));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 0));
            $openingBalance = round((float) ($_POST['opening_balance'] ?? 0), 4);
            
            if ($name === '') {
                $_SESSION['auth_message'] = 'Bank name is required.';
                redirect('/bank?action=list');
            }

            if ($bankId > 0) {
                $existing = getBankById($pdo, $bankId);
                if (!$existing || (int) $existing['company_id'] !== $companyId) {
                    $_SESSION['auth_message'] = 'Bank account not found.';
                    redirect('/bank?action=list');
                }

                updateBank($pdo, $bankId, [
                    'name' => $name,
                    'account_number' => $accountNumber !== '' ? $accountNumber : null,
                    'account_type' => $accountType,
                    'currency' => $currency !== '' ? $currency : 'USD',
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ]);
            } else {
                createBank($pdo, [
                    'company_id' => $companyId,
                    'name' => $name,
                    'account_number' => $accountNumber !== '' ? $accountNumber : null,
                    'account_type' => $accountType,
                    'currency' => $currency !== '' ? $currency : 'USD',
                    'balance' => $openingBalance,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                ]);
            }

            $_SESSION['auth_message'] = 'Bank account ' . ($bankId > 0 ? 'updated' : 'saved') . ' successfully.';
            redirect('/bank?action=list');
        }
        
        if ($saveAction === 'record_transaction') {
            $bankId = $_POST['bank_id'] ?? 0;
            $type = $_POST['transaction_type'] ?? 'deposit';
            $amount = floatval($_POST['amount'] ?? '0.0000');
            $description = $_POST['description'] ?? '';
            $referenceNumber = $_POST['reference_number'] ?? '';
            
            if ($bankId > 0 && $amount > 0) {
                $transactionId = recordBankTransaction($pdo, [
                    'company_id' => currentCompanyId(),
                    'bank_id' => $bankId,
                    'transaction_type' => $type,
                    'transaction_number' => 'BT-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 999), 3, '0', STR_PAD_LEFT),
                    'amount' => $amount,
                    'description' => $description,
                    'reference_number' => $referenceNumber,
                ]);

                $_SESSION['auth_message'] = $transactionId > 0
                    ? 'Bank transaction recorded successfully.'
                    : 'Unable to record the bank transaction.';
            }
            redirect('/bank?action=list');
        }
        
        if ($saveAction === 'delete_bank') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            $existing = $delId > 0 ? getBankById($pdo, $delId) : null;
            if ($existing && (int) $existing['company_id'] === $companyId) {
                updateBank($pdo, $delId, ['is_active' => 0]);
                $_SESSION['auth_message'] = 'Bank account deactivated successfully.';
            } else {
                $_SESSION['auth_message'] = 'Bank account not found.';
            }
            redirect('/bank?action=list');
        }
    }
    
    // If viewing a specific bank, fetch details
    if ($bankId > 0) {
        $candidate = getBankById($pdo, $bankId);
        if ($candidate && (int) $candidate['company_id'] === $companyId) {
            $bank = $candidate;
            $transactions = getBankTransactions($pdo, $bankId);
        } else {
            $bankId = 0;
        }
    }
}

// ============================================================
// Transaction Form
// ============================================================

if ($action === 'transaction') {
    $pageTitle = 'Bank Transaction';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle transaction recording - already handled above
        redirect('/bank?action=list');
    }
}

// ============================================================
// Render Views
// ============================================================

// Get cash summary
$cashSummary = getCashSummary($pdo, $companyId);

// Get banks for form
$banks = getBanksByCompany($pdo, $companyId);

// ============================================================
// Include the appropriate view based on action
// ============================================================

$viewFile = 'views/bank.html';

require_once APP_ROOT . '/views/' . basename($viewFile);