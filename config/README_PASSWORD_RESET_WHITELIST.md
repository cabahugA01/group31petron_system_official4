# Password Reset Email Whitelist Configuration

## Overview
This security feature restricts password reset OTP emails to only **whitelisted email addresses**. This prevents unauthorized users from receiving password reset OTPs even if they have active accounts in the system.

## Purpose
- **Security Control**: Only authorized emails can receive password reset OTPs
- **Testing Control**: Useful during development/testing to prevent sending emails to real users
- **Compliance**: Helps meet security requirements for password reset functionality

## How It Works
1. User requests password reset via `forgot_password.php`
2. System finds user in database
3. System checks if user's email is in whitelist
4. If **whitelisted**: OTP is generated and sent to the email
5. If **NOT whitelisted**: User sees error message, OTP is NOT sent

## Configuration File
**Location**: `config/password_reset_whitelist.php`

### Add Whitelisted Email
Edit the `$password_reset_whitelist` array:

```php
$password_reset_whitelist = [
    'yyangcabahug@gmail.com',      // Currently whitelisted
    'admin@example.com',            // Add new emails here
    'manager@example.com',
];
```

### Remove Whitelisted Email
Simply delete or comment out the email from the array:

```php
$password_reset_whitelist = [
    'yyangcabahug@gmail.com',
    // 'old.email@example.com',     // Removed from whitelist
];
```

## Affected Files
- `public/forgot_password.php` - Initial password reset request
- `public/verify_otp.php` - OTP verification and resend functionality

## Error Messages

### User-Facing Message (Non-whitelisted)
```
"Password reset is currently restricted. Please contact your system administrator for assistance."
```

### Server Log (Error Log)
```
Password reset blocked for non-whitelisted email: user@example.com (user_id=123)
```

## Testing

### Test Case 1: Whitelisted Email (Should Work)
1. Go to: `http://localhost/group31petron_system_official4/public/forgot_password.php`
2. Enter: `yyangcabahug@gmail.com`
3. **Expected**: OTP sent successfully, redirected to verification page

### Test Case 2: Non-whitelisted Email (Should Fail)
1. Go to: `http://localhost/group31petron_system_official4/public/forgot_password.php`
2. Enter: `pepito@gmail.com` (or any other active user email)
3. **Expected**: Error message shown, NO OTP sent

## Security Notes

⚠️ **Important Security Considerations**:

1. **Do NOT commit sensitive emails** to version control if using public repositories
2. **Keep whitelist updated** - remove old/unused emails
3. **Monitor error logs** for suspicious password reset attempts
4. **Whitelist is case-insensitive** - `User@Example.com` matches `user@example.com`
5. **Email trimming** - Leading/trailing spaces are automatically removed

## Disabling the Whitelist (Production Mode)

If you want to allow ALL active users to reset passwords (normal production behavior), you have two options:

### Option 1: Remove the Whitelist Check
Comment out or remove the whitelist check in both files:
- `public/forgot_password.php` (around line 92)
- `public/verify_otp.php` (around line 40)

### Option 2: Use a Wildcard Function
Modify `config/password_reset_whitelist.php` to always return `true`:

```php
function isEmailWhitelistedForPasswordReset($email) {
    return true; // Allow all emails (production mode)
}
```

## Support & Troubleshooting

### Issue: "Password reset is currently restricted"
- **Cause**: Email is not in whitelist
- **Solution**: Add email to `config/password_reset_whitelist.php`

### Issue: Email in whitelist but still blocked
- **Cause**: Email might have extra spaces or different case in database
- **Solution**: Check database email value, whitelist function auto-trims and lowercases

### Issue: Want to allow all users
- **Solution**: See "Disabling the Whitelist" section above

## Changelog

### 2026-07-12
- ✅ Created centralized whitelist configuration file
- ✅ Added whitelist check to `forgot_password.php`
- ✅ Added whitelist check to `verify_otp.php` (resend OTP)
- ✅ Added helper functions for whitelist management
- ✅ Added error logging for blocked attempts
- ✅ Currently whitelisted: `yyangcabahug@gmail.com`

---

**Last Updated**: July 12, 2026  
**Version**: 1.0.0  
**Author**: System Administrator
