<?php
/**
 * Module 5: Sales and Point of Sale Controller
 * Handles customers, quotes, sales invoices, payments and POS.
 * Extends Modules 1-4 authentication and inventory foundation.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);
defined('SALES_MODULE') || define('SALES_MODULE', 'sales');
defined('AUTH_MODULE') || define('AUTH_MODULE', 'auth');

// Require foundation, auth, and sales functions
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/auth_helpers.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/inventory_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';

// Require authentication
auth_require();

// Initialize PDO
$pdo = db();
$companyId = currentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    [$salesResource, $salesWriteAction] = match ($postAction) {
        'save_customer' => ['receivables', (int) ($_POST['customer_id'] ?? 0) > 0 ? 'update' : 'create'],
        'delete_customer' => ['receivables', 'delete'],
        'save_quote' => ['sales', (int) ($_POST['quote_id'] ?? 0) > 0 ? 'update' : 'create'],
        'convert_quote', 'save_sale' => ['sales', 'create'],
        'delete_quote', 'cancel_sale' => ['sales', 'delete'],
        'record_payment' => ['payments', 'create'],
        default => ['sales', 'create'],
    };
    require_permission($salesResource, $salesWriteAction);
} else {
    require_permission('sales', 'view');
}

// Handle routing based on action
$action = $_GET['action'] ?? 'sales';
$quoteId = (int) ($_GET['quote_id'] ?? 0);
$saleId = (int) ($_GET['sale_id'] ?? 0);

// Initialize variables
$customers = [];
$quotes = [];
$sales = [];
$customer = [];
$quote = [];
$sale = [];
$posCart = [];
$pageTitle = 'Sales Management';
$formAction = '/sales';

/**
 * Collect posted document lines from parallel arrays.
 */
function collectSalesLines(): array {
    $productIds = $_POST['line_product_id'] ?? [];
    $quantities = $_POST['line_quantity'] ?? [];
    $prices = $_POST['line_unit_price'] ?? [];
    $discounts = $_POST['line_discount'] ?? [];
    $descriptions = $_POST['line_description'] ?? [];

    $lines = [];
    foreach ($productIds as $index => $productId) {
        $quantity = (float) ($quantities[$index] ?? 0);
        if ((int) $productId <= 0 || $quantity <= 0) {
            continue;
        }
        $lines[] = [
            'product_id' => (int) $productId,
            'description' => trim((string) ($descriptions[$index] ?? '')),
            'quantity' => $quantity,
            'unit_price' => ($prices[$index] ?? '') === '' ? null : (float) $prices[$index],
            'discount_percent' => (float) ($discounts[$index] ?? 0),
        ];
    }

    return $lines;
}

// ============================================================
// Customer Actions
// ============================================================

if ($action === 'customers') {
    $pageTitle = 'Customers';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_customer') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $name = trim((string) ($_POST['customer_name'] ?? ''));

            if ($name === '') {
                $_SESSION['auth_message'] = 'Customer name is required.';
                redirect('/sales?action=customers');
            }

            $payload = [
                'company_id' => $companyId,
                'name' => $name,
                'email' => trim((string) ($_POST['customer_email'] ?? '')),
                'phone' => trim((string) ($_POST['customer_phone'] ?? '')),
                'tax_id' => trim((string) ($_POST['customer_tax_id'] ?? '')),
                'address' => trim((string) ($_POST['customer_address'] ?? '')),
                'city' => trim((string) ($_POST['customer_city'] ?? '')),
                'state' => trim((string) ($_POST['customer_state'] ?? '')),
                'postal_code' => trim((string) ($_POST['customer_postal'] ?? '')),
                'country' => trim((string) ($_POST['customer_country'] ?? '')),
                'credit_limit' => (float) ($_POST['customer_credit_limit'] ?? 0),
                'payment_terms' => trim((string) ($_POST['customer_payment_terms'] ?? 'Net 30')),
                'is_active' => isset($_POST['customer_active']) ? 1 : 0,
                'is_tax_exempt' => isset($_POST['customer_tax_exempt']) ? 1 : 0,
                'notes' => trim((string) ($_POST['customer_notes'] ?? '')),
            ];

            if ($customerId > 0) {
                updateCustomer($pdo, $customerId, $payload);
                $_SESSION['auth_message'] = 'Customer updated successfully.';
            } else {
                createCustomer($pdo, $payload);
                $_SESSION['auth_message'] = 'Customer saved successfully.';
            }
            redirect('/sales?action=customers');
        }

        if ($_POST['action'] === 'delete_customer') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            if ($delId > 0) {
                updateCustomer($pdo, $delId, ['is_active' => 0]);
                $_SESSION['auth_message'] = 'Customer deactivated successfully.';
            }
            redirect('/sales?action=customers');
        }
    }

    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $customer = getCustomerById($pdo, (int) $_GET['edit']);
    }
}

// ============================================================
// Quote Actions
// ============================================================

if ($action === 'quotes') {
    $pageTitle = 'Quotes';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_quote') {
            $quoteId = (int) ($_POST['quote_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $lines = collectSalesLines();
            $status = (string) ($_POST['quote_status'] ?? 'draft');
            if (!in_array($status, ['draft', 'sent', 'accepted', 'rejected', 'expired'], true)) {
                $status = 'draft';
            }

            if ($customerId <= 0 || $lines === []) {
                $_SESSION['auth_message'] = 'Select a customer and at least one line item.';
                redirect('/sales?action=quotes');
            }

            auth_guard_branch($pdo, (int) ($_POST['branch_id'] ?? 0));

            $payload = [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'branch_id' => (int) ($_POST['branch_id'] ?? 0) ?: null,
                'status' => $status,
                'issue_date' => $_POST['issue_date'] ?? date('Y-m-d'),
                'expiry_date' => $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+30 days')),
                'discount_percent' => (float) ($_POST['discount_percent'] ?? 0),
                'tax_rate' => (float) ($_POST['tax_rate'] ?? 0),
                'notes' => trim((string) ($_POST['quote_notes'] ?? '')),
                'lines' => $lines,
                'created_by' => salesUserId(),
            ];

            if ($quoteId > 0) {
                updateQuote($pdo, $quoteId, $payload);
                $savedQuoteId = $quoteId;
                $_SESSION['auth_message'] = 'Quote updated successfully.';
            } else {
                $savedQuoteId = createQuote($pdo, $payload);
                $_SESSION['auth_message'] = $savedQuoteId > 0
                    ? 'Quote saved successfully.'
                    : 'Unable to save quote.';
            }

            if ($savedQuoteId > 0 && $status === 'accepted'
                && salesSetting($pdo, 'auto_convert_quotes', '0') === '1') {
                convertQuoteToSale($pdo, $savedQuoteId);
            }

            redirect('/sales?action=quotes');
        }

        if ($_POST['action'] === 'convert_quote') {
            $qid = (int) ($_POST['quote_id'] ?? 0);
            if ($qid > 0 && convertQuoteToSale($pdo, $qid)) {
                $_SESSION['auth_message'] = 'Quote converted to a sales invoice.';
            } else {
                $_SESSION['auth_message'] = 'Unable to convert quote. Check the quote status and stock levels.';
            }
            redirect('/sales?action=quotes');
        }

        if ($_POST['action'] === 'delete_quote') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            $quoteRow = $delId > 0 ? getQuoteById($pdo, $delId) : null;
            if ($quoteRow && !in_array($quoteRow['status'], ['converted'], true)) {
                $pdo->prepare("DELETE FROM quote_lines WHERE quote_id = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM quotes WHERE quote_id = ?")->execute([$delId]);
                $_SESSION['auth_message'] = 'Quote deleted.';
            } else {
                $_SESSION['auth_message'] = 'Converted quotes cannot be deleted.';
            }
            redirect('/sales?action=quotes');
        }
    }

    if ($quoteId > 0) {
        $quote = getQuoteById($pdo, $quoteId);
        $quote['lines'] = getQuoteLines($pdo, $quoteId);
    }
}

// ============================================================
// Sales Invoice Actions
// ============================================================

if ($action === 'sales' || $action === 'list' || $action === 'manage_sales') {
    $pageTitle = 'Sales';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        require_post_csrf();

        if ($_POST['action'] === 'save_sale') {
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $lines = collectSalesLines();

            if ($customerId <= 0 || $lines === []) {
                $_SESSION['auth_message'] = 'Select a customer and at least one line item.';
                redirect('/sales?action=sales');
            }

            auth_guard_branch($pdo, (int) ($_POST['branch_id'] ?? 0));

            $result = createSalesInvoice($pdo, [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'branch_id' => (int) ($_POST['branch_id'] ?? 0) ?: null,
                'issue_date' => $_POST['issue_date'] ?? date('Y-m-d'),
                'due_date' => $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
                'discount_percent' => (float) ($_POST['discount_percent'] ?? 0),
                'tax_rate' => (float) ($_POST['tax_rate'] ?? 0),
                'amount_paid' => (float) ($_POST['amount_paid'] ?? 0),
                'payment_method' => $_POST['payment_method'] ?? null,
                'reference_number' => trim((string) ($_POST['reference_number'] ?? '')),
                'notes' => trim((string) ($_POST['sale_notes'] ?? '')),
                'lines' => $lines,
            ]);

            $_SESSION['auth_message'] = $result['success']
                ? $result['message']
                : 'Unable to record sale: ' . $result['message'];
            redirect('/sales?action=sales');
        }

        if ($_POST['action'] === 'record_payment') {
            $invoiceId = (int) ($_POST['sale_id'] ?? 0);
            $customerId = (int) ($_POST['customer_id'] ?? 0);
            $amount = (float) ($_POST['payment_amount'] ?? 0);

            if ($invoiceId > 0 && $customerId > 0 && $amount > 0
                && recordPayment($pdo, $customerId, $invoiceId, $amount,
                    (string) ($_POST['payment_method'] ?? 'bank_transfer'),
                    trim((string) ($_POST['payment_reference'] ?? '')),
                    trim((string) ($_POST['payment_notes'] ?? '')))) {
                $_SESSION['auth_message'] = 'Payment recorded successfully.';
            } else {
                $_SESSION['auth_message'] = 'Unable to record payment. Check the amount and invoice.';
            }
            redirect('/sales?action=sales');
        }

        if ($_POST['action'] === 'cancel_sale') {
            $delId = (int) ($_POST['delete_id'] ?? 0);
            if ($delId > 0 && updateSaleStatus($pdo, $delId, 'cancelled')) {
                $_SESSION['auth_message'] = 'Sale cancelled and stock restored.';
            } else {
                $_SESSION['auth_message'] = 'Unable to cancel this sale. Refund or remove recorded payments first.';
            }
            redirect('/sales?action=sales');
        }
    }

    if ($saleId > 0) {
        $sale = getSaleById($pdo, $saleId);
    }
}

// ============================================================
// POS Operations
// ============================================================

if ($action === 'pos') {
    $pageTitle = 'Point of Sale';
    require_once APP_ROOT . '/includes/shift_functions.php';
    require_once APP_ROOT . '/includes/barcode_functions.php';

    if (!isset($_SESSION['pos_cart'])) {
        posClearCart();
    }

    // The drawer a POS sale belongs to is decided by the session, never by a
    // posted field, so a client cannot aim a sale at another branch.
    $posBranchId = salesDefaultBranchId($pdo, $companyId);
    $posJson = posWantsJson();

    /** JSON envelope: current cart + shift state, so the screen never needs a second call. */
    $posRespond = static function (array $payload) use ($pdo, $companyId, $posBranchId): never {
        json_response($payload + [
            'cart' => posCartPayload($pdo),
            'shift' => shiftSummaryForUser($pdo, $companyId, (int) $posBranchId),
        ]);
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
        $posAction = (string) ($_POST['pos_action'] ?? '');

        if ($posJson) {
            switch ($posAction) {
                case 'search':
                    $items = posSearchProducts($pdo, (string) ($_POST['q'] ?? ''), $companyId, $posBranchId);
                    json_response(['ok' => true, 'items' => $items, 'branch_id' => $posBranchId]);

                case 'scan':
                    $code = trim((string) ($_POST['code'] ?? ''));
                    $found = bcLookup($pdo, $code, $companyId, $posBranchId);
                    if (!$found) {
                        $posRespond([
                            'ok' => false,
                            'code' => 'not_found',
                            'message' => 'No product matches "' . $code . '".',
                        ]);
                    }
                    $added = posAddLine($pdo, (int) $found['product_id'], 1);
                    $posRespond([
                        'ok' => (bool) $added['success'],
                        'code' => $added['success'] ? null : 'add_failed',
                        'product' => $found,
                        'message' => $added['success']
                            ? $found['name'] . ' added.'
                            : $added['message'],
                    ]);

                case 'add_item':
                    $result = posAddLine(
                        $pdo,
                        (int) ($_POST['product_id'] ?? 0),
                        (float) ($_POST['quantity'] ?? 0),
                        $posBranchId,
                        (float) ($_POST['discount_percent'] ?? 0)
                    );
                    $posRespond([
                        'ok' => $result['success'],
                        'code' => $result['success'] ? null : 'add_failed',
                        'message' => $result['message'],
                    ]);

                case 'set_quantity':
                    $result = posSetLineQuantity(
                        $pdo,
                        (int) ($_POST['item_index'] ?? -1),
                        (float) ($_POST['quantity'] ?? 0),
                        $posBranchId
                    );
                    $posRespond(['ok' => $result['success'], 'message' => $result['message']]);

                case 'set_discount':
                    $result = posSetLineDiscount($pdo, (int) ($_POST['item_index'] ?? -1),
                        (float) ($_POST['discount_percent'] ?? 0));
                    $posRespond(['ok' => $result['success'], 'message' => $result['message']]);

                case 'remove_item':
                    $removed = posRemoveItem($pdo, (int) ($_POST['item_index'] ?? -1));
                    $posRespond(['ok' => $removed, 'message' => $removed ? 'Line removed.' : 'That cart line no longer exists.']);

                case 'clear_cart':
                    posClearCart();
                    $posRespond(['ok' => true, 'message' => 'Cart cleared.']);

                case 'gateway_init':
                    // An online tender is never a sale on its own: this opens a
                    // payment session and quotes the cart total from the server.
                    $started = gatewayPosSession($pdo, $companyId, trim((string) ($_POST['tender'] ?? '')),
                        (int) $posBranchId, (int) ($_SESSION['user_id'] ?? 0));
                    $posRespond([
                        'ok' => $started['ok'],
                        'code' => $started['ok'] ? null : 'gateway_init_failed',
                        'message' => $started['message'],
                        'payment' => $started,
                    ]);

                case 'gateway_status':
                    $watched = gatewayPosStatus($pdo, $companyId, trim((string) ($_POST['reference'] ?? '')));
                    $posRespond([
                        'ok' => $watched['found'],
                        'code' => $watched['found'] ? null : 'unknown_reference',
                        'message' => $watched['message'],
                        'payment' => $watched,
                    ]);

                case 'checkout':
                    $tenders = [];
                    $postedMethods = (array) ($_POST['payment_method'] ?? []);
                    $postedAmounts = (array) ($_POST['payment_amount'] ?? []);
                    $postedRefs = (array) ($_POST['payment_reference'] ?? []);
                    foreach ($postedMethods as $i => $method) {
                        if (!is_string($method) || trim($method) === '') {
                            continue;
                        }
                        $tenders[] = [
                            'method' => trim($method),
                            'amount' => (float) ($postedAmounts[$i] ?? 0),
                            'reference' => trim((string) ($postedRefs[$i] ?? '')),
                        ];
                    }

                    $result = posCheckout($pdo, [
                        'branch_id' => (int) $posBranchId,
                        'payments' => $tenders,
                        'payment_method' => trim((string) array_shift($postedMethods)) ?: 'cash',
                        'payment_amount' => (float) ($postedAmounts[0] ?? 0),
                        'reference_number' => trim((string) ($postedRefs[0] ?? '')),
                        'notes' => trim((string) ($_POST['payment_notes'] ?? '')),
                        'idempotency_key' => posNewIdempotencyKey(),
                    ]);

                    if (!$result['success']) {
                        $posRespond([
                            'ok' => false,
                            'code' => str_contains($result['message'], 'shift') ? 'shift_required' : 'checkout_failed',
                            'message' => $result['message'],
                        ]);
                    }

                    $invoiceId = (int) $result['invoice_id'];
                    json_response([
                        'ok' => true,
                        'message' => $result['message'],
                        'duplicate' => (bool) ($result['duplicate'] ?? false),
                        'invoice_id' => $invoiceId,
                        'invoice_number' => (string) ($result['invoice_number'] ?? ''),
                        'total' => (float) $result['total'],
                        'change' => (float) ($result['change'] ?? 0),
                        'tendered' => (float) ($result['tendered'] ?? 0),
                        'receipt_url' => url('/sales?action=receipt&sale_id=' . $invoiceId),
                        'cart' => posCartPayload($pdo),
                        'shift' => shiftSummaryForUser($pdo, $companyId, (int) $posBranchId),
                    ]);
            }

            $posRespond(['ok' => false, 'code' => 'unknown_op', 'message' => 'Unknown POS operation.']);
        }

        if ($posAction === 'add_item') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = (float) ($_POST['quantity'] ?? 0);
            if (posAddItem($pdo, $productId, $quantity)) {
                $_SESSION['auth_message'] = 'Item added to cart.';
            } elseif ($quantity <= 0) {
                $_SESSION['auth_message'] = 'Enter a quantity greater than zero.';
            } else {
                $_SESSION['auth_message'] = 'Not enough stock on hand. Receive stock in Inventory first, or reduce the quantity.';
            }
            redirect('/sales?action=pos');
        }

        if ($posAction === 'remove_item') {
            posRemoveItem($pdo, (int) ($_POST['item_index'] ?? -1));
            redirect('/sales?action=pos');
        }

        if ($posAction === 'clear_cart') {
            posClearCart();
            redirect('/sales?action=pos');
        }

        if ($posAction === 'gateway_init') {
            // The no-Javascript way to take a card: open a session here and hand
            // the browser to the provider. Whether the money arrived is still
            // answered only by verification, so nothing is posted on this path.
            $started = gatewayPosSession($pdo, $companyId, trim((string) ($_POST['tender'] ?? '')),
                (int) $posBranchId, (int) ($_SESSION['user_id'] ?? 0));
            if (!$started['ok']) {
                $_SESSION['auth_message'] = $started['message'];
                redirect('/sales?action=pos');
            }
            $checkoutUrl = (string) $started['checkout_url'];
            if (preg_match('#^(?:https?://|/)#i', $checkoutUrl) === 1) {
                header('Location: ' . $checkoutUrl);
                exit;
            }
            $_SESSION['auth_message'] = $started['message'] . ' Open the payment from the reference ' . $started['reference'] . '.';
            redirect('/sales?action=pos&payment=' . rawurlencode($started['reference']));
        }

        if ($posAction === 'checkout') {
            $result = posCheckout($pdo, [
                'branch_id' => (int) $posBranchId,
                'payment_method' => $_POST['payment_method'] ?? 'cash',
                'payment_amount' => (float) ($_POST['payment_amount'] ?? 0),
                'reference_number' => trim((string) ($_POST['payment_reference'] ?? '')),
                'notes' => trim((string) ($_POST['payment_notes'] ?? '')),
                'idempotency_key' => posNewIdempotencyKey(),
            ]);

            if ($result['success']) {
                // Change is read back from the payment rows by the receipt, not
                // carried in the session: a reprint has to show it too.
                redirect('/sales?action=receipt&sale_id=' . (int) $result['invoice_id'] . '&autoprint=1');
            }

            $_SESSION['auth_message'] = 'POS checkout failed: ' . $result['message'];
            redirect('/sales?action=pos');
        }
    } elseif ($posJson) {
        // Read-only status poll for the AJAX screen: cart totals and whether
        // the register may take payment right now.
        json_response([
            'ok' => true,
            'cart' => posCartPayload($pdo),
            'shift' => shiftSummaryForUser($pdo, $companyId, (int) $posBranchId),
        ]);
    }
}

// ============================================================
// Online payment: the trip to the gateway and back
// ============================================================

if ($action === 'gateway_return') {
    // This is the URL the provider sends the customer's browser back to. What
    // the query string claims about the payment is ignored: the only question
    // asked is this server's, to the provider itself.
    require_permission('sales', 'create');
    $gatewayReference = trim((string) ($_GET['reference'] ?? $_GET['tx_ref'] ?? ''));
    if ($gatewayReference === '') {
        $_SESSION['auth_message'] = 'The gateway returned without a payment reference, so nothing was recorded.';
        redirect('/sales?action=pos');
    }

    $settled = gatewayVerify($pdo, $companyId, $gatewayReference);
    $settledInvoice = (int) ($settled['invoice_id'] ?? 0);
    if (($settled['ok'] ?? false) && $settledInvoice > 0) {
        posClearCart();
        $_SESSION['pos_last_invoice_id'] = $settledInvoice;
        redirect('/sales?action=receipt&sale_id=' . $settledInvoice . '&autoprint=1');
    }

    $_SESSION['auth_message'] = (string) ($settled['message'] ?? 'The payment could not be confirmed.');
    redirect('/sales?action=pos&payment=' . rawurlencode($gatewayReference));
}

if ($action === 'gateway_sandbox') {
    // The stand-in for a provider's checkout page on a workshop host. It is
    // honest about being a simulation, and gatewaySandboxRespond() refuses to
    // record anything when the host claims to be production.
    require_permission('sales', 'create');
    $sandboxReference = trim((string) ($_GET['reference'] ?? $_POST['reference'] ?? ''));
    $sandboxSession = $sandboxReference === '' ? null : gatewayTransactionByReference($pdo, $companyId, $sandboxReference);
    if ($sandboxSession === null) {
        $_SESSION['auth_message'] = 'That payment session is not known to this company.';
        redirect('/sales?action=pos');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
        $answered = gatewaySandboxRespond($pdo, $companyId, $sandboxReference,
            (string) ($_POST['outcome'] ?? ''), (int) ($_SESSION['user_id'] ?? 0));
        $answeredInvoice = (int) ($answered['invoice_id'] ?? 0);
        if (($answered['paid'] ?? false) && $answeredInvoice > 0) {
            posClearCart();
            $_SESSION['pos_last_invoice_id'] = $answeredInvoice;
            redirect('/sales?action=receipt&sale_id=' . $answeredInvoice . '&autoprint=1');
        }
        $_SESSION['auth_message'] = (string) ($answered['message'] ?? 'The sandbox did not answer.');
        redirect('/sales?action=pos&payment=' . rawurlencode($sandboxReference));
    }

    $sandboxAmount = (float) $sandboxSession['amount'];
    $sandboxCurrency = (string) $sandboxSession['currency'];
    $sandboxProvider = gatewayProviderLabel((string) $sandboxSession['gateway']);
    $sandboxCart = posCartPayload($pdo);
    $sandboxSimulatable = $sandboxSession['mode'] === 'sandbox' && gatewaySimulationPermitted();
    require APP_ROOT . '/views/gateway_sandbox.html';
    exit;
}

// ============================================================
// Printable Sales Receipt
// ============================================================

if ($action === 'receipt') {
    require_once APP_ROOT . '/includes/receipt_functions.php';

    $receiptSaleId = (int) ($_GET['sale_id'] ?? 0);
    $receiptFormat = strtolower(trim((string) ($_GET['format'] ?? 'html')));
    if (!in_array($receiptFormat, ['html', 'pdf'], true)) {
        $receiptFormat = 'html';
    }

    // One payload feeds the screen, the thermal print and the PDF, so the
    // three can never disagree about what was paid.
    $receipt = $receiptSaleId > 0 ? receiptPayload($pdo, $companyId, $receiptSaleId, $receiptFormat) : null;
    if (!$receipt) {
        $_SESSION['auth_message'] = 'Sales receipt not found.';
        redirect('/sales?action=sales');
    }

    $receiptWidths = receiptPaperWidths();
    $receiptWidth = (int) ($_GET['width'] ?? $receipt['print']['width']);
    if (!isset($receiptWidths[(string) $receiptWidth])) {
        $receiptWidth = $receipt['print']['width'];
    }
    $receipt['print']['width'] = $receiptWidth;
    $receiptAutoPrint = isset($_GET['autoprint']);
    $receiptReprint = $receipt['print']['reprint'];
    require_once APP_ROOT . '/includes/auth_service.php';
    receiptAuditPrint($pdo, $receipt, $receiptReprint);

    if ($receiptFormat === 'pdf') {
        $receiptPdf = receiptPdfBytes($receipt);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="RECEIPT-' . preg_replace('/[^A-Za-z0-9_-]/', '', $receipt['number']) . '.pdf"');
        header('Content-Length: ' . strlen($receiptPdf));
        echo $receiptPdf;
        exit;
    }

    require APP_ROOT . '/views/sales_receipt.html';
    exit;
}

// ============================================================
// Render Views
// ============================================================

$customers = getCustomersByCompany($pdo, $companyId);
$quotes = getQuotesByCompany($pdo, $companyId);
$sales = getSalesByCompany($pdo, $companyId);
$branches = getBranchesByCompany($pdo, $companyId);
$products = getProductsByCompany($pdo, $companyId);
$productStock = [];
$stockBranchId = salesDefaultBranchId($pdo, $companyId);
$posShift = null;
$posCashCodes = ['cash'];
$posOnlineMethods = [];
$posIdempotencyKey = bin2hex(random_bytes(16));
if ($stockBranchId) {
    // One query for the whole branch instead of a stock lookup per product row.
    $stmt = $pdo->prepare("SELECT product_id, on_hand_quantity FROM product_locations WHERE branch_id = ?");
    $stmt->execute([$stockBranchId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $stockRow) {
        $productStock[(int) $stockRow['product_id']] = (float) $stockRow['on_hand_quantity'];
    }
}
if ($action === 'pos') {
    $posShift = shiftSummaryForUser($pdo, $companyId, (int) $stockBranchId);
    $posCashCodes = shiftCashMethodCodes($pdo, $companyId);
    // The only gateway facts the till is allowed to know: which tenders are
    // card tenders and who runs them. Nothing about credentials.
    $posOnlineMethods = gatewayOnlineMethods($pdo, $companyId);
}
$posCart = $_SESSION['pos_cart'] ?? [];
$posSubtotal = (float) ($_SESSION['pos_subtotal'] ?? 0);
$posDiscount = (float) ($_SESSION['pos_discount'] ?? 0);
$posTaxRate = (float) ($_SESSION['pos_tax_rate'] ?? getDefaultTaxRate($pdo));
$posTax = (float) ($_SESSION['pos_tax'] ?? 0);
$posTotal = (float) ($_SESSION['pos_total'] ?? 0);
$defaultTaxRate = getDefaultTaxRate($pdo);

// Active payment methods for this company (DB-backed, legacy fallback inside)
$paymentMethodOptions = salesPaymentMethodOptions($pdo, $companyId);

$viewFile = 'views/sales.html';

require_once APP_ROOT . '/views/' . basename($viewFile);
