# Requirements Document

## Introduction

The Staff Customer Sidebar feature expands the existing flat "Customers" link in `includes/staff_sidebar.php` into a proper sub-menu with four direct-navigation items: Encode Customer Details, Update Customer Details, Transaction Linkage, and Outstanding Balances. This mirrors the sub-menu pattern already used by "Fuel Management" and "Merchandise" in the same sidebar.

Additionally, the Outstanding Balances tab in `public/customers.php` — currently restricted to manager-and-above roles — must be made accessible to staff roles as a read-only view. Transaction Linkage must display both Job Order and Merchandise transactions linked to a customer.

The feature is purely navigational and access-control in scope: no new pages are created, no new database tables are required, and no existing business logic is changed beyond the role gate on the Outstanding Balances tab.

## Glossary

- **Sidebar**: The `includes/staff_sidebar.php` PHP array-driven navigation component rendered on every staff page.
- **Sub-menu**: A collapsible list of child links nested under a parent sidebar item, following the pattern used by `fuel_management` and `inventory` entries.
- **Customers Page**: `public/customers.php` — the single page that hosts all customer-related tabs.
- **Tab**: A switchable content panel within `customers.php`, activated via the `switchTab()` JavaScript function.
- **Tab URL**: A URL of the form `customers.php?tab={tab_name}` that causes `customers.php` to activate the named tab on load.
- **Staff Role**: Any authenticated user whose `role_key` resolves to `staff`, `cashier`, or `pump_attendant`.
- **Manager Role**: Any authenticated user whose `role_key` resolves to `manager`, `admin`, or `superadmin`.
- **Outstanding Balances Tab**: The `balances` tab in `customers.php` that displays Customer ID, Name, Balance Amount, and Due Date.
- **Transaction Linkage Tab**: The `linkage` tab in `customers.php` that displays Job Orders and Merchandise transactions linked to a customer.
- **RBAC Menu**: `partials/rbac_menu.php`, which already defines `customer_encode`, `customer_update`, `customer_balances`, and `customer_linkage` sub-items under the `customers` parent.
- **Customer_ID**: The auto-incremented primary key of a record in the `customers` table, displayed as a numeric identifier.

---

## Requirements

### Requirement 1: Customers Sub-Menu in Staff Sidebar

**User Story:** As a staff member, I want the Customers section of the sidebar to show direct links to each customer management function, so that I can navigate to the correct tab without first landing on the default page and manually switching tabs.

#### Acceptance Criteria

1. THE Sidebar SHALL render a collapsible sub-menu under the "Customers" parent item containing exactly four child links: "Encode Customer Details", "Update Customer Details", "Transaction Linkage", and "Outstanding Balances".
2. WHEN a staff member clicks the "Customers" parent item, THE Sidebar SHALL toggle the sub-menu open or closed using the same `toggleSubmenu()` mechanism used by "Fuel Management" and "Merchandise".
3. THE Sidebar SHALL set the URL of the "Encode Customer Details" sub-item to `customers.php?tab=encode`.
4. THE Sidebar SHALL set the URL of the "Update Customer Details" sub-item to `customers.php?tab=update`.
5. THE Sidebar SHALL set the URL of the "Transaction Linkage" sub-item to `customers.php?tab=linkage`.
6. THE Sidebar SHALL set the URL of the "Outstanding Balances" sub-item to `customers.php?tab=balances`.
7. THE Sidebar SHALL assign a distinct Font Awesome icon to each sub-item: `fas fa-user-plus` for Encode, `fas fa-user-edit` for Update, `fas fa-link` for Transaction Linkage, and `fas fa-wallet` for Outstanding Balances.
8. WHEN the current page is `customers`, THE Sidebar SHALL apply the `active` CSS class to the "Customers" parent `<li>` element.

---

### Requirement 2: Tab Activation via URL Query Parameter

**User Story:** As a staff member, I want clicking a sidebar sub-item to open `customers.php` with the correct tab already active, so that I do not have to click a second time after the page loads.

#### Acceptance Criteria

1. WHEN `customers.php` is loaded with a `tab` query parameter, THE Customers_Page SHALL activate the tab whose identifier matches the value of the `tab` parameter.
2. WHEN `customers.php` is loaded without a `tab` query parameter, THE Customers_Page SHALL activate the `encode` tab by default, preserving existing behaviour.
3. IF the value of the `tab` query parameter does not match any known tab identifier, THEN THE Customers_Page SHALL activate the `encode` tab as a fallback.
4. THE Customers_Page SHALL pass the resolved active tab name to the `switchTab()` JavaScript function on page load so that the correct tab panel receives the `active` CSS class and all others are hidden.

---

### Requirement 3: Outstanding Balances Read-Only Access for Staff

**User Story:** As a staff member, I want to view the Outstanding Balances tab, so that I can see which customers have unpaid balances without needing manager-level access.

#### Acceptance Criteria

1. WHEN a user with a Staff Role navigates to `customers.php?tab=balances`, THE Customers_Page SHALL display the Outstanding Balances tab content.
2. THE Customers_Page SHALL render the Outstanding Balances tab button in the tab bar for Staff Role users.
3. WHILE a Staff Role user is viewing the Outstanding Balances tab, THE Customers_Page SHALL display each customer record with the following fields: Customer ID, Customer Name, Balance Amount, and Due Date.
4. WHILE a Staff Role user is viewing the Outstanding Balances tab, THE Customers_Page SHALL render the tab in read-only mode with no payment-recording form or balance-editing controls visible.
5. WHILE a Manager Role user is viewing the Outstanding Balances tab, THE Customers_Page SHALL continue to display the full payment-recording form and balance-editing controls as currently implemented.
6. THE Customers_Page SHALL fetch Outstanding Balances data from the `accounts_receivable` table filtered by `station_id` and `status = 'Pending'` for Staff Role users, joining the `customers` table to retrieve Customer ID, Name, Balance Amount, and Due Date.

---

### Requirement 4: Transaction Linkage Displays Job Orders and Merchandise

**User Story:** As a staff member, I want the Transaction Linkage tab to show both Job Orders and Merchandise transactions linked to a customer, so that I have a complete view of a customer's activity.

#### Acceptance Criteria

1. WHEN a user selects a customer in the Transaction Linkage tab, THE Customers_Page SHALL display all Job Order records linked to that customer, showing at minimum: Job Order ID, service description, date, and status.
2. WHEN a user selects a customer in the Transaction Linkage tab, THE Customers_Page SHALL display all Merchandise transaction records linked to that customer, showing at minimum: transaction ID, item description, quantity, amount, and date.
3. THE Customers_Page SHALL present Job Orders and Merchandise transactions in two clearly labelled sections within the Transaction Linkage tab.
4. IF no Job Orders exist for the selected customer, THEN THE Customers_Page SHALL display a "No job orders found" message in the Job Orders section.
5. IF no Merchandise transactions exist for the selected customer, THEN THE Customers_Page SHALL display a "No merchandise transactions found" message in the Merchandise section.
6. THE Customers_Page SHALL filter Transaction Linkage results by `station_id` to ensure staff only see transactions belonging to their own station.

---

### Requirement 5: Sidebar Active State for Customer Sub-Pages

**User Story:** As a staff member, I want the sidebar to visually indicate which customer section I am currently viewing, so that I can orient myself within the application.

#### Acceptance Criteria

1. WHEN `customers.php` is the current page, THE Sidebar SHALL keep the "Customers" parent item highlighted with the `active` CSS class.
2. WHEN `customers.php` is loaded with `?tab=encode`, THE Sidebar SHALL apply a highlighted style to the "Encode Customer Details" sub-item.
3. WHEN `customers.php` is loaded with `?tab=update`, THE Sidebar SHALL apply a highlighted style to the "Update Customer Details" sub-item.
4. WHEN `customers.php` is loaded with `?tab=linkage`, THE Sidebar SHALL apply a highlighted style to the "Transaction Linkage" sub-item.
5. WHEN `customers.php` is loaded with `?tab=balances`, THE Sidebar SHALL apply a highlighted style to the "Outstanding Balances" sub-item.
6. THE Sidebar SHALL automatically expand the Customers sub-menu when the current page is `customers`, so the active sub-item is visible without requiring a manual click.
