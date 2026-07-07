<?php
/**
 * SMS Configuration — Petron Station Management System
 * ─────────────────────────────────────────────────────
 *  * IMPORTANT: Free SMS services have limitations!
 *  * CURRENT STATUS: Using SIMULATED mode (logs to file)
 *  * TO ENABLE REAL SMS, you need a PAID provider:
 *  * OPTION 1: SEMAPHORE (Recommended for Philippines)
 *  Cost: ~₱0.60 per SMS
 *  Sign up: https://semaphore.co/
 *  Steps:
 *  1. Create account and load ₱100 credits
 *  2. Get API Key from dashboard
 *  3. Set provider = 'semaphore'
 *  4. Paste your API key below
 *  5. Set enabled = true
 *  * OPTION 2: TWILIO (International)
 *  Cost: $0.0079 per SMS
 *  Sign up: https://www.twilio.com/ (has FREE trial credits)
 *  Steps:
 *  1. Create account (get $15 free trial credit)
 *  2. Get Account SID, Auth Token, and Phone Number
 *  3. Set provider = 'twilio'
 *  4. Paste credentials below
 *  5. Set enabled = true
 *
 * OPTION 3: MOVIDER (Philippines Alternative)
 *  Cost: ~₱0.50 per SMS
 *  Sign up: https://www.movider.co/
 *  Steps:
 *  1. Create account and load credits
 *  2. Get API Key and Secret
 *  3. Set provider = 'movider'
 *  4. Paste credentials below
 *  5. Set enabled = true
 */

$sms_config = [  // Choose provider: 'semaphore', 'twilio', 'movider', or leave disabled for simulation  'provider'  => 'semaphore',  // Enable/Disable SMS sending (set to false for simulated mode)  'enabled'  => false,  // ← Set to TRUE after adding API credentials  // Semaphore settings (Philippines)  'api_key'  => 'YOUR_SEMAPHORE_API_KEY_HERE',  // ← Paste your Semaphore API key  'sender_name' => 'PETRON',  // Twilio settings (International, has FREE trial)  'account_sid'  => 'YOUR_TWILIO_ACCOUNT_SID_HERE',  // ← Paste your Twilio Account SID  'auth_token'  => 'YOUR_TWILIO_AUTH_TOKEN_HERE',  // ← Paste your Twilio Auth Token  'from_number'  => 'YOUR_TWILIO_PHONE_NUMBER_HERE', // ← Paste your Twilio phone number  // Movider settings (Philippines)  'movider_api_key'  => 'YOUR_MOVIDER_API_KEY_HERE',  'movider_api_secret' => 'YOUR_MOVIDER_API_SECRET_HERE',
];
?>
