<?php
/** 
 * Module 19: System Settings Functions 
 * Handles system configuration and preferences. 
 * Extends Modules 1-18 foundation. 
 */ 

// Prevent direct access
defined('SYSTEM_SETTINGS_MODULE') || define('SYSTEM_SETTINGS_MODULE', 'system_settings');

// Require foundation
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/config/database.php';

// ============================================================
// System Settings Functions
// ============================================================

/** 
 * Get all system settings by company 
 */ 
function getSystemSettingsByCompany(PDO $pdo, int $companyId = 1): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM system_settings WHERE company_id = ? ORDER BY `key`"
    );
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 
 * Get a specific system setting 
 */ 
function getSystemSettingByKey(PDO $pdo, string $key, int $companyId = 1): ?array {
    $stmt = $pdo->prepare("SELECT * FROM system_settings WHERE `key` = ? AND company_id = ?");
    $stmt->execute([$key, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 
 * Create or update system setting 
 */ 
function setSystemSetting(PDO $pdo, array $settingData): bool {
    $key = trim((string) ($settingData['key'] ?? ''));
    $value = (string) ($settingData['value'] ?? '');
    $valueType = (string) ($settingData['value_type'] ?? 'string');
    $description = (string) ($settingData['description'] ?? '');
    $isActive = !empty($settingData['is_active']) ? 1 : 0;
    $settingId = (int) ($settingData['setting_id'] ?? 0);
    $companyId = (int) ($settingData['company_id'] ?? 1);

    if ($key === '') {
        return false;
    }
    if (!in_array($valueType, ['string', 'number', 'boolean', 'json'], true)) {
        $valueType = 'string';
    }

    $existing = getSystemSettingByKey($pdo, $key, $companyId);
    $targetId = $settingId > 0 ? $settingId : (int) ($existing['setting_id'] ?? 0);

    if ($targetId > 0) {
        $stmt = $pdo->prepare(
            "UPDATE system_settings SET `key` = ?, value = ?, value_type = ?, description = ?, is_active = ?, updated_at = NOW()
             WHERE setting_id = ? AND company_id = ?"
        );
        return $stmt->execute([$key, $value, $valueType, $description, $isActive, $targetId, $companyId]);
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO system_settings (company_id, `key`, value, value_type, description, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        return $stmt->execute([$companyId, $key, $value, $valueType, $description, $isActive]);
    } catch (PDOException $e) {
        return false;
    }
}

/** 
 * Delete system setting 
 */ 
function deleteSystemSetting(PDO $pdo, string $key, int $companyId = 1): bool {
    $stmt = $pdo->prepare("DELETE FROM system_settings WHERE `key` = ? AND company_id = ?");
    return $stmt->execute([$key, $companyId]);
}

/** 
 * Get system setting value with proper type casting 
 */ 
function getSystemSettingValue(PDO $pdo, string $key, mixed $default = null): mixed {
    $setting = getSystemSettingByKey($pdo, $key);
    if (!$setting) {
        return $default;
    }

    switch ($setting['value_type']) {
        case 'boolean':
            return $setting['value'] === '1' || $setting['value'] === true;
        case 'number':
            return floatval($setting['value']);
        case 'json':
            return json_decode($setting['value'], true);
        case 'string':
        default:
            return $setting['value'];
    }
}