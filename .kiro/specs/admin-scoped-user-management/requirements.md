# Requirements Document

## Introduction

This feature enforces station-scoped authority for Admin users in the Petron Station Management System. Currently, the user management page (`app/master_data/users/users.php`) allows Admins to interact with the system but lacks strict enforcement of the one-Manager-per-station rule and a consistent email credential delivery flow for Manager and Staff accounts. This feature defines the complete, enforceable rules for what an Admin can and cannot do within User Management, ensuring data integrity across all stations.

## Glossary

- **System**: The Petron Station Management System (PHP web application).
- **Admin**: A user with the `admin` role, assigned to exactly one station. Manages Manager and Staff accounts within that station only.
- **Manager**: A user with the `manager` role, assigned to a station. Only one Manager account may exist per station at any time.
- **Staff**: A user with the `staff` role, assigned to a station. Multiple Staff accounts may exist per station.
- **SuperAdmin**: A user with the `superadmin` role. Has global access across all stations and is not subject to station-scoped restrictions.
- **Station**: A Petron fuel station entity stored in the `stations` table, identified by a unique `station_id`.
- **Assigned Station**: The station recorded in the `station_id` column of the currently authenticated Admin's `users` row.
- **Email**: The email address provided when creating a Manager or Staff account. Serves as the username for that account.
- **Auto-Generated Password**: A cryptographically random password produced by the System when the Admin leaves the password field empty during account creation. Must satisfy Auto-Generated Password Complexity.
- **Manual Password**: A password explicitly provided by the Admin during account creation. Must satisfy Manual Password Complexity.
- **Auto-Generated Password Complexity**: A cryptographically random password produced by the System must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, one digit, and one symbol from the allowed set: `_ . - ! @ #`. No other special characters are permitted.
- **Manual Password Complexity**: A password explicitly provided by the Admin during account creation must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, one digit, and one symbol from the allowed set: `_ . - ! @ #`. No other special characters are permitted.
- **Phone_Number**: An optional phone number associated with a Manager or Staff account, stored in the `phone_number` column of the `users` table. Not required for account creation.
- **Force Password Change Flag**: A boolean flag (`must_change_password`) stored on a user record that, when set to `true`, requires the user to set a new password upon their next successful login.
- **Credential Email**: The automated email sent to a newly created Manager or Staff account containing login credentials.
- **User_Management_Page**: The page at `app/master_data/users/users.php` that Admins use to manage users.
- **User_Operations_API**: The backend endpoint at `app/master_data/users/user_operations.php` that processes user management actions.
- **Email_Service**: The PHPMailer-based email sending component defined in `config/email_config.php`.

---

## Requirements

### Requirement 1: Station-Scoped User Listing

**User Story:** As an Admin, I want to see only the users belonging to my assigned station, so that I cannot accidentally view or modify accounts from other stations.

#### Acceptance Criteria

1. WHEN an Admin loads the User_Management_Page, THE System SHALL query and display only users whose `station_id` matches the Admin's Assigned Station.
2. WHEN an Admin loads the User_Management_Page, THE System SHALL exclude SuperAdmin accounts from the displayed user list.
3. WHILE the authenticated user has the `admin` role, THE System SHALL render the station field as a read-only display showing the Admin's Assigned Station name, not as an editable dropdown.
4. IF an Admin submits a request to list users for a `station_id` different from the Admin's Assigned Station, THEN THE User_Operations_API SHALL reject the request and return an error message: "Access denied: You can only view users in your assigned station."

---

### Requirement 2: Manager Account Creation with One-Per-Station Enforcement

**User Story:** As an Admin, I want to create a Manager account for my station, so that the station has a designated manager, while the system prevents duplicate Manager accounts.

#### Acceptance Criteria

1. WHEN an Admin submits a create-user request with role `manager`, THE System SHALL verify that no active Manager account exists for the Admin's Assigned Station before inserting the new record.
2. IF an active Manager account already exists for the Admin's Assigned Station, THEN THE User_Operations_API SHALL reject the request and return the error message: "Manager account already exists for this station."
3. WHEN an Admin creates a Manager account, THE System SHALL set the new user's `station_id` to the Admin's Assigned Station regardless of any `station_id` value submitted in the request payload.
4. WHEN an Admin creates a Manager account, THE System SHALL require the `email` field to be a valid RFC 5321-compliant email address.
5. IF the submitted email address already exists in the `users` table, THEN THE User_Operations_API SHALL reject the request and return the error message: "Email address is already in use."
6. WHEN an Admin creates a Manager account and the password field is empty, THE System SHALL auto-generate a password that satisfies the Auto-Generated Password Complexity.
7. WHEN an Admin creates a Manager account and the password field is not empty, THE System SHALL validate that the provided password satisfies the Manual Password Complexity before creating the account.
8. IF the provided Manual Password does not satisfy the Manual Password Complexity, THEN THE User_Operations_API SHALL reject the request and return the error message: "Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #)."
9. WHEN a Manager account is successfully created, THE Email_Service SHALL send a Credential Email to the Manager's email address within the same request lifecycle, regardless of whether the password was auto-generated or manually set.
10. IF the Email_Service fails to deliver the Credential Email, THEN THE System SHALL still complete the account creation and return a success response that includes a warning: "Account created but email delivery failed. Please share credentials manually."
11. WHEN a Manager account is successfully created and the Credential Email is sent, THE System SHALL display the confirmation message: "User created successfully. Credentials have been sent to [email]." where [email] is the Manager's email address.

---

### Requirement 3: Staff Account Creation

**User Story:** As an Admin, I want to create multiple Staff accounts for my station without a quantity limit, so that I can onboard as many staff members as needed.

#### Acceptance Criteria

1. WHEN an Admin submits a create-user request with role `staff`, THE System SHALL create the Staff account with `station_id` set to the Admin's Assigned Station.
2. THE System SHALL impose no maximum limit on the number of active Staff accounts per station.
3. WHEN an Admin creates a Staff account, THE System SHALL require the `email` field to be a valid RFC 5321-compliant email address.
4. IF the submitted email address already exists in the `users` table, THEN THE User_Operations_API SHALL reject the request and return the error message: "Email address is already in use."
5. WHEN an Admin creates a Staff account and the password field is empty, THE System SHALL auto-generate a password that satisfies the Auto-Generated Password Complexity.
6. WHEN an Admin creates a Staff account and the password field is not empty, THE System SHALL validate that the provided password satisfies the Manual Password Complexity before creating the account.
7. IF the provided Manual Password does not satisfy the Manual Password Complexity, THEN THE User_Operations_API SHALL reject the request and return the error message: "Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #)."
8. WHEN a Staff account is successfully created, THE Email_Service SHALL send a Credential Email to the Staff member's email address within the same request lifecycle, regardless of whether the password was auto-generated or manually set.
9. IF the Email_Service fails to deliver the Credential Email, THEN THE System SHALL still complete the account creation and return a success response that includes a warning: "Account created but email delivery failed. Please share credentials manually."
10. WHEN a Staff account is successfully created and the Credential Email is sent, THE System SHALL display the confirmation message: "User created successfully. Credentials have been sent to [email]." where [email] is the Staff member's email address.

---

### Requirement 4: Cross-Station Creation Block

**User Story:** As a system operator, I want the system to block any attempt by an Admin to create users for a different station, so that station data boundaries are never violated.

#### Acceptance Criteria

1. IF an Admin submits a create-user request containing a `station_id` that does not match the Admin's Assigned Station, THEN THE User_Operations_API SHALL override the submitted `station_id` with the Admin's Assigned Station and proceed with creation.
2. WHILE the authenticated user has the `admin` role, THE System SHALL not expose a station selection dropdown on the User_Management_Page.
3. IF an Admin submits a create-user request with role `admin` or `superadmin`, THEN THE User_Operations_API SHALL reject the request and return the error message: "Admins can only create Manager or Staff accounts."

---

### Requirement 5: Email as Username

**User Story:** As an Admin, I want the email address entered during account creation to serve as the username, so that users have a single, memorable credential to log in with.

#### Acceptance Criteria

1. WHEN an Admin creates a Manager or Staff account, THE System SHALL set the `username` field in the `users` table to the value of the submitted `email` field.
2. THE System SHALL enforce uniqueness of the `username` (email) value across all records in the `users` table.
3. IF the submitted email address is already stored as a `username` in the `users` table, THEN THE User_Operations_API SHALL reject the request and return the error message: "Email address is already in use."
4. WHEN the User_Management_Page renders the Add User form for an Admin, THE System SHALL display a single "Email Address" field and SHALL NOT display a separate "Username" field.
5. WHEN the User_Management_Page renders the Add User form for an Admin, THE System SHALL include exactly the following fields: Full Name, Email Address (serves as Username), Role (limited to Manager or Staff), and Phone Number.

---

### Requirement 6: Credential Email Delivery

**User Story:** As a newly created Manager or Staff member, I want to receive an email with my login credentials immediately upon account creation, so that I can access the system without needing to contact the Admin directly.

#### Acceptance Criteria

1. WHEN a Manager or Staff account is successfully created, THE Email_Service SHALL send an email to the account's email address with the subject line: "Petron Station Management – Account Credentials", regardless of whether the password was auto-generated or manually set.
2. THE Email_Service SHALL include all of the following in the email body: a greeting addressed to the recipient's full name, the name of the Assigned Station, the username (email address), the actual usable password in plain text, and a reminder that the user must change their password upon first login.
3. WHEN the password field is left empty during account creation, THE System SHALL generate the password before sending the Credential Email so that the email contains the actual usable password.
4. WHEN the Admin provides a Manual Password during account creation, THE System SHALL include that Manual Password in the Credential Email.
5. IF the `email` field is empty or contains an invalid email address, THEN THE User_Operations_API SHALL reject the create-user request before attempting to send any email.

---

### Requirement 7: Admin Cannot Manage Users Outside Assigned Station

**User Story:** As a system operator, I want every user management action performed by an Admin to be validated against the Admin's Assigned Station, so that no Admin can modify accounts belonging to another station.

#### Acceptance Criteria

1. WHEN an Admin submits an edit-user request, THE User_Operations_API SHALL verify that the target user's `station_id` matches the Admin's Assigned Station before applying any changes.
2. IF the target user's `station_id` does not match the Admin's Assigned Station, THEN THE User_Operations_API SHALL reject the request and return the error message: "Access denied: You can only manage users in your assigned station."
3. WHEN an Admin submits a reset-password request, THE User_Operations_API SHALL verify that the target user's `station_id` matches the Admin's Assigned Station before resetting the password.
4. WHEN an Admin submits a toggle-status request, THE User_Operations_API SHALL verify that the target user's `station_id` matches the Admin's Assigned Station before changing the status.
5. IF an Admin attempts to edit, reset the password of, or change the status of another Admin account, THEN THE User_Operations_API SHALL reject the request and return the error message: "Admins cannot manage other Admin accounts."

---

### Requirement 8: Audit Logging for Admin User Management Actions

**User Story:** As a SuperAdmin, I want every user management action performed by an Admin to be recorded in the audit log, so that I can review the history of account changes per station.

#### Acceptance Criteria

1. WHEN an Admin successfully creates a Manager or Staff account, THE System SHALL write an audit log entry recording: the acting Admin's user ID, the created user's ID, the role assigned, the Assigned Station ID, the timestamp, and whether the Credential Email was sent successfully.
2. WHEN an Admin successfully resets a user's password, THE System SHALL write an audit log entry recording: the acting Admin's user ID, the target user's ID, and the timestamp.
3. WHEN an Admin successfully changes a user's status, THE System SHALL write an audit log entry recording: the acting Admin's user ID, the target user's ID, the previous status, the new status, and the timestamp.
4. IF any user management action fails due to a station-scope violation, THE System SHALL write an audit log entry recording: the acting Admin's user ID, the attempted action, the target station ID, and the timestamp.

---

### Requirement 9: Force Password Change on First Login

**User Story:** As a system operator, I want newly created Manager and Staff accounts to be required to change their password upon their very first login, so that initial credentials issued by the Admin are replaced with a private password known only to the account holder.

#### Acceptance Criteria

1. WHEN a Manager or Staff account is successfully created, THE System SHALL set the `must_change_password` flag to `true` on the new user record.
2. WHEN a user with `must_change_password` set to `true` completes a successful login, THE System SHALL redirect the user to the Change Password page before granting access to any other page.
3. WHILE a user's `must_change_password` flag is `true`, THE System SHALL block navigation to any page other than the Change Password page.
4. WHEN a user successfully submits a new password on the Change Password page, THE System SHALL validate that the new password satisfies the Manual Password Complexity before saving it.
5. IF the submitted new password does not satisfy the Manual Password Complexity, THEN THE System SHALL reject the change and return the error message: "Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol (_ . - ! @ #)."
6. WHEN a user successfully saves a new password on the Change Password page, THE System SHALL set the `must_change_password` flag to `false` and redirect the user to their role-appropriate dashboard.
7. THE System SHALL NOT allow a user to reuse the initial credential password as their new password on the Change Password page.

---

### Requirement 10: Phone Number Field on Add User Form

**User Story:** As an Admin, I want to optionally record a phone number when creating a Manager or Staff account, so that contact information is available for station personnel.

#### Acceptance Criteria

1. WHEN the User_Management_Page renders the Add User form, THE System SHALL include a Phone Number field that is optional and does not block account creation when left empty.
2. WHEN an Admin submits a create-user request that includes a Phone Number value, THE System SHALL store the value in the `phone_number` column of the `users` table for the newly created account.
3. WHEN an Admin submits a create-user request without a Phone Number value, THE System SHALL create the account with the `phone_number` column set to `NULL`.
