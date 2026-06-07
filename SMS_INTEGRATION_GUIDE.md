# SMS Integration Guide - Petron System

## 📱 Current Status: SIMULATED MODE

The system is currently running in **SIMULATED SMS MODE**. OTP codes are written to `sms_sent.log` file instead of being sent as actual SMS messages.

## ✅ What's Already Working

### Login & Password Reset with Phone:
- ✅ Phone number detection (11-digit format: 09XXXXXXXXX)
- ✅ Database queries by phone number
- ✅ OTP generation (6-digit random code)
- ✅ OTP storage in database (5-minute expiry)
- ✅ OTP verification flow
- ✅ SMS function infrastructure

### Current Flow:
1. User enters phone number (e.g., `09851743073`)
2. System generates 6-digit OTP
3. System stores OTP in database
4. System writes OTP to `sms_sent.log` file *(SIMULATED)*
5. User checks log file for OTP code
6. User enters OTP to verify
7. System validates OTP
8. Password reset proceeds

## 🚀 How to Enable REAL SMS

### Option 1: Semaphore (Recommended for Philippines)

#### Step 1: Create Account
1. Go to https://semaphore.co/
2. Click **"Sign Up"**
3. Fill in:
   - Full Name
   - Email Address
   - Password
4. Verify your email
5. Complete phone verification

#### Step 2: Load Credits
1. Login to dashboard
2. Click **"Buy Credits"**
3. Choose amount:
   - **Minimum:** ₱100 (~ 50-100 SMS)
   - **Testing:** ₱500 (~ 250-500 SMS)
   - **Production:** ₱5,000+ per month
4. Payment methods:
   - GCash *(FASTEST)*
   - PayMaya
   - Bank Transfer
   - Credit Card
5. Wait for credits to reflect (instant for GCash)

#### Step 3: Get API Key
1. Go to **"API"** section in dashboard
2. Find **"API Key"** section
3. Copy your API key (format: `xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)
4. Keep it secure!

#### Step 4: Configure System
Open `config/sms_config.php`:
```php
$sms_config = [
    'provider' => 'semaphore',
    'api_key' => 'YOUR_ACTUAL_API_KEY_HERE', // ← Paste here
    'sender_name' => 'PETRON',
    'enabled' => true, // ← Change to true
];
```

#### Step 5: Test SMS
Run test script:
```bash
cd c:\xampp\htdocs\group31petron_system_official4\database
C:\xampp\php\php.exe test_sms_send.php
```

Expected output:
```
✅ SUCCESS! SMS sent in XXXms
   Status: Pending
   Message ID: xxxxxxxxxx
```

### Option 2: Twilio (Global Provider)

#### Step 1: Create Account
1. Go to https://www.twilio.com/
2. Sign up (free trial: $15 credit)
3. Verify phone number

#### Step 2: Get Credentials
1. Go to Console Dashboard
2. Note:
   - Account SID
   - Auth Token
   - Twilio Phone Number

#### Step 3: Update Code
Create new function in `config/email_config.php`:
```php
function sendTwilioSMS($to_phone, $message) {
    $account_sid = 'YOUR_ACCOUNT_SID';
    $auth_token = 'YOUR_AUTH_TOKEN';
    $from_number = 'YOUR_TWILIO_NUMBER';
    
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
    
    $data = [
        'From' => $from_number,
        'To' => '+63' . substr($to_phone, 1), // Convert 09XX to +639XX
        'Body' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_USERPWD, "$account_sid:$auth_token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    return isset($result['sid']);
}
```

## 💰 Pricing Comparison

| Provider | Country | OTP SMS Cost | Free Trial | Best For |
|----------|---------|--------------|------------|----------|
| **Semaphore** | Philippines | ₱1.50-₱2.00 | No | PH-only systems |
| **Twilio** | Global | $0.05-0.10 | $15 credit | International |
| **Vonage** | Global | $0.04-0.08 | €2 credit | High volume |
| **AWS SNS** | Global | $0.00645 | Free tier | AWS users |

## 🧪 Testing Guide

### Test in Simulated Mode (Current):
```bash
# Check SMS log
type c:\xampp\htdocs\group31petron_system_official4\sms_sent.log

# Test password reset
1. Go to forgot_password.php
2. Enter: 09851743073
3. Check sms_sent.log for OTP
4. Enter OTP in verify_otp.php
```

### Test with Real API:
```bash
# Run test script
cd c:\xampp\htdocs\group31petron_system_official4\database
C:\xampp\php\php.exe test_sms_send.php

# Test actual flow
1. Go to forgot_password.php
2. Enter your phone number
3. Check phone for SMS
4. Enter OTP received
```

## 🔧 Troubleshooting

### Issue: "Invalid API Key"
**Solution:**
- Check if API key is correct
- Ensure no extra spaces
- Verify account is active
- Check if credits are loaded

### Issue: "SMS not received"
**Solutions:**
1. Check phone number format (+639XXXXXXXXX)
2. Verify phone is active
3. Check SMS credits balance
4. Wait 1-2 minutes (network delay)
5. Check spam/junk messages

### Issue: "cURL error"
**Solutions:**
1. Enable cURL in `php.ini`:
   ```ini
   extension=curl
   ```
2. Restart Apache
3. Verify internet connection
4. Check firewall settings

## 📊 Monitoring SMS Usage

### Check Sent Messages:
```bash
# View SMS log
type c:\xampp\htdocs\group31petron_system_official4\sms_sent.log

# Count today's SMS
findstr /C:"[2026-06-05" c:\xampp\htdocs\group31petron_system_official4\sms_sent.log
```

### Database Query:
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) as otp_sent
FROM password_reset_tokens 
WHERE token_type = 'reset'
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;
```

## ⚠️ Important Notes

1. **API Key Security:**
   - Never commit API keys to version control
   - Use environment variables in production
   - Rotate keys regularly

2. **Cost Management:**
   - Monitor daily SMS usage
   - Set budget limits
   - Use rate limiting
   - Implement fraud detection

3. **Philippines Phone Format:**
   - Accept: `09XXXXXXXXX` (11 digits)
   - Convert to: `+639XXXXXXXXX` for API
   - Validate format before sending

4. **OTP Security:**
   - 6-digit numeric only
   - 5-minute expiry
   - Single use only
   - Rate limit: max 3 per hour per phone

## ✅ Summary

| Feature | Status |
|---------|--------|
| Phone Login | ✅ Working |
| Phone Password Reset | ✅ Working |
| OTP Generation | ✅ Working |
| OTP Validation | ✅ Working |
| SMS Infrastructure | ✅ Ready |
| **Real SMS** | ⚠️ **Needs API Key** |

**To enable real SMS:** Get Semaphore API key and update `config/sms_config.php`

## 📞 Support

**Semaphore Support:**
- Website: https://semaphore.co/
- Email: support@semaphore.co
- Phone: Contact through website

**System Admin:**
- Check `sms_sent.log` for all SMS attempts
- Monitor credits balance regularly
- Test before going live
