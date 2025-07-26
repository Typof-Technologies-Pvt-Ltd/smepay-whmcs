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
    $amount = $params['amount'];
    $name = trim(($params['clientdetails']['firstname'] ?? '') . ' ' . ($params['clientdetails']['lastname'] ?? ''));
    $email = $params['clientdetails']['email'] ?? '';
    $phone = $params['clientdetails']['phonenumber'] ?? '';

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
        logActivity("SMEPay Auth HTTP Error: " . $authHttpCode);
        return "<p>Error: SMEPay authentication failed (HTTP $authHttpCode)</p>";
    }

    $auth = json_decode($authResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($auth['access_token'])) {
        logActivity("SMEPay Auth JSON Error: " . json_last_error_msg());
        return "<p>Error: Invalid authentication response from SMEPay</p>";
    }

    $token = $auth['access_token'];

    // Step 2: Create order using cURL
    $orderData = [
        'client_id' => $clientId,
        'amount' => $amount,
        'order_id' => $invoiceId,
        'callback_url' => $callbackUrl,
        'customer_details' => [
            'email' => $email,
            'mobile' => $phone,
            'name' => $name
        ]
    ];

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
        logActivity("SMEPay Order HTTP Error: " . $orderHttpCode . " - " . $orderResponse);
        return "<p>Error: SMEPay order creation failed (HTTP $orderHttpCode)</p>";
    }

    $response = json_decode($orderResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($response['order_slug'])) {
        logActivity("SMEPay Order JSON Error: " . json_last_error_msg() . " - " . $orderResponse);
        return "<p><strong>SMEPay Order Creation Failed</strong><br><pre>" . htmlspecialchars($orderResponse, ENT_QUOTES, 'UTF-8') . "</pre></p>";
    }

    $slug = htmlspecialchars($response['order_slug'], ENT_QUOTES, 'UTF-8');
    $callbackUrlEncoded = htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<script src="https://typof.co/smepay/checkout.js"></script>
<button onclick="handleOpenSMEPay()">Pay Now with SMEPay</button>
<script>
function handleOpenSMEPay() {
  if (window.smepayCheckout) {
    window.smepayCheckout({
      slug: "{$slug}",
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
HTML;
}
