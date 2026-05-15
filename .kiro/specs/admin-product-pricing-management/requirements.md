# Requirements Document

## Introduction

This feature defines the Admin Product & Pricing Management module for the Petron Station Management System. The Admin role is station-scoped — each Admin is assigned to exactly one franchise branch and may only view product and pricing data for that station. The module covers both Fuel products (Diesel, Gasoline, Kerosene, etc.) and Merchandise products (Air Fresheners, Lubricants, Car Care, Snacks, Beverages, etc.).

The Admin's access is strictly **read-only**: the Admin cannot create, edit, or delete any product or pricing record. Pricing and cost changes are performed exclusively by the Manager. The Admin's responsibilities in this module are to view current product data, validate that pricing and cost values set by the Manager are correct, review the full audit trail of changes, and generate pricing reports for their station.

The current page (`public/admin_set_prices.php`) incorrectly allows the Admin to submit price updates via POST. This feature replaces that behavior with a fully read-only, station-scoped view.

---

## Glossary

- **System**: The Petron Station Management System (PHP web application).
- **Admin**: A user with the `admin` role, assigned to exactly one station. Has read-only access to product and pricing data for their Assigned Station.
- **Manager**: A user with the `manager` role, assigned to a station. The only role authorized to create or modify product pricing and cost values.
- **SuperAdmin**: A user with the `superadmin` role. Has global access across all stations and is not subject to station-scoped restrictions.
- **Assigned Station**: The station recorded in the `station_id` column of the currently authenticated Admin's `users` row.
- **Fuel Product**: A product with `category = Fuel` (e.g., Diesel, Gasoline, Kerosene). Measured and priced per liter.
- **Merchandise Product**: A product with `category = Merchandise` and a specific sub-category (e.g., Oils/Lubes/Grease, Car Accessories, Snacks/Beverages). Measured and priced per unit.
- **Product_Catalog_Page**: The new read-only admin page that replaces `public/admin_set_prices.php` for the Admin role.
- **Audit_Trail**: The chronological log of changes to a product's cost, price, or stock level, including the old value, new value, Manager ID, and timestamp. Stored via `log_activity` and the `price_change_logs` / `fuel_adjustments` tables.
- **Pricing_Report**: A generated document (PDF or Excel/CSV) summarizing current product pricing and cost data for the Admin's Assigned Station.
- **Stock Level**: For Fuel Products, the current volume in liters from `fuel_inventory.current_stock`. For Merchandise Products, the current quantity from `station_inventory.stock_level` or `inventory_products.stock`.
- **Status**: The active/inactive state of a product record.
- **Cost**: The base purchase cost per unit (per liter for Fuel, per unit for Merchandise).
- **Selling Price**: The retail price per unit charged to customers.
- **SKU**: Stock Keeping Unit — a unique identifier for a Merchandise product.
- **Product_ID**: The unique database identifier for a product record.

---

## Requirements

### Requirement 1: Station-Scoped Read-Only Product Listing

**User Story:** As an Admin, I want to view all Fuel and Merchandise products assigned to my station, so that I can monitor what is available at my franchise branch without being able to modify any records.

#### Acceptance Criteria

1. WHEN an Admin loads the Product_Catalog_Page, THE System SHALL query and display only products whose `station_id` matches the Admin's Assigned Station.
2. WHEN an Admin loads the Product_Catalog_Page, THE System SHALL display both Fuel Products and Merchandise Products in separate, clearly labeled sections.
3. THE Product_Catalog_Page SHALL display the following fields for each Fuel Product: Product ID, Product Name, Category (Fuel), Cost (₱/liter), Selling Price (₱/liter), Stock Level (liters), and Status (Active/Inactive).
4. THE Product_Catalog_Page SHALL display the following fields for each Merchandise Product: Product ID, Product Name, SKU, Category (Merchandise sub-category), Cost (₱/unit), Selling Price (₱/unit), Stock Level (quantity), and Status (Active/Inactive).
5. WHILE the authenticated user has the `admin` role, THE System SHALL render all product fields as read-only display values with no editable inputs, no Save buttons, and no form submission controls.
6. IF an Admin submits an HTTP POST request to the Product_Catalog_Page, THEN THE System SHALL reject the request and return an HTTP 403 response with the message: "Access denied: Admins have read-only access to product pricing."
7. IF no products are found for the Admin's Assigned Station, THEN THE Product_Catalog_Page SHALL display the message: "No products found for your station."

---

### Requirement 2: Fuel Product Detail View

**User Story:** As an Admin, I want to see the complete details of each Fuel product at my station, so that I can verify that fuel types are correctly configured and priced.

#### Acceptance Criteria

1. WHEN an Admin views the Fuel Products section, THE System SHALL display one row per active fuel type (e.g., Diesel, Gasoline, Kerosene) registered in `fuel_inventory` for the Admin's Assigned Station.
2. THE System SHALL display the current `price_per_liter` value from `fuel_inventory` as the Selling Price for each Fuel Product.
3. THE System SHALL display the current `current_stock` value from `fuel_inventory` as the Stock Level (in liters) for each Fuel Product.
4. THE System SHALL display the fuel type status (Active/Inactive) derived from the `status` column of `fuel_inventory`.
5. WHEN the Stock Level of a Fuel Product is at or below the `critical_level` value in `fuel_inventory`, THE System SHALL display a visual low-stock indicator (e.g., a colored badge) next to the Stock Level value.
6. IF a Fuel Product has a Selling Price of ₱0.00 or NULL, THEN THE System SHALL display a "No Price Set" badge next to that product's Selling Price field.

---

### Requirement 3: Merchandise Product Detail View

**User Story:** As an Admin, I want to see the complete details of each Merchandise product at my station, so that I can verify that merchandise is correctly categorized and priced.

#### Acceptance Criteria

1. WHEN an Admin views the Merchandise Products section, THE System SHALL display all merchandise records from `inventory_products` where `category NOT IN ('Fuel')` for the Admin's Assigned Station.
2. THE System SHALL group Merchandise Products by their sub-category (e.g., Oils/Lubes/Grease, Car Accessories, Brake System, Tire, Maintenance, Oil/Fuel Filters, Others/Snacks/Drinks) with a visible category header row.
3. THE System SHALL display the `unit_cost` value as the Cost and the `unit_price` value as the Selling Price for each Merchandise Product.
4. THE System SHALL display the `stock` value from `inventory_products` as the Stock Level for each Merchandise Product.
5. WHEN the Stock Level of a Merchandise Product is 0 or below, THE System SHALL display an "Out of Stock" status indicator.
6. WHEN the Stock Level of a Merchandise Product is above 0 but at or below the reorder level (default: 10 units), THE System SHALL display a "Low Stock" status indicator.
7. WHEN the Stock Level of a Merchandise Product is above the reorder level, THE System SHALL display an "Available" status indicator.
8. IF a Merchandise Product has a Selling Price of ₱0.00 or NULL, THEN THE System SHALL display a "No Price Set" badge next to that product's Selling Price field.

---

### Requirement 4: Pricing Validation View

**User Story:** As an Admin, I want to identify products where the selling price is lower than the cost or where no price has been set, so that I can flag pricing errors to the Manager for correction.

#### Acceptance Criteria

1. WHEN an Admin loads the Product_Catalog_Page, THE System SHALL compute the margin (Selling Price minus Cost) for each product and display it alongside the price.
2. IF a product's Selling Price is less than its Cost, THEN THE System SHALL highlight that product row with a visual warning indicator (e.g., a red or amber row background or badge) labeled "Price Below Cost".
3. IF a product's Selling Price equals its Cost, THEN THE System SHALL display a "Zero Margin" indicator for that product.
4. IF a product has no Selling Price set (NULL or ₱0.00), THEN THE System SHALL display an "Unpriced" indicator for that product.
5. THE Product_Catalog_Page SHALL display a summary count of: total products, products with valid pricing, products with "Price Below Cost", products with "Zero Margin", and unpriced products.
6. WHILE the authenticated user has the `admin` role, THE System SHALL display a read-only "Pricing Guidelines" panel listing the station's pricing rules (selling price must be ≥ cost, prices must reflect current supplier invoices).

---

### Requirement 5: Audit Trail Access

**User Story:** As an Admin, I want to view the complete history of pricing and cost changes for each product at my station, so that I can verify that all changes were made by authorized personnel and are traceable.

#### Acceptance Criteria

1. WHEN an Admin selects a product on the Product_Catalog_Page, THE System SHALL display the Audit_Trail for that product showing all recorded changes.
2. THE System SHALL display the following fields for each Audit_Trail entry: change type (Cost change, Price change, Stock adjustment), old value, new value, Manager ID, Manager name, and timestamp.
3. THE System SHALL retrieve Fuel Product audit entries from the `fuel_adjustments` table filtered by `station_id` and `fuel_type_id`, ordered by `adjustment_date DESC`.
4. THE System SHALL retrieve Merchandise Product audit entries from the `price_change_logs` table (or equivalent activity log) filtered by `station_id` and `product_id`, ordered by timestamp descending.
5. WHEN no audit entries exist for a product, THE System SHALL display the message: "No change history available for this product."
6. WHILE the authenticated user has the `admin` role, THE System SHALL display audit trail entries as read-only records with no edit or delete controls.
7. THE System SHALL display a maximum of 50 most recent audit entries per product by default, with a "Load More" option to retrieve older entries.

---

### Requirement 6: Pricing Report Generation

**User Story:** As an Admin, I want to generate a pricing report for my station covering both Fuel and Merchandise products, so that I can share accurate pricing data with stakeholders or use it for compliance review.

#### Acceptance Criteria

1. WHEN an Admin requests a pricing report, THE System SHALL generate a report scoped exclusively to the Admin's Assigned Station.
2. THE System SHALL include the following data in the pricing report: station name, station address, report generation date and time, Admin name, and a complete product listing with Product Name, Category, Cost, Selling Price, Margin, Stock Level, and Status for each product.
3. THE System SHALL support exporting the pricing report in PDF format.
4. THE System SHALL support exporting the pricing report in Excel (CSV) format.
5. WHEN an Admin selects PDF export, THE System SHALL generate and trigger a browser download of a PDF file named `pricing_report_{station_id}_{YYYY-MM-DD}.pdf`.
6. WHEN an Admin selects Excel export, THE System SHALL generate and trigger a browser download of a CSV file named `pricing_report_{station_id}_{YYYY-MM-DD}.csv`.
7. THE System SHALL include both Fuel Products and Merchandise Products in the same report, separated into clearly labeled sections.
8. IF no products exist for the Admin's Assigned Station at the time of report generation, THEN THE System SHALL still generate the report with the station header and an empty product table with the note: "No products found for this station."

---

### Requirement 7: Product Search and Filter

**User Story:** As an Admin, I want to search and filter the product list by name, category, or status, so that I can quickly locate specific products without scrolling through the entire catalog.

#### Acceptance Criteria

1. THE Product_Catalog_Page SHALL provide a text search input that filters the displayed product list in real time as the Admin types, matching against Product Name and SKU fields.
2. THE Product_Catalog_Page SHALL provide a category filter dropdown that allows the Admin to show All, Fuel only, or a specific Merchandise sub-category.
3. THE Product_Catalog_Page SHALL provide a status filter that allows the Admin to show All, Active only, or Inactive only products.
4. WHEN a search term is entered, THE System SHALL highlight the matching text within the Product Name column of the filtered results.
5. WHEN filters are applied, THE System SHALL update the summary count (Requirement 4, Criterion 5) to reflect only the currently visible filtered products.
6. WHEN the Admin clears all filters, THE System SHALL restore the full product listing for the Assigned Station.

---

### Requirement 8: Access Control Enforcement

**User Story:** As a system operator, I want all product and pricing data access by the Admin to be strictly limited to their Assigned Station and to be read-only, so that no Admin can view or affect data from another station.

#### Acceptance Criteria

1. WHEN a user with a role other than `admin` or `superadmin` attempts to load the Product_Catalog_Page, THE System SHALL redirect the user to `dashboard.php`.
2. IF an Admin submits any HTTP request (GET or POST) that includes a `station_id` parameter different from the Admin's Assigned Station, THEN THE System SHALL ignore the submitted `station_id` and use the Admin's Assigned Station for all queries.
3. WHILE the authenticated user has the `admin` role, THE System SHALL not render any form input, button, or link that would allow modification of product data, pricing, cost, or stock levels.
4. IF a session is not authenticated, THEN THE System SHALL redirect the request to the login page before serving any product data.
5. THE System SHALL enforce station-scoping at the database query level by always including `WHERE station_id = {Admin's Assigned Station}` in all product and audit trail queries, not only at the UI level.

---

### Requirement 9: Audit Logging of Admin View Actions

**User Story:** As a SuperAdmin, I want to know when an Admin views pricing data or generates a report, so that I have a complete record of who accessed sensitive pricing information and when.

#### Acceptance Criteria

1. WHEN an Admin successfully loads the Product_Catalog_Page, THE System SHALL write an audit log entry via `log_activity` recording: the Admin's user ID, the action type "View Product Pricing", the Assigned Station ID, and the timestamp.
2. WHEN an Admin successfully generates and downloads a pricing report, THE System SHALL write an audit log entry via `log_activity` recording: the Admin's user ID, the action type "Export Pricing Report", the export format (PDF or CSV), the Assigned Station ID, and the timestamp.
3. WHEN an Admin views the Audit_Trail for a specific product, THE System SHALL write an audit log entry via `log_activity` recording: the Admin's user ID, the action type "View Product Audit Trail", the Product ID, the Assigned Station ID, and the timestamp.
