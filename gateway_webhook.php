<?php
/**
 * Public gateway callback endpoint. One per provider:
 *   POST /inventory/gateway_webhook.php?gateway=paystack
 *   POST /inventory/gateway_webhook.php?gateway=flutterwave
 *
 * There is no session and no CSRF token here — the provider cannot hold one.
 * Authentication is the signature on the body, checked against the secret the
 * company stored, before a single field of the payload is trusted. A callback
 * announces that something happened; it does not prove a payment, so the
 * handler always asks the provider for the real answer before a sale is posted.
 */

defined('APP_ROOT') || define('APP_ROOT', __DIR__);

require_once APP_ROOT . '/bootstrap.php';
require_once APP_ROOT . '/includes/auth_functions.php';
require_once APP_ROOT . '/includes/company_functions.php';
require_once APP_ROOT . '/includes/sales_functions.php';
require_once APP_ROOT . '/includes/gateway_functions.php';

/** Answer the provider and stop. Text only: nothing here is a page. */
function gatewayWebhookReply(int $status, string $body): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    gatewayWebhookReply(405, 'POST only.');
}

$gateway = strtolower(trim((string) ($_GET['gateway'] ?? $_POST['gateway'] ?? '')));
if (!gatewayIsKnownProvider($gateway)) {
    gatewayWebhookReply(404, 'Unknown gateway.');
}

// The signature covers the exact bytes sent, so the body is read raw and
// parsed only after the handler has decided whom it came from.
$rawBody = (string) file_get_contents('php://input');
if ($rawBody === '' || strlen($rawBody) > 200000) {
    gatewayWebhookReply(400, 'Empty or oversized payload.');
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
    }
}
// The signature header is compared case-insensitively by the handler, but a
// proxy may fold it; collect both spellings of the provider's header name.
foreach (gatewayProviders() as $providerKey => $provider) {
    if ($providerKey === $gateway) {
        $wanted = strtolower((string) $provider['webhook_header']);
        if (!isset($headers[$wanted]) && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                $headers[strtolower((string) $key)] = (string) $value;
            }
        }
        break;
    }
}

$pdo = db();

try {
    $answer = gatewayHandleWebhook($pdo, $gateway, $rawBody, $headers);
} catch (Throwable $exception) {
    // The provider must retry, so this is a 5xx and not a page. The message is
    // logged, never echoed: a callback caller has no business reading it.
    log_app_exception($exception);
    gatewayWebhookReply(500, 'Processing failed.');
}

gatewayWebhookReply((int) $answer['status'], (string) $answer['body']);
