# SMEPay WHMCS Payment Gateway Module

A simple and secure WHMCS module to accept UPI payments using **SMEPay Wizard**. This module enables merchants to collect payments via QR/UPI with zero transaction commissions (in development mode) and seamless integration using the SMEPay checkout widget.

---

## 🚀 Features

- Easy setup with your SMEPay Client ID and Secret
- Supports both **Development** and **Production** environments
- Launches SMEPay widget via "Pay Now" button
- Auto-validates payment after completion
- Customizable callback handling

---

## 📁 Installation

1. Clone or download this repository.

2. Upload the files to your WHMCS installation:

3. In your WHMCS Admin Panel:

- Navigate to **Setup > Payments > Payment Gateways**
- Activate **SMEPay**
- Enter your:
  - `Client ID`
  - `Client Secret`
  - `Callback URL`: e.g., `https://yourdomain.com/modules/gateways/callback/smepay_callback.php`
  - Choose **Environment**: Development or Production

---

## 🧪 Testing Mode (Sandbox)

Use these endpoints for development/testing:


---

## 🌐 Production Mode

Switch to live by selecting "Production" in the gateway settings.


Ensure you use **live Client ID and Secret** from your SMEPay merchant dashboard.

---

## 📦 Files

| File                              | Description                                 |
|-----------------------------------|---------------------------------------------|
| `smepay.php`                      | Main WHMCS gateway integration              |
| `callback/smepay_callback.php`    | Validates payment post transaction          |

---

## 📜 API Flow

1. Authenticate with `client_id` and `client_secret`
2. Create order and receive a `slug`
3. Widget is launched on frontend with the `slug`
4. After payment, SMEPay redirects to your `callback_url`
5. The module validates the transaction with `slug` and updates the invoice

---

## 🔐 Security Tips

- Do not expose your `client_secret` in frontend
- Always validate the payment server-side using the provided APIs

---

## 🛠 Resources

- [Website](https://smepay.io)


---

## 🤝 Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

---

## 📧 Support

For issues related to this module, please contact [support@smepay.io](mailto:support@smepay.io)

---

## 📄 License

This module is provided under the [MIT License](LICENSE).


