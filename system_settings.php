<?php
/** 
 * Module 19: System Settings Controller 
 * Handles system configuration and preferences. 
 * Extends Modules 1-18 authentication and foundation. 
 */ 

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('SYSTEM_SETTINGS_MODULE') || define('SYSTEM_SETTINGS_MODULE', 'system_settings');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and system settings functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/system_settings_functions.php';
require_once APP_ROOT . '/includes/receipt_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('settings', !empty($_POST['delete_key']) ? 'delete' : 'update');
} else {
    require_permission('settings', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'list';
$settingId = $_GET['setting_id'] ?? 0;

// Initialize variables
$settings = [];
$pageTitle = 'System Settings';
$formAction = '/system_settings';

// ============================================================
// System Settings Listing
// ============================================================

if ($action === 'list' || $action === 'manage_settings') {
    $pageTitle = 'System Settings';
    $settings = getSystemSettingsByCompany($pdo, $companyId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();

        if (($_POST['action'] ?? '') === 'save_app_settings') {
            $result = saveAppSettings($pdo, $companyId, (array) ($_POST['app_settings'] ?? []));
            $_SESSION['auth_message'] = $result['message'];
            redirect('/system_settings?action=list');
        }

        if (($_POST['action'] ?? '') === 'save_receipt_logo') {
            $result = receiptSaveLogo($pdo, $companyId, (array) ($_FILES['receipt_logo'] ?? []));
            $_SESSION['auth_message'] = $result['message'];
            redirect('/system_settings?action=list');
        }

        if (($_POST['action'] ?? '') === 'remove_receipt_logo') {
            $_SESSION['auth_message'] = receiptDeleteLogo($pdo, $companyId)['message'];
            redirect('/system_settings?action=list');
        }

        if (!empty($_POST['delete_key'])) {
            $deleteKey = trim((string) $_POST['delete_key']);
            if ($deleteKey !== '') {
                deleteSystemSetting($pdo, $deleteKey, $companyId);
                $_SESSION['auth_message'] = 'System setting deleted successfully.';
            }
            redirect('/system_settings?action=list');
        }

        if (($_POST['action'] ?? '') === 'save_setting') {
            $ids = (array) ($_POST['setting_id'] ?? []);
            $keys = (array) ($_POST['setting_key'] ?? []);
            $values = (array) ($_POST['setting_value'] ?? []);
            $types = (array) ($_POST['value_type'] ?? []);
            $descriptions = (array) ($_POST['setting_description'] ?? []);
            $activeMap = (array) ($_POST['is_active'] ?? []);

            foreach ($keys as $index => $key) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $rowId = (int) ($ids[$index] ?? 0);
                setSystemSetting($pdo, [
                    'company_id' => $companyId,
                    'setting_id' => $rowId,
                    'key' => $key,
                    'value' => (string) ($values[$index] ?? ''),
                    'value_type' => (string) ($types[$index] ?? 'string'),
                    'description' => (string) ($descriptions[$index] ?? ''),
                    'is_active' => isset($activeMap[$rowId]) ? 1 : 0,
                ]);
            }

            $newKey = trim((string) ($_POST['new_key'] ?? ''));
            if ($newKey !== '') {
                setSystemSetting($pdo, [
                    'company_id' => $companyId,
                    'key' => $newKey,
                    'value' => (string) ($_POST['new_value'] ?? ''),
                    'value_type' => (string) ($_POST['new_value_type'] ?? 'string'),
                    'description' => (string) ($_POST['new_description'] ?? ''),
                    'is_active' => 1,
                ]);
            }

            $_SESSION['auth_message'] = 'System settings saved successfully.';
            redirect('/system_settings?action=list');
        }
    }
}

// ============================================================
// Render Views
// ============================================================

// Get all settings for display
$settings = getSystemSettingsByCompany($pdo, $companyId);
$appSettings = listAppSettings($pdo);
$appSettingDefs = appSettingDefinitions($pdo);

// ============================================================
// Include the appropriate view based on action
// ============================================================

$viewFile = 'views/system_settings.html';

require_once APP_ROOT . '/views/' . basename($viewFile);