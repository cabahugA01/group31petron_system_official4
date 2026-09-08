# Implementation Plan

- [x] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - UTF-8 Characters Display as Mojibake
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists
  - **Scoped PBT Approach**: Scope the property to concrete failing cases (notifications with common UTF-8 characters: accented letters, currency symbols, emoji)
  - Test implementation: Create notifications with UTF-8 characters (é, ñ, ₱, 🔔) in database, render notifications panel, assert that rendered HTML contains correct UTF-8 characters (not mojibake like "Ã©", "Ã±", "â‚±")
  - The test assertions should match the Expected Behavior Properties: For any notification with UTF-8 characters, rendered output contains correct characters without garbling
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct - it proves the bug exists)
  - Document counterexamples found: which UTF-8 characters are garbled and what mojibake patterns appear (e.g., "Café" → "CafÃ©", "₱500" → "â‚±500")
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - ASCII and HTML Escaping Behavior Unchanged
  - **IMPORTANT**: Follow observation-first methodology
  - Observe behavior on UNFIXED code for ASCII-only notifications (e.g., "Delivery completed", "Order #12345")
  - Observe behavior on UNFIXED code for HTML special characters (e.g., notification with "<script>alert('xss')</script>" should be escaped as "&lt;script&gt;...")
  - Write property-based tests capturing observed behavior patterns:
    - Property: For all ASCII-only notifications (characters with code points ≤ 127), rendered HTML output matches baseline behavior
    - Property: For all notifications containing HTML special characters (<, >, &, ", '), rendered HTML properly escapes them using htmlspecialchars()
    - Property: UI interactions (click-to-mark-read, badge count, timestamp display) function correctly
  - Property-based testing generates many test cases for stronger guarantees
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

- [-] 3. Fix for notification character encoding

  - [x] 3.1 Add HTTP Content-Type header with UTF-8 charset
    - Open `partials/header.php`
    - Locate the header section near the top (after cache-control headers, around lines 1-10)
    - Add: `header('Content-Type: text/html; charset=UTF-8');`
    - This ensures the browser interprets the response as UTF-8 regardless of HTML meta tag
    - _Bug_Condition: isBugCondition(notification) where containsNonASCII(notification.title) OR containsNonASCII(notification.message)_
    - _Expected_Behavior: UTF-8 characters display correctly without mojibake in browser_
    - _Preservation: ASCII-only notifications, HTML escaping, UI behavior unchanged_
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3_

  - [x] 3.2 Set PHP internal encoding to UTF-8
    - In `partials/header.php`, after the HTTP headers section
    - Add: `mb_internal_encoding('UTF-8');` (ensures PHP string functions treat text as UTF-8 multi-byte sequences)
    - This prevents PHP from treating multi-byte UTF-8 characters as separate single bytes
    - _Bug_Condition: isBugCondition(notification) where containsNonASCII(notification.title) OR containsNonASCII(notification.message)_
    - _Expected_Behavior: PHP string functions preserve UTF-8 multi-byte sequences_
    - _Preservation: ASCII-only notifications, HTML escaping, UI behavior unchanged_
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2_

  - [x] 3.3 Add UTF-8 parameter to htmlspecialchars() calls
    - Locate the notification rendering loop in `partials/header.php` around line 4290
    - Change: `htmlspecialchars($hn['title'] ?? 'Notification')`
    - To: `htmlspecialchars($hn['title'] ?? 'Notification', ENT_QUOTES, 'UTF-8')`
    - Change: `htmlspecialchars($hn['message'] ?? '')`
    - To: `htmlspecialchars($hn['message'] ?? '', ENT_QUOTES, 'UTF-8')`
    - This ensures htmlspecialchars() treats input as UTF-8 multi-byte encoding instead of default ISO-8859-1
    - _Bug_Condition: isBugCondition(notification) where containsNonASCII(notification.title) OR containsNonASCII(notification.message)_
    - _Expected_Behavior: htmlspecialchars() preserves UTF-8 characters while escaping HTML special chars_
    - _Preservation: HTML escaping for XSS prevention remains intact_
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.2_

  - [x] 3.4 Verify PDO connection UTF-8 configuration (optional reinforcement)
    - Open `public/db_connect.php`
    - Verify that the PDO DSN includes `charset=utf8mb4` (should already be present)
    - Optionally add explicit: `$pdo->exec("SET NAMES utf8mb4");` after connection for older MySQL versions
    - This ensures database connection uses UTF-8 for data transfer
    - _Bug_Condition: isBugCondition(notification) where containsNonASCII(notification.title) OR containsNonASCII(notification.message)_
    - _Expected_Behavior: Database fetches UTF-8 data correctly without conversion_
    - _Preservation: Existing database operations unchanged_
    - _Requirements: 2.4_

  - [x] 3.5 Verify HTML meta charset tag exists
    - Confirm `<meta charset="utf-8">` is present in HTML head section of `partials/header.php`
    - Should already be present as fallback if HTTP header is missing
    - _Preservation: Existing HTML structure unchanged_
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 3.6 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - UTF-8 Characters Display Correctly After Fix
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior
    - When this test passes, it confirms the expected behavior is satisfied
    - Run bug condition exploration test from step 1
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - Verify that notifications with UTF-8 characters (é, ñ, ₱, 🔔) display correctly in browser without mojibake
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [ ] 3.7 Verify preservation tests still pass
    - **Property 2: Preservation** - ASCII and HTML Escaping Unchanged After Fix
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Run preservation property tests from step 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - Confirm ASCII-only notifications display identically
    - Confirm HTML special characters remain properly escaped
    - Confirm UI interactions (click-to-mark-read, badge count) work correctly
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_

- [ ] 4. Checkpoint - Ensure all tests pass
  - Run full test suite including bug condition exploration test (Property 1) and preservation tests (Property 2)
  - Verify UTF-8 characters display correctly in notifications panel across different browsers
  - Verify ASCII-only notifications unchanged
  - Verify HTML escaping still prevents XSS attacks
  - Verify UI interactions work correctly
  - If any issues arise, investigate and consult with the user before proceeding
