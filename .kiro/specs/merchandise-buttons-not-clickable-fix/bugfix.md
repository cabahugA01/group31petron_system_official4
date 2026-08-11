# Bugfix Requirements Document

## Introduction

The View, Req. Void, and Req. Adjust buttons in the merchandise transactions table (Merchandise History section at `staff_transactions_hub.php`) are not clickable and non-functional. Users cannot interact with these action buttons to view transaction details, request void operations, or request adjustments.

**Impact**: Staff cannot perform critical transaction operations including viewing details, requesting voids, or requesting adjustments for merchandise transactions.

**Location**: `public/staff_transactions_hub.php` - Merchandise History section, transaction table rows

**Root Cause**: The table row has an `onclick` event handler (`onclick="viewMerchandiseDetails(...)"`) that intercepts all click events on the row, preventing the buttons' click handlers from executing even though they call `event.stopPropagation()`.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN a user clicks the "View" button in the merchandise transactions table THEN the button does not respond and instead the row's `viewMerchandiseDetails` function is triggered

1.2 WHEN a user clicks the "Req. Void" button in the merchandise transactions table THEN the button does not respond and instead the row's `viewMerchandiseDetails` function is triggered

1.3 WHEN a user clicks the "Req. Adjust" button in the merchandise transactions table THEN the button does not respond and instead the row's `viewMerchandiseDetails` function is triggered

1.4 WHEN a user clicks the "Reprint" link in the merchandise transactions table THEN the link does not respond and instead the row's `viewMerchandiseDetails` function is triggered

### Expected Behavior (Correct)

2.1 WHEN a user clicks the "View" button in the merchandise transactions table THEN the system SHALL call `viewMerchandiseDetails()` function with the transaction ID and prevent the row click event from triggering

2.2 WHEN a user clicks the "Req. Void" button in the merchandise transactions table THEN the system SHALL open the transaction request modal with request type "Void" and prevent the row click event from triggering

2.3 WHEN a user clicks the "Req. Adjust" button in the merchandise transactions table THEN the system SHALL open the transaction request modal with request type "Adjustment" and prevent the row click event from triggering

2.4 WHEN a user clicks the "Reprint" link in the merchandise transactions table THEN the system SHALL open the receipt page in a new tab and prevent the row click event from triggering

2.5 WHEN a user clicks anywhere else on the table row (not on buttons) THEN the system SHALL call the row's `viewMerchandiseDetails()` function as intended

### Unchanged Behavior (Regression Prevention)

3.1 WHEN a user clicks on empty areas of the table row THEN the system SHALL CONTINUE TO trigger the `viewMerchandiseDetails()` function to show transaction details

3.2 WHEN the buttons are in a voided or cancelled transaction row THEN the system SHALL CONTINUE TO hide the Req. Void and Req. Adjust buttons as they are conditionally rendered

3.3 WHEN the transaction request modal is opened THEN the system SHALL CONTINUE TO function correctly with all its existing features (form submission, validation, API calls)

3.4 WHEN the `viewMerchandiseDetails()` function is called THEN the system SHALL CONTINUE TO display the transaction details modal correctly

3.5 WHEN event.stopPropagation() is called in button handlers THEN the system SHALL CONTINUE TO use this pattern for other similar button interactions throughout the application
