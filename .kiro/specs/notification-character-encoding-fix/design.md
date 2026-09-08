# Notification Character Encoding Fix Bugfix Design

## Overview

Notifications containing UTF-8 characters (accented letters, emoji, special symbols) are displaying as garbled text (mojibake) in the notifications dropdown panel. Despite the database using `charset=utf8mb4`, HTML declaring `<meta charset="utf-8">`, and output being escaped with `htmlspecialchars()`, character encoding is breaking somewhere in the data pipeline.

The fix will ensure UTF-8 encoding is preserved throughout the entire data flow: database retrieval → PHP processing → HTML rendering → browser display. This involves adding explicit encoding declarations at critical points in the pipeline and verifying that no encoding conversion is occurring inadvertently.

## Glossary

- **Bug_Condition (C)**: The condition that triggers the bug - when notification text contains non-ASCII UTF-8 characters (accented letters, emoji, symbols)
- **Property (P)**: The desired behavior when UTF-8 characters are present - they should display correctly without garbling
- **Preservation**: Existing ASCII text display, HTML escaping for XSS prevention, and UI behavior that must remain unchanged
- **Mojibake**: Garbled text resulting from character encoding mismatches (e.g., "Café" displayed as "CafÃ©")
- **UTF-8**: Unicode character encoding that supports all international characters and symbols
- **htmlspecialchars()**: PHP function in `partials/header.php` line 4290 that escapes HTML special characters
- **$header_notifications**: Array in `partials/header.php` containing notification data fetched from the database
- **notifications table**: Database table storing notification records with columns: id, user_id, type, title, message, event_type, severity, redirect_url, status, created_at

## Bug Details

### Bug Condition

The bug manifests when notification text (title or message) contains UTF-8 characters beyond the basic ASCII range (characters with code points > 127). The encoding pipeline is corrupting these characters during one of these stages: database retrieval via PDO, PHP string processing, or HTML output rendering.

**Formal Specification:**
```
FUNCTION isBugCondition(input)
  INPUT: input of type NotificationRecord {title: string, message: string}
  OUTPUT: boolean
  
  RETURN (containsNonASCII(input.title) OR containsNonASCII(input.message))
         AND displayedIncorrectly(input)
         
FUNCTION containsNonASCII(text)
  FOR each character c in text DO
    IF codePoint(c) > 127 THEN
      RETURN true
    END IF
  END FOR
  RETURN false
END FUNCTION
```

### Examples

- **Example 1**: Notification title "Café Delivery" displays as "CafÃ© Delivery" (é → Ã©)
- **Example 2**: Message "Price increased by ₱500" displays as "Price increased by â‚±500" (₱ → â‚±)
- **Example 3**: Title "Empleado señalado" displays as "Empleado seÃ±alado" (ñ → Ã±)
- **Edge Case**: Emoji notification "🔔 New alert" displays as "� New alert" or "🔔 New alert" (replacement character)

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- ASCII-only notifications (A-Z, a-z, 0-9, basic punctuation) must continue to display correctly
- HTML special characters (<, >, &, ", ') must continue to be properly escaped using htmlspecialchars() to prevent XSS attacks
- Notification panel layout, styling, colors, icons, and user interactions must remain unchanged
- Click-to-mark-read functionality must continue to work correctly
- Timestamp formatting and display must remain unchanged
- Notification badge count and visual indicators must continue to function correctly

**Scope:**
All inputs that do NOT contain UTF-8 characters beyond ASCII should be completely unaffected by this fix. This includes:
- ASCII-only notification text
- Empty notifications or NULL values
- Numeric-only content
- Basic punctuation and symbols

## Hypothesized Root Cause

Based on the bug description and code analysis, the most likely issues are:

1. **Missing PHP Internal Encoding Declaration**: PHP's internal string handling may not be set to UTF-8, causing string functions to treat multi-byte UTF-8 characters as separate bytes
   - Default internal encoding may be ASCII or ISO-8859-1
   - String manipulation functions may corrupt multi-byte sequences

2. **Missing HTTP Content-Type Header**: The HTTP response may lack explicit UTF-8 charset declaration
   - Browser may guess wrong encoding based on heuristics
   - Even with HTML meta tag, HTTP header takes precedence

3. **PDO Connection Charset Not Applied**: Despite `charset=utf8mb4` in DSN, the connection may not be using UTF-8 for data transfer
   - `SET NAMES utf8mb4` may not be executed automatically
   - Character set conversion may occur at connection level

4. **htmlspecialchars() Encoding Parameter**: The function may be using wrong encoding (default is ISO-8859-1 in some PHP versions)
   - Without explicit UTF-8 parameter, it treats input as single-byte encoding
   - Multi-byte sequences get corrupted

## Correctness Properties

Property 1: Bug Condition - UTF-8 Characters Display Correctly

_For any_ notification record where the title or message contains non-ASCII UTF-8 characters (code points > 127), the fixed rendering pipeline SHALL display those characters correctly in the browser without garbling, mojibake, or replacement characters.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4**

Property 2: Preservation - ASCII and Security Behavior Unchanged

_For any_ notification record where the title and message contain ONLY ASCII characters (code points ≤ 127) OR contain HTML special characters, the fixed rendering pipeline SHALL produce exactly the same display output as the original code, preserving XSS protection via htmlspecialchars() and maintaining all existing visual and interactive behavior.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5**

## Fix Implementation

### Changes Required

Assuming our root cause analysis is correct, the fix involves adding explicit UTF-8 encoding declarations at three critical points in the data pipeline:

**File**: `partials/header.php`

**Function/Section**: Notification rendering section (lines 1-100 and 4260-4310)

**Specific Changes**:

1. **Add HTTP Content-Type Header with UTF-8**: Near the top of `partials/header.php` after existing cache-control headers
   - Add: `header('Content-Type: text/html; charset=UTF-8');`
   - This ensures the browser interprets the response as UTF-8 regardless of HTML meta tag

2. **Set PHP Internal Encoding to UTF-8**: After the HTTP headers
   - Add: `mb_internal_encoding('UTF-8');` (if mbstring extension is available)
   - This ensures PHP string functions treat text as UTF-8 multi-byte sequences

3. **Verify PDO Connection Uses UTF-8**: In `public/db_connect.php` (already has `charset=utf8mb4`)
   - Verify the PDO connection attributes are correct (already set)
   - Optionally add explicit `$pdo->exec("SET NAMES utf8mb4");` for older MySQL versions
   - Ensure `PDO::MYSQL_ATTR_INIT_COMMAND` is not overriding charset

4. **Add UTF-8 Parameter to htmlspecialchars()**: In notification rendering loop at line 4290
   - Change: `htmlspecialchars($hn['title'] ?? 'Notification')`
   - To: `htmlspecialchars($hn['title'] ?? 'Notification', ENT_QUOTES, 'UTF-8')`
   - Change: `htmlspecialchars($hn['message'] ?? '')`
   - To: `htmlspecialchars($hn['message'] ?? '', ENT_QUOTES, 'UTF-8')`
   - This ensures htmlspecialchars treats input as UTF-8 multi-byte

5. **Verify HTML Meta Charset**: Confirm `<meta charset="utf-8">` exists in HTML head
   - Should already be present
   - Acts as fallback if HTTP header is missing

## Testing Strategy

### Validation Approach

The testing strategy follows a two-phase approach: first, surface counterexamples that demonstrate the bug on unfixed code by creating notifications with UTF-8 characters and observing garbled output, then verify the fix works correctly and preserves existing behavior for ASCII-only notifications and HTML security escaping.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples that demonstrate the bug BEFORE implementing the fix. Confirm or refute the root cause analysis by observing how UTF-8 characters are corrupted at each stage of the pipeline.

**Test Plan**: Create test notifications with various UTF-8 characters (accented letters, symbols, emoji) directly in the database, then load the notifications panel and capture the rendered HTML. Run these tests on the UNFIXED code to observe failures and understand where in the pipeline encoding breaks.

**Test Cases**:
1. **Accented Letters Test**: Insert notification with title "Café Delivery" and message "Naïve résumé" (will show garbled text on unfixed code)
2. **Currency Symbol Test**: Insert notification with message "Price ₱500 increased" (will show garbled ₱ symbol on unfixed code)
3. **Spanish Characters Test**: Insert notification with title "Señor empleado año" (will show garbled ñ on unfixed code)
4. **Emoji Test**: Insert notification with title "🔔 Alert 📢 Notice" (may show replacement characters � on unfixed code)
5. **Mixed Content Test**: Insert notification with HTML chars and UTF-8: "Café's price < ₱100 & ready" (will show garbled é and ₱ on unfixed code)

**Expected Counterexamples**:
- UTF-8 multi-byte characters display as multiple garbled characters (mojibake pattern)
- Possible causes: htmlspecialchars() treating UTF-8 as ISO-8859-1, missing Content-Type header, wrong PHP internal encoding

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds (notifications with UTF-8 characters), the fixed rendering pipeline produces correct display output.

**Pseudocode:**
```
FOR ALL notification WHERE containsNonASCII(notification.title) OR containsNonASCII(notification.message) DO
  rendered_html := renderNotificationPanel_fixed(notification)
  ASSERT rendered_html contains correct UTF-8 characters (no mojibake)
  ASSERT browser displays characters correctly when HTML is loaded
END FOR
```

**Test Approach**: After implementing the fix, create the same test notifications and verify they display correctly in the browser.

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold (ASCII-only notifications), the fixed rendering pipeline produces the same result as the original code.

**Pseudocode:**
```
FOR ALL notification WHERE NOT containsNonASCII(notification.title) AND NOT containsNonASCII(notification.message) DO
  ASSERT renderNotificationPanel_original(notification) = renderNotificationPanel_fixed(notification)
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many test cases automatically across the ASCII input domain
- It catches edge cases with HTML special characters that must remain escaped
- It provides strong guarantees that XSS protection is unchanged for all ASCII inputs

**Test Plan**: Observe behavior on UNFIXED code first for ASCII notifications and HTML escaping, then write property-based tests capturing that exact behavior.

**Test Cases**:
1. **ASCII-Only Preservation**: Verify "Delivery completed" displays identically before and after fix
2. **HTML Escaping Preservation**: Verify "<script>alert('xss')</script>" remains escaped as `&lt;script&gt;...` after fix
3. **Numeric Content Preservation**: Verify "Order #12345" displays identically
4. **Empty/NULL Preservation**: Verify empty title/message handles correctly
5. **Timestamp Display Preservation**: Verify "2h ago", "Just now" formatting unchanged
6. **UI Interaction Preservation**: Verify click-to-mark-read, badge count updates work correctly

### Unit Tests

- Test notification rendering with various UTF-8 character sets (Latin-1 supplement, Greek, Cyrillic, CJK, emoji)
- Test edge cases: very long UTF-8 strings, mixed ASCII+UTF-8, NULL values
- Test htmlspecialchars() with UTF-8 parameter correctly escapes HTML special chars while preserving UTF-8
- Test that HTTP Content-Type header is set correctly in response

### Property-Based Tests

- Generate random ASCII-only notifications and verify identical output before/after fix
- Generate random HTML special character combinations and verify XSS protection preserved
- Generate random UTF-8 character combinations and verify all display correctly after fix
- Test across many notification records to ensure no edge cases break encoding

### Integration Tests

- Test full notification flow: create notification in DB → fetch via PDO → render in header → display in browser
- Test notification panel interactions (scroll, click, mark as read) work correctly with UTF-8 content
- Test that badge count updates correctly regardless of character encoding in notification text
- Test switching between pages and verifying UTF-8 notifications persist correctly
