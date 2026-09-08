# Bugfix Requirements Document

## Introduction

Users are experiencing garbled or malformed characters in notification text within the notifications panel. This affects special characters, diacritics, and non-ASCII text, rendering notifications unreadable. The bug occurs when notification messages containing UTF-8 encoded characters (such as accented letters, special symbols, or international characters) are displayed in the notifications dropdown panel at `partials/header.php` line 4290.

Despite the database connection using `charset=utf8mb4`, the HTML document declaring `<meta charset="utf-8" />`, and output being properly escaped with `htmlspecialchars()`, character encoding is still breaking. This suggests a potential mismatch in the encoding chain or missing encoding declarations at critical points.

---

## Bug Analysis

### 1. Current Behavior (Defect)

1.1 WHEN a notification message contains special characters (é, ñ, ü, etc.) THEN the system displays garbled characters (e.g., "CafÃ©" instead of "Café")

1.2 WHEN a notification message contains emoji or unicode symbols THEN the system displays replacement characters (�) or broken encoding

1.3 WHEN a notification title contains non-ASCII characters THEN the system displays mojibake (incorrect character mappings)

1.4 WHEN notifications are fetched from the database THEN character encoding may be lost or corrupted during the data retrieval and rendering pipeline

---

### 2. Expected Behavior (Correct)

2.1 WHEN a notification message contains special characters (é, ñ, ü, etc.) THEN the system SHALL display them correctly without garbling

2.2 WHEN a notification message contains emoji or unicode symbols THEN the system SHALL display them correctly as intended

2.3 WHEN a notification title contains non-ASCII characters THEN the system SHALL display them correctly with proper UTF-8 encoding

2.4 WHEN notifications are fetched from the database THEN the system SHALL preserve UTF-8 character encoding throughout the entire data pipeline from database to browser display

---

### 3. Unchanged Behavior (Regression Prevention)

3.1 WHEN a notification message contains only ASCII characters (A-Z, a-z, 0-9) THEN the system SHALL CONTINUE TO display them correctly as before

3.2 WHEN a notification contains HTML special characters (<, >, &, ", ') THEN the system SHALL CONTINUE TO properly escape them using htmlspecialchars() to prevent XSS attacks

3.3 WHEN notifications are displayed in the dropdown panel THEN the system SHALL CONTINUE TO maintain the same visual layout, styling, and user interaction behavior

3.4 WHEN users click on notifications to mark them as read THEN the system SHALL CONTINUE TO function correctly without affecting the encoding fix

3.5 WHEN the notification panel displays timestamps THEN the system SHALL CONTINUE TO format and display them correctly
