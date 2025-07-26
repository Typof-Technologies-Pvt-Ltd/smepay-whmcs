<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

$invoiceId = $_GET['order_id'] ?? null;
if (!$invoiceId) die("Invalid Request");

$gateway = getGatewayVariables('smepay');
$clientId = $gateway['client_id'];
$clientSecret = $gateway['client_secret'];
$env = $gateway['environment'] === 'Production' ? 'https://apps.typof.com' : 'https://apps.typof.in';

$auth = file_get_contents("$env/api/external/auth", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json",
        'content' => json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret])
    ]
]));
$auth = json_decode($auth, true);
$token = $auth['access_token'] ?? null;
if (!$token) die("Authentication Failed");

$validation = file_get_contents("$env/api/external/validate-order", false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer $token\r\nContent-Type: application/json",
        'content' => json_encode([
            'client_id' => $clientId,
            'amount' => 0,
            'slug' => ''
        ])
    ]
]));
$data = json_decode($validation, true);

if ($data && $invoiceId) {
    addInvoicePayment($invoiceId, uniqid("sme_"), $data['amount'], 0, 'smepay');
    logTransaction("SMEPay", $data, "Successful");
    header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId);
} else {
    logTransaction("SMEPay", $data, "Validation Failed");
    echo "Payment validation failed.";
}
