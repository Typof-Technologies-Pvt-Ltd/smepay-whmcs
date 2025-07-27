<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// Get the order_id from the callback
$orderIdWithPrefix = $_GET['order_id'] ?? null;
if (!$orderIdWithPrefix) {
    logActivity("SMEPay Callback: Missing order_id parameter");
    die("Invalid Request - Missing order_id");
}

// Extract the actual invoice ID
$invoiceId = null;
if (strpos($orderIdWithPrefix, 'INV') === 0) {
    $withoutPrefix = substr($orderIdWithPrefix, 3);
    $parts = explode('_', $withoutPrefix);
    $invoiceId = $parts[0] ?? null;
} else {
    logActivity("SMEPay Callback: Invalid order_id format - " . $orderIdWithPrefix);
    die("Invalid Request - Invalid order_id format");
}

if (!is_numeric($invoiceId)) {
    logActivity("SMEPay Callback: Invalid invoice ID format - " . $invoiceId);
    die("Invalid Request - Invalid invoice ID");
}

// Retrieve slug from file (NO DATABASE ISSUES)
$slug = null;
try {
    $slugFile = dirname(__FILE__) . "/../slugs/" . $orderIdWithPrefix . ".txt";
    
    if (file_exists($slugFile)) {
        $slug = trim(file_get_contents($slugFile));
        // Clean up the file after reading
        unlink($slugFile);
        logActivity("SMEPay Callback: Retrieved slug $slug for order $orderIdWithPrefix");
    } else {
        logActivity("SMEPay Callback: Slug file not found for order $orderIdWithPrefix");
    }
} catch (Exception $e) {
    logActivity("SMEPay Callback Slug Retrieval Error: " . $e->getMessage());
}

if (!$slug) {
    logActivity("SMEPay Callback: Unable to find slug for order $orderIdWithPrefix");
    die("Order slug not found");
}

// Get invoice details
$invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
if ($invoiceData['result'] !== 'success') {
    logActivity("SMEPay Callback: Unable to retrieve invoice #$invoiceId");
    die("Invoice Retrieval Error");
}

// Get gateway configuration
$gateway = getGatewayVariables('smepay');
if (!$gateway) {
    logActivity("SMEPay Callback: Gateway configuration not found");
    die("Gateway Configuration Error");
}

$clientId = $gateway['client_id'];
$clientSecret = $gateway['client_secret'];
$env = $gateway['environment'] === 'Production' ? 'https://apps.typof.com' : 'https://apps.typof.in';

if (empty($clientId) || empty($clientSecret)) {
    logActivity("SMEPay Callback: Missing gateway configuration");
    die("Gateway Configuration Error");
}

// Step 1: Authenticate with SMEPay
$authData = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$env/api/external/auth",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($authData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true
]);

$authResponse = curl_exec($ch);
$authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$authError = curl_error($ch);
curl_close($ch);

if ($authResponse === false || !empty($authError)) {
    logActivity("SMEPay Callback Auth Error: " . $authError);
    die("Authentication Service Error");
}

if ($authHttpCode !== 200) {
    logActivity("SMEPay Callback Auth HTTP Error: " . $authHttpCode);
    die("Authentication Failed");
}

$auth = json_decode($authResponse, true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($auth['access_token'])) {
    logActivity("SMEPay Callback Auth JSON Error: " . json_last_error_msg());
    die("Invalid Authentication Response");
}

$token = $auth['access_token'];

// Step 2: Validate the order
$invoiceAmount = number_format(floatval($invoiceData['total']), 1, '.', '');

$validationData = [
    'client_id' => $clientId,
    'amount' => $invoiceAmount,
    'slug' => $slug
];

logActivity("SMEPay Validation Request: " . json_encode($validationData));

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$env/api/external/validate-order",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($validationData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true
]);

$validationResponse = curl_exec($ch);
$validationHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$validationError = curl_error($ch);
curl_close($ch);

if ($validationResponse === false || !empty($validationError)) {
    logActivity("SMEPay Callback Validation Error: " . $validationError);
    die("Order Validation Service Error");
}

if ($validationHttpCode !== 200) {
    logActivity("SMEPay Callback Validation HTTP Error: " . $validationHttpCode);
    die("Order Validation Failed");
}

$validationResult = json_decode($validationResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logActivity("SMEPay Callback Validation JSON Error: " . json_last_error_msg());
    die("Invalid Validation Response");
}

logActivity("SMEPay Validation Response: " . json_encode($validationResult));

// Check validation response
$validationStatus = $validationResult['status'] ?? false;
$paymentStatus = $validationResult['payment_status'] ?? null;

if ($validationStatus === true && $paymentStatus === 'paid') {
    
    $paidAmount = floatval($invoiceAmount);
    $transactionId = $orderIdWithPrefix;
    
    // Add payment to WHMCS
    $addPaymentResult = addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paidAmount,
        0,
        'smepay'
    );
    
    if ($addPaymentResult) {
        logTransaction("SMEPay", $validationResult, "Successful");
        logActivity("SMEPay Payment Successful: Invoice #$invoiceId - Order: $orderIdWithPrefix - Amount: $paidAmount");
        
        header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId);
        exit;
    } else {
        logActivity("SMEPay Payment Processing Error: Failed to add payment for Invoice #$invoiceId");
        die("Payment Processing Error");
    }
    
} else {
    $statusMsg = "Status: " . ($validationStatus ? 'true' : 'false') . ", Payment: " . ($paymentStatus ?? 'unknown');
    logActivity("SMEPay Payment Validation Failed: Invoice #$invoiceId - $statusMsg");
    logTransaction("SMEPay", $validationResult, "Validation Failed - $statusMsg");
    
    header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId . "&paymentfailed=1");
    exit;
}
