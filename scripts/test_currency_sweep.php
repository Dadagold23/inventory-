<?php
/**
 * Focused tests for currency display and catalogue-price conversion.
 *
 * Run: php scripts/test_currency_sweep.php
 */

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/helpers.php';

class CurrencySweepTestPDO extends PDO
{
    public function __construct()
    {
    }
}

function getProductRecord(PDO $pdo, int $productId): ?array
{
    return $productId === 7
        ? ['sales_price' => 300000, 'cost_price' => 90000, 'name' => 'Test product']
        : null;
}

require_once APP_ROOT . '/includes/sales_functions.php';

function currencyCheck(string $label, bool $passed): void
{
    if (!$passed) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    echo "PASS $label\n";
}

$pdo = new CurrencySweepTestPDO();
$usdLines = salesNormaliseLines($pdo, [['product_id' => 7, 'quantity' => 2]], 1500);
$ngnLines = salesNormaliseLines($pdo, [['product_id' => 7, 'quantity' => 2]], 1);
$manualLines = salesNormaliseLines($pdo, [['product_id' => 7, 'quantity' => 2, 'unit_price' => 125]], 1500);

currencyCheck('Naira display rounds to whole units', formatPrice(1234.56, null, 'NGN') === '₦1,235');
currencyCheck('Dollar display keeps two decimals', formatPrice(1234.56, null, 'USD') === '$1,234.56');
currencyCheck('foreign catalogue price uses the saved conversion rate', $usdLines[0]['unit_price'] === 200.0);
currencyCheck('home-currency catalogue price remains unchanged', $ngnLines[0]['unit_price'] === 300000.0);
currencyCheck('a manually entered sale-currency price is not converted', $manualLines[0]['unit_price'] === 125.0);
currencyCheck('base amount is recorded in whole Naira', toBaseAmount(12, 1500) === 18000.0);
currencyCheck('missing USD setting uses the documented default', currencyExchangeRate($pdo, 'USD') === 1500.0);

$unsupportedRateFailed = false;
try {
    currencyExchangeRate($pdo, 'EUR');
} catch (RuntimeException $e) {
    $unsupportedRateFailed = true;
}
currencyCheck('unconfigured foreign currency is rejected', $unsupportedRateFailed);
