<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// Get the order_id from the callback (try multiple sources)
$orderIdWithPrefix = $_GET['order_id'] ?? $_POST['order_id'] ?? $_REQUEST['order_id'] ?? null;

// Log all incoming parameters for debugging
logActivity("SMEPay Callback: Received parameters - GET: " . json_encode($_GET) . ", POST: " . json_encode($_POST));

if (!$orderIdWithPrefix) {
    logActivity("SMEPay Callback: Missing order_id parameter");
    die("Invalid Request - Missing order_id");
}

logActivity("SMEPay Callback: Processing order_id = $orderIdWithPrefix");

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

// Retrieve slug from file with enhanced search
$slug = null;
$slugsDir = dirname(__FILE__) . "/../slugs";

try {
    // Method 1: Try exact match
    $slugFile = $slugsDir . "/" . $orderIdWithPrefix . ".txt";
    
    logActivity("SMEPay Callback: Looking for slug file: $slugFile");
    
    if (file_exists($slugFile)) {
        $slug = trim(file_get_contents($slugFile));
        //unlink($slugFile);
        logActivity("SMEPay Callback: Retrieved slug $slug for order $orderIdWithPrefix (exact match)");
    } else {
        logActivity("SMEPay Callback: Slug file not found at exact path: $slugFile");
        
        // Method 2: Search by invoice ID pattern
        $pattern = $slugsDir . "/INV{$invoiceId}_*.txt";
        $matchingFiles = glob($pattern);
        
        logActivity("SMEPay Callback: Searching with pattern: $pattern, Found files: " . json_encode($matchingFiles));
        
        if (!empty($matchingFiles)) {
            // Get the most recent file
            usort($matchingFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $slugFile = $matchingFiles[0];
            $slug = trim(file_get_contents($slugFile));
            //unlink($slugFile);
            logActivity("SMEPay Callback: Retrieved slug $slug from pattern match: " . basename($slugFile));
        } else {
            // Method 3: Check all files in directory for debugging
            $allFiles = glob($slugsDir . "/*.txt");
            logActivity("SMEPay Callback: All files in slugs directory: " . json_encode(array_map('basename', $allFiles)));
            
            // Method 4: Try to find by invoice ID in any recent file
            foreach ($allFiles as $file) {
                if ((time() - filemtime($file)) < 600) { // Within last 10 minutes
                    $filename = basename($file, '.txt');
                    if (strpos($filename, "INV{$invoiceId}") !== false) {
                        $slug = trim(file_get_contents($file));
                        //unlink($file);
                        logActivity("SMEPay Callback: Retrieved slug $slug from recent file: " . basename($file));
                        break;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    logActivity("SMEPay Callback Slug Retrieval Error: " . $e->getMessage());
}

if (!$slug) {
    logActivity("SMEPay Callback: Unable to find slug for order $orderIdWithPrefix, Invoice #$invoiceId");
    logActivity("SMEPay Callback: Slugs directory path: $slugsDir");
    logActivity("SMEPay Callback: Directory exists: " . (is_dir($slugsDir) ? 'yes' : 'no'));
    logActivity("SMEPay Callback: Directory readable: " . (is_readable($slugsDir) ? 'yes' : 'no'));
    die("Order slug not found - Check activity log for details");
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
$env = $gateway['environment'] === 'Production' ? 'https://extranet.smepay.in/api/wiz/external' : 'https://staging.smepay.in/api/wiz/external';

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
    CURLOPT_URL => "$env/auth",
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
    logActivity("SMEPay Callback Auth HTTP Error: " . $authHttpCode . " - " . $authResponse);
    die("Authentication Failed - HTTP " . $authHttpCode);
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
    'amount' => (float)$invoiceAmount,
    'slug' => $slug
];


logActivity("SMEPay Validation Request: " . json_encode($validationData));

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$env/order/validate",
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
    logActivity("SMEPay Callback Validation HTTP Error: " . $validationHttpCode . " - " . $validationResponse);
    die("Order Validation Failed - HTTP " . $validationHttpCode);
}

$validationResult = json_decode($validationResponse, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logActivity("SMEPay Callback Validation JSON Error: " . json_last_error_msg());
    die("Invalid Validation Response");
}

logActivity("SMEPay Validation Response: " . json_encode($validationResult));

// Check validation response (handle multiple success statuses)
$validationStatus = $validationResult['status'] ?? false;
$paymentStatus = strtoupper($validationResult['payment_status'] ?? '');

$successStatuses = ['SUCCESS', 'PAID', 'COMPLETED', 'SUCCESSFUL'];
$isSuccess = $validationStatus === true && in_array($paymentStatus, $successStatuses);

if ($isSuccess) {

    $paidAmount = floatval($invoiceAmount);

    // Use an explicit transaction id variable.
    // If SMEPay returns its own transaction id in $validationResult, prefer that instead.
    $transactionId = $orderIdWithPrefix;   // or: $validationResult['transaction_id'] ?? $orderIdWithPrefix;

    // Duplicate-transaction protection (NEW LINE)
    // This will stop the script if a transaction with the same ID already exists.
    checkCbTransID($transactionId); // [web:8][web:11][web:24]

    // Check if already paid to prevent duplicates (your existing logic)
    if ($invoiceData['status'] === 'Paid') {
        logActivity("SMEPay: Invoice #$invoiceId already marked as paid");
        header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId);
        exit;
    }

    // Add payment to WHMCS (unchanged except for using $transactionId variable)
    $addPaymentResult = addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paidAmount,
        0,
        'smepay'
    );

    if ($addPaymentResult) {
        logTransaction("SMEPay", $validationResult, "Successful");
        logActivity("SMEPay Payment Successful: Invoice #$invoiceId - Order: $orderIdWithPrefix - Amount: $paidAmount - Slug: $slug");

        header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId);
        exit;
    } else {
        logActivity("SMEPay Payment Processing Error: Failed to add payment for Invoice #$invoiceId");
        die("Payment Processing Error");
    }

} else {
    $statusMsg = "Status: " . ($validationStatus ? 'true' : 'false') . ", Payment: " . $paymentStatus;
    logActivity("SMEPay Payment Validation Failed: Invoice #$invoiceId - Order: $orderIdWithPrefix - $statusMsg");
    logTransaction("SMEPay", $validationResult, "Validation Failed - $statusMsg");
    
    header("Location: " . $CONFIG['SystemURL'] . "/viewinvoice.php?id=" . $invoiceId . "&paymentfailed=1");
    exit;
}
