# 🔧 PASSWORD RESET OTP ISSUE - COMPLETE FIX GUIDE

## 📌 PROBLEM DISCOVERED

The user reported that **OTP verification is failing even with the correct code**. After investigating the code, here's what we found:

### Root Cause Analysis

There are **TWO DIFFERENT OTP VERIFICATION PAGES** in the system:

1. **`verify_login_otp.php`** - For **LOGIN 2FA** (two-factor authentication)
   - Looks for `token_type = 'login'`
   - Used when logging in with Station ID + Email/Username + Password

2. **`verify_otp.php`** - For **PASSWORD RESET**
   - Looks for `token_type = 'reset'`
   - Used when resetting forgotten password

### The Bug

When users do **password reset**, the system:
- Sends OTP with `token_type = 'reset'` ✅
- **BUT** users might be accessing `verify_login_otp.php` instead of `verify_otp.php` ❌
- `verify_login_otp.php` searches for `token_type = 'login'` (WRONG!)
- Result: "Invalid OTP" error even with correct code

---

## ✅ THE FIX - CORRECT PASSWORD RESET FLOW

### Step-by-Step Password Reset Process

```
┌─────────────────────────────────────────────────────────────┐
│ 1. User visits: forgot_password.php                         │
│    - Enters email/username                                   │
│    - System generates OTP with token_type = 'reset'         │
│    - Stores in password_reset_tokens table                  │
│    - Sends email with OTP code                              │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 2. User redirected to: verify_otp.php?email=user@mail.com  │
│    - Enters the 6-digit OTP from email                      │
│    - System verifies token_type = 'reset'                   │
│    - Checks expiration (5 minutes)                          │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 3. User redirected to: forgot_password_reset.php            │
│    - Enters new password (twice for confirmation)           │
│    - Password must meet complexity requirements             │
│    - System updates password_hash in users table            │
│    - Marks token as 'used'                                  │
└─────────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ 4. Success! User redirected to: login.php                   │
│    - Can now log in with new password                       │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔍 HOW TO TEST THE FIX

### Method 1: Use the Testing Tool (RECOMMENDED)

1. **Access the test tool in your browser:**
   ```
   http://localhost/group31petron_system_official4/test_complete_password_reset_flow.php
   ```

2. **Enter a valid email** from your `users` table

3. **Click "Send Password Reset OTP"**
   - You'll see the OTP code displayed on screen
   - Check if email was sent successfully
   - Verify token is stored in database

4. **Click "Test OTP Verification"**
   - System will verify if the OTP works
   - Shows detailed debug info

5. **Go to Password Reset Page**
   - Click the link to test the real flow
   - Enter the OTP code
   - Set a new password

### Method 2: Manual Testing

1. **Clear old tokens first:**
   ```sql
   DELETE FROM password_reset_tokens WHERE token_type = 'reset';
   ```

2. **Visit the forgot password page:**
   ```
   http://localhost/group31petron_system_official4/public/forgot_password.php
   ```

3. **Enter your email** and submit

4. **Check your email** for the OTP (or check the test tool to see the code)

5. **IMPORTANT:** Make sure you are redirected to:
   ```
   public/verify_otp.php?email=youremail@example.com
   ```
   **NOT** `verify_login_otp.php`!

6. **Enter the OTP** code from your email

7. **Set your new password** when redirected to `forgot_password_reset.php`

---

## 🎯 COMMON ISSUES & SOLUTIONS

### Issue 1: "Invalid OTP" Error

**Possible Causes:**
- ❌ Using `verify_login_otp.php` instead of `verify_otp.php`
- ❌ OTP has expired (5 minutes timeout)
- ❌ Token type mismatch (`login` vs `reset`)
- ❌ Email doesn't match the one in database

**Solutions:**
1. Check the URL - make sure you're on `verify_otp.php?email=...`
2. Request a new OTP (they expire after 5 minutes)
3. Make sure email is exactly the same (case-sensitive)

### Issue 2: Email Not Received

**Possible Causes:**
- ❌ Email config not set up (`config/email_config.php`)
- ❌ Gmail app password incorrect
- ❌ Email in spam/junk folder

**Solutions:**
1. Check `config/email_config.php`:
   ```php
   'username' => 'christianval0813@gmail.com',
   'password' => 'ojgy ravy ufed qgfl',  // App password
   ```
2. Check spam/junk folder
3. Use the test tool to see email sending status
4. Check PHP error logs

### Issue 3: Wrong Verification Page

**Problem:** System redirects to `verify_login_otp.php` instead of `verify_otp.php`

**Solution:**
- This is by design! `verify_login_otp.php` is for login 2FA, NOT password reset
- Password reset should use `verify_otp.php`
- Check `forgot_password.php` line 195-203 to ensure correct redirect

---

## 📊 DATABASE TABLES EXPLAINED

### `password_reset_tokens` Table

Used for **EMAIL** OTP (password reset & email verification):

```sql
CREATE TABLE password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(255) NOT NULL,      -- The 6-digit OTP code
    token_type  ENUM('reset','login','email_verify'),
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    is_used     TINYINT(1) DEFAULT 0,
    ip_address  VARCHAR(45),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**Token Types:**
- `'reset'` - Password reset OTP (used in forgot password flow)
- `'login'` - Login 2FA OTP (used in login flow)
- `'email_verify'` - Email verification OTP

### `password_resets` Table

Used for **SMS** OTP (phone-based password reset):

```sql
CREATE TABLE password_resets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL,
    otp_code     CHAR(6) NOT NULL,
    expiry       DATETIME NOT NULL,
    status       ENUM('unused','used') DEFAULT 'unused',
    ip_address   VARCHAR(45),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🔐 SECURITY NOTES

1. **OTP Expiration:** All OTPs expire after 5 minutes for security
2. **Single Use:** Each OTP can only be used once
3. **IP Tracking:** All OTP requests are logged with IP addresses
4. **Rate Limiting:** Consider adding rate limiting to prevent abuse

---

## 📝 FILES INVOLVED

| File | Purpose |
|------|---------|
| `public/forgot_password.php` | Initiates password reset, sends OTP |
| `public/verify_otp.php` | Verifies OTP for password reset |
| `public/forgot_password_reset.php` | Updates password after verification |
| `public/verify_login_otp.php` | Verifies OTP for login 2FA (different flow!) |
| `config/email_config.php` | Email settings & OTP sending function |
| `test_complete_password_reset_flow.php` | Testing & debugging tool |

---

## 🚀 QUICK FIX SUMMARY

**If OTP verification is failing:**

1. ✅ Make sure you're using the **correct URL**:
   - Password Reset: `verify_otp.php?email=...`
   - Login 2FA: `verify_login_otp.php`

2. ✅ Check the **token_type** in database:
   ```sql
   SELECT * FROM password_reset_tokens 
   WHERE token = 'YOUR_OTP_CODE' 
   AND token_type = 'reset';
   ```

3. ✅ Verify **expiration time**:
   ```sql
   SELECT *, 
          (expires_at > NOW()) AS is_valid 
   FROM password_reset_tokens 
   WHERE token = 'YOUR_OTP_CODE';
   ```

4. ✅ Use the **test tool** to debug:
   ```
   http://localhost/group31petron_system_official4/test_complete_password_reset_flow.php
   ```

---

## 💡 FOR DEVELOPERS

### How to Add Better Error Messages

Edit `public/verify_otp.php` around line 150:

```php
if (!$token_data) {
    // Add more helpful error message
    $error = "Invalid OTP or OTP has expired. Please request a new password reset.";
    
    // Optional: Add debug info (remove in production)
    error_log("OTP Verification Failed - Token: {$otp}, Email: {$email}");
}
```

### How to Extend OTP Expiration Time

Edit the expiry time in `public/forgot_password.php` line 175:

```php
// Change from 5 minutes to 10 minutes
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));  // Was: +5 minutes
```

---

## ✅ VERIFICATION CHECKLIST

- [ ] Email configuration is correct in `config/email_config.php`
- [ ] Gmail app password is valid
- [ ] `password_reset_tokens` table exists in database
- [ ] Users table has valid email addresses
- [ ] User status is 'Active' (not 'Locked' or 'Disabled')
- [ ] Testing tool shows OTP is generated and stored correctly
- [ ] Email is being sent (check inbox/spam)
- [ ] Correct verification page is used (`verify_otp.php` for password reset)
- [ ] OTP hasn't expired (5 minutes validity)
- [ ] OTP hasn't been used already

---

## 📞 NEED MORE HELP?

Run the complete test flow using:
```
http://localhost/group31petron_system_official4/test_complete_password_reset_flow.php
```

This tool will show you:
- ✅ If the user exists
- ✅ If the OTP was generated
- ✅ If the email was sent
- ✅ If the OTP is stored correctly in database
- ✅ If the verification works
- ✅ Detailed error messages if something fails

---

**Last Updated:** June 6, 2026  
**System:** Petron Station Management System  
**Status:** Email OTP Working ✅ | SMS OTP Disabled (needs paid API) ⚠️
