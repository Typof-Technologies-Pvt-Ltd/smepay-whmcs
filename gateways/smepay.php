<?php

function smepay_config() {
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SMEPay',
        ],
        'client_id' => [
            'FriendlyName' => 'Client ID',
            'Type' => 'text',
            'Size' => '50',
        ],
        'client_secret' => [
            'FriendlyName' => 'Client Secret',
            'Type' => 'text',
            'Size' => '50',
        ],
        'environment' => [
            'FriendlyName' => 'Environment',
            'Type' => 'dropdown',
            'Options' => 'Development,Production',
            'Description' => 'Choose Development for testing or Production for live payments.',
        ],
        'callback_url' => [
            'FriendlyName' => 'Callback URL',
            'Type' => 'text',
            'Size' => '100',
            'Description' => 'e.g., https://yourdomain.com/modules/gateways/callback/smepay_callback.php',
        ],
    ];
}

function smepay_link($params) {
    // Validate required parameters
    if (empty($params['client_id']) || empty($params['client_secret'])) {
        return "<p>Error: Missing SMEPay configuration</p>";
    }

    $clientId = $params['client_id'];
    $clientSecret = $params['client_secret'];
    $callbackUrl = $params['callback_url'] ?? '';
    $env = $params['environment'] === 'Production' ? 'https://apps.typof.com' : 'https://apps.typof.in';

    $invoiceId = $params['invoiceid'];
    $amount = floatval($params['amount']);

    // Validate amount
    if ($amount <= 0) {
        return "<p>Error: Invalid amount for SMEPay order</p>";
    }

    // Handle customer details with proper defaults
    $clientDetails = $params['clientdetails'] ?? [];
    
    // Extract and sanitize customer information with defaults
    $firstName = !empty($clientDetails['firstname']) ? trim($clientDetails['firstname']) : 'Customer';
    $lastName = !empty($clientDetails['lastname']) ? trim($clientDetails['lastname']) : '';
    $email = !empty($clientDetails['email']) ? trim($clientDetails['email']) : 'customer@example.com';
    $phone = !empty($clientDetails['phonenumber']) ? preg_replace('/[^\d+]/', '', $clientDetails['phonenumber']) : '0000000000';
    
    // Construct full name
    $name = trim($firstName . ' ' . $lastName);
    if (empty($name)) {
        $name = 'Customer';
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'customer@example.com';
    }
    
    // Ensure phone number has minimum length
    if (strlen($phone) < 10) {
        $phone = '0000000000';
    }

    // Step 1: Authenticate with SMEPay using cURL
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
        logActivity("SMEPay Auth Error: " . $authError);
        return "<p>Error: Unable to connect to SMEPay authentication service</p>";
    }

    if ($authHttpCode !== 200) {
        logActivity("SMEPay Auth HTTP Error: " . $authHttpCode . " - " . $authResponse);
        return "<p>Error: SMEPay authentication failed (HTTP $authHttpCode)</p>";
    }

    $auth = json_decode($authResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($auth['access_token'])) {
        logActivity("SMEPay Auth JSON Error: " . json_last_error_msg());
        return "<p>Error: Invalid authentication response from SMEPay</p>";
    }

    $token = $auth['access_token'];

    // Create unique order ID with random string
    $randomString = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    $uniqueOrderId = "INV{$invoiceId}_{$randomString}";

    // Step 2: Create order using cURL
    $orderData = [
        'client_id' => $clientId,
        'amount' => number_format($amount, 2, '.', ''),
        'order_id' => $uniqueOrderId,
        'callback_url' => $callbackUrl,
        'customer_details' => [
            'name' => $name,
            'email' => $email,
            'mobile' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName
        ]
    ];

    logActivity("SMEPay Order Data: " . json_encode($orderData));

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "$env/api/external/create-order",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($orderData),
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

    $orderResponse = curl_exec($ch);
    $orderHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $orderError = curl_error($ch);
    curl_close($ch);

    if ($orderResponse === false || !empty($orderError)) {
        logActivity("SMEPay Order Error: " . $orderError);
        return "<p>Error: Unable to create order with SMEPay</p>";
    }

    if ($orderHttpCode !== 200) {
        $errorDetails = [
            'http_code' => $orderHttpCode,
            'request_data' => $orderData,
            'response' => $orderResponse,
            'environment' => $env
        ];
        logActivity("SMEPay Order HTTP Error: " . json_encode($errorDetails));
        return "<p><strong>SMEPay Order Creation Failed (HTTP $orderHttpCode)</strong><br><pre>" . 
               htmlspecialchars($orderResponse, ENT_QUOTES, 'UTF-8') . "</pre></p>";
    }

    $response = json_decode($orderResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($response['order_slug'])) {
        logActivity("SMEPay Order JSON Error: " . json_last_error_msg() . " - " . $orderResponse);
        return "<p><strong>SMEPay Order Creation Failed</strong><br><pre>" . 
               htmlspecialchars($orderResponse, ENT_QUOTES, 'UTF-8') . "</pre></p>";
    }

    $slug = $response['order_slug'];

    // Step 3: Store slug using simple file-based approach (NO DATABASE ISSUES)
    try {
        $slugFile = dirname(__FILE__) . "/slugs/" . $uniqueOrderId . ".txt";
        $slugDir = dirname($slugFile);
        
        // Create directory if it doesn't exist
        if (!is_dir($slugDir)) {
            mkdir($slugDir, 0755, true);
        }
        
        // Store slug in file
        file_put_contents($slugFile, $slug);
        
        logActivity("SMEPay Slug stored: Invoice #$invoiceId, Order: $uniqueOrderId, Slug: $slug");
    } catch (Exception $e) {
        logActivity("SMEPay Slug Storage Error: " . $e->getMessage());
        // Continue anyway
    }

    $slugEscaped = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    $callbackUrlEncoded = htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8');

return <<<HTML
<script src="https://typof.co/smepay/checkout.js"></script>
<div class="payment-btn-container">
    <button type="button" class="btn btn-success btn-block" onclick="handleOpenSMEPay()">
        <i class="fas fa-qrcode"></i> Pay Now
    </button>
</div>
<script>
function handleOpenSMEPay() {
  if (window.smepayCheckout) {
    window.smepayCheckout({
      slug: "{$slugEscaped}",
      onSuccess: function(data) {
        window.location.href = '{$callbackUrlEncoded}?order_id=' + encodeURIComponent(data.order_id);
      },
      onFailure: function() {
        alert("Payment failed or cancelled.");
      }
    });
  } else {
    alert("SMEPay widget not loaded.");
  }
}
</script>
<style>
.payment-btn-container {
    margin: 10px 0;
}
.payment-btn-container .btn {
    font-size: 16px;
    padding: 12px 20px;
    border-radius: 4px;
    transition: all 0.3s ease;
}
.payment-btn-container .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}
</style>
HTML;
}

