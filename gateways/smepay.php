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
    $clientId = $params['client_id'];
    $clientSecret = $params['client_secret'];
    $callbackUrl = $params['callback_url'];
    $env = $params['environment'] === 'Production' ? 'https://apps.typof.com' : 'https://apps.typof.in';

    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $name = $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];

    $auth = file_get_contents("$env/api/external/auth", false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json",
            'content' => json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret])
        ]
    ]));

    $auth = json_decode($auth, true);
    $token = $auth['access_token'] ?? null;

    if (!$token) {
        return "<p>Error: Unable to authenticate with SMEPay</p>";
    }

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

    $result = file_get_contents("$env/api/external/create-order", false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $token\r\nContent-Type: application/json",
            'content' => json_encode($orderData)
        ]
    ]));

    $response = json_decode($result, true);
    $slug = $response['order_slug'] ?? null;

    if (!$slug) {
        return "<p><strong>SMEPay Order Creation Failed</strong><br><pre>" . json_encode($response, JSON_PRETTY_PRINT) . "</pre></p>";
    }

    return <<<HTML
<script src="https://typof.co/smepay/checkout.js"></script>
<button onclick="handleOpenSMEPay()">Pay Now with SMEPay</button>
<script>
function handleOpenSMEPay() {
  if (window.smepayCheckout) {
    window.smepayCheckout({
      slug: "{$slug}",
      onSuccess: function(data) {
        window.location.href = '{$callbackUrl}?order_id=' + encodeURIComponent(data.order_id);
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
