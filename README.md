# SMEPay WHMCS Payment Gateway Module

A simple and secure WHMCS module to accept UPI payments using **SMEPay Wizard**. This module enables merchants to collect payments via QR/UPI with zero transaction commissions (in development mode) and seamless integration using the SMEPay checkout widget.

---

## 🚀 Features

- Easy setup with your SMEPay Client ID and Secret
- Supports both **Development** and **Production** environments
- Launches SMEPay widget via "Pay Now" button
- Auto-validates payment after completion
- Customizable callback handling
- Prevents duplicate order creation
- Comprehensive logging for debugging

---

## 📁 Installation

### Step 1: Upload Files

Clone or download this repository and upload the files to your WHMCS installation:

```
/modules/gateways/smepay.php
/modules/gateways/callback/smepay_callback.php
```

### Step 2: Create Slugs Directory

The module stores temporary payment session data in a `slugs` folder. Create this directory and set proper permissions:

**Option A: Via FTP/File Manager**

1. Navigate to `/modules/gateways/`
2. Create a new folder named `slugs`
3. Set folder permissions to `755` or `777`

**Option B: Via SSH/Terminal**

```
cd /path/to/whmcs/modules/gateways
mkdir slugs
chmod 755 slugs
```

**Option C: Via cPanel File Manager**

1. Navigate to `/modules/gateways/`
2. Click **+ Folder** to create new folder named `slugs`
3. Right-click the `slugs` folder → **Permissions**
4. Set to `755` (or check Read, Write, Execute for Owner; Read, Execute for Group and Public)

**⚠️ Important:** The web server must have **write permissions** to this directory. If you encounter "Permission Denied" errors:

```
# Try setting permissions to 777 (less secure, but works)
chmod 777 /path/to/whmcs/modules/gateways/slugs

# Or change ownership to web server user
chown www-data:www-data /path/to/whmcs/modules/gateways/slugs  # Apache/Ubuntu
chown apache:apache /path/to/whmcs/modules/gateways/slugs      # Apache/CentOS
chown nginx:nginx /path/to/whmcs/modules/gateways/slugs        # Nginx
```

### Step 3: Configure in WHMCS Admin

1. Navigate to **Setup > Payments > Payment Gateways**
2. Find and activate **SMEPay**
3. Enter your configuration:
   - **Client ID**: Your SMEPay Client ID
   - **Client Secret**: Your SMEPay Client Secret  
   - **Environment**: Select `Development` for testing or `Production` for live
   - **Callback URL**: `https://yourdomain.com/modules/gateways/callback/smepay_callback.php`

4. Click **Save Changes**

---

## 🧪 Testing Mode (Sandbox)

Use these endpoints for development/testing:

- **Auth Endpoint**: `https://staging.smepay.in/api/wiz/external/auth`
- **Create Order**: `https://staging.smepay.in/api/wiz/external/order/create`
- **Validate Order**: `https://staging.smepay.in/api/wiz/external/order/validate`

Select **Environment: Development** in WHMCS gateway settings.

---

## 🌐 Production Mode

Switch to live by selecting **Environment: Production** in the gateway settings.

- **Auth Endpoint**: `https://extranet.smepay.in/api/wiz/external/auth`
- **Create Order**: `https://extranet.smepay.in/api/wiz/external/order/create`
- **Validate Order**: `https://extranet.smepay.in/api/wiz/external/order/validate`

⚠️ Ensure you use **live Client ID and Secret** from your SMEPay merchant dashboard.

---

## 📦 File Structure

```
/modules/gateways/
├── smepay.php                      # Main gateway module
├── slugs/                          # Session data storage (create this!)
│   └── *.txt                       # Temporary order slug files
└── callback/
    └── smepay_callback.php         # Payment validation callback
```

| File                              | Description                                 |
|-----------------------------------|---------------------------------------------|
| `smepay.php`                      | Main WHMCS gateway integration              |
| `callback/smepay_callback.php`    | Validates payment post transaction          |
| `slugs/`                          | Stores temporary order-slug mappings        |

---

## 📜 API Flow

1. **Authentication**: Authenticate with `client_id` and `client_secret` to receive an `access_token`
2. **Create Order**: Create an order and receive an `order_slug`
3. **Store Mapping**: Save the order-slug mapping in the `slugs` directory
4. **Launch Widget**: Frontend launches SMEPay checkout widget with the `slug`
5. **Payment**: User completes payment via UPI/QR
6. **Callback**: SMEPay redirects to your `callback_url` with order details
7. **Validation**: Module validates transaction using the stored slug and updates the WHMCS invoice

---

## 🔍 Troubleshooting

### Permission Issues

**Error**: `Order slug not found` or `Failed to create slugs directory`

**Solution**: Ensure the `slugs` folder has proper write permissions:

```
# Check current permissions
ls -la /path/to/whmcs/modules/gateways/slugs

# Fix permissions
chmod 755 /path/to/whmcs/modules/gateways/slugs

# If still not working, try:
chmod 777 /path/to/whmcs/modules/gateways/slugs
```

### Authentication Failed

**Error**: `SMEPay authentication failed (HTTP 401)`

**Solution**:
- Verify your Client ID and Client Secret are correct
- Ensure you're using the right environment (Dev vs Prod)
- Check WHMCS System Activity Log for detailed error messages

### Payment Not Updating

**Error**: Payment completed but invoice not marked as paid

**Solution**:
- Check that your Callback URL is correctly configured
- Verify your server can receive incoming HTTP requests (not blocked by firewall)
- Review WHMCS Activity Log for callback errors
- Ensure `slugs` folder is writable

### Debug Mode

Enable detailed logging by checking WHMCS **System Activity Log**:
- `Utilities > Logs > Module Log`
- Look for entries starting with "SMEPay"

---

## 🔐 Security Best Practices

- ✅ Never expose your `client_secret` in frontend code
- ✅ Always validate payments server-side using SMEPay validation API
- ✅ Use HTTPS for callback URLs
- ✅ Set `slugs` folder to `755` permissions (not `777` unless necessary)
- ✅ Regularly clean old files from the `slugs` directory
- ✅ Keep your WHMCS installation up to date

---

## 🛠 Resources

- **Website**: [https://smepay.io](https://smepay.io)
- **Documentation**: Contact SMEPay support for API documentation
- **Dashboard**: Log in to your SMEPay merchant account for credentials

---

## 🧹 Maintenance

### Cleanup Old Slug Files

The module automatically deletes slug files after successful payment. However, failed/abandoned payments may leave files behind. Set up a cron job to clean old files:

```
# Add to crontab (runs daily at 2 AM)
0 2 * * * find /path/to/whmcs/modules/gateways/slugs -name "*.txt" -mtime +1 -delete
```

Or manually via SSH:
```
# Delete files older than 24 hours
find /path/to/whmcs/modules/gateways/slugs -name "*.txt" -mtime +1 -delete
```

---

## 🤝 Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss what you would like to change.

### Development Setup

1. Fork this repository
2. Create your feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes: `git commit -m 'Add amazing feature'`
4. Push to the branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

---

## 📧 Support

For issues related to:
- **This module**: Open a GitHub issue
- **SMEPay API/Account**: Contact [support@smepay.io](mailto:support@smepay.io)
- **WHMCS Integration**: Check [WHMCS Documentation](https://docs.whmcs.com)

---

## 📄 License

This module is provided under the [MIT License](LICENSE).

---

## ✨ Version History

- **v1.0.0** (2025-11-20)
  - Initial release
  - Support for Development and Production environments
  - Payment validation via SMEPay API
  - Automatic order-slug mapping storage
  - Duplicate order prevention
  - Comprehensive error logging

---

## 🙏 Acknowledgments

- Built for [WHMCS](https://www.whmcs.com)
- Powered by [SMEPay](https://smepay.io)

---

**Made with ❤️ for seamless UPI payments in India**