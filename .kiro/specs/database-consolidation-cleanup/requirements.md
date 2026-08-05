# Requirements Document

## Introduction

This feature consolidates the Petron POS system from using the legacy database name `group31petron_system_official4` to the standardized database name `petron_pos_db_secure`. The system currently points to the correct database in configuration, but legacy references remain in file paths, URLs, SQL dump data, and documentation. This consolidation ensures data integrity, eliminates confusion, and provides a single source of truth for the database.

## Glossary

- **System**: The Petron POS application (PHP codebase)
- **Legacy_Database**: The old database name `group31petron_system_official4`
- **Target_Database**: The new standardized database name `petron_pos_db_secure`
- **Database_Connection_Config**: The file `public/db_connect.php` that defines database connection parameters
- **SQL_Dump**: A file containing SQL statements representing database structure and data
- **File_Path_Reference**: Any hardcoded file path string in the codebase containing the Legacy_Database name
- **URL_Reference**: Any URL string in the codebase containing the Legacy_Database name
- **Backup_Record**: A row in the `system_backups` table containing file path information
- **Migration_Script**: A PHP script or SQL file that performs data migration operations
- **Data_Validator**: A component that verifies data integrity after migration

## Requirements

### Requirement 1: Database Connection Verification

**User Story:** As a system administrator, I want to verify that the database connection is correctly configured, so that I know the current connection state before migration.

#### Acceptance Criteria

1. THE System SHALL verify that Database_Connection_Config points to Target_Database
2. THE System SHALL verify that Target_Database exists and is accessible
3. WHEN Legacy_Database exists, THE System SHALL check if it contains any tables
4. WHEN Legacy_Database contains tables, THE System SHALL list all table names for migration review

### Requirement 2: Data Migration from Legacy Database

**User Story:** As a system administrator, I want to migrate all data from the legacy database to the target database, so that no data is lost during consolidation.

#### Acceptance Criteria

1. IF Legacy_Database exists and contains tables, THEN THE Migration_Script SHALL export all table structures and data
2. THE Migration_Script SHALL import all Legacy_Database table structures into Target_Database without conflicts
3. THE Migration_Script SHALL import all Legacy_Database table data into Target_Database without data loss
4. WHEN a table exists in both databases, THE Migration_Script SHALL merge data without creating duplicates
5. IF a migration error occurs, THEN THE Migration_Script SHALL log the error details and halt execution
6. THE Data_Validator SHALL verify that all rows from Legacy_Database tables exist in Target_Database after migration

### Requirement 3: File Path Reference Updates

**User Story:** As a developer, I want all hardcoded file paths updated to remove legacy database references, so that the codebase reflects the correct project structure.

#### Acceptance Criteria

1. THE System SHALL identify all File_Path_Reference instances containing Legacy_Database name
2. THE System SHALL replace all File_Path_Reference instances with paths using the current project directory structure
3. THE System SHALL preserve relative path structures when updating File_Path_Reference instances
4. THE System SHALL update File_Path_Reference instances in PHP files, configuration files, and documentation files

### Requirement 4: URL Reference Updates

**User Story:** As a developer, I want all hardcoded URLs updated to remove legacy database references, so that links and redirects work correctly.

#### Acceptance Criteria

1. THE System SHALL identify all URL_Reference instances containing Legacy_Database name
2. THE System SHALL replace all URL_Reference instances with URLs using the current project directory name
3. THE System SHALL preserve URL structures when updating URL_Reference instances
4. THE System SHALL update URL_Reference instances in PHP files, JavaScript files, email templates, and configuration files

### Requirement 5: SQL Dump Cleanup

**User Story:** As a system administrator, I want SQL dump files cleaned of legacy database references, so that backup restoration uses the correct database name.

#### Acceptance Criteria

1. THE System SHALL identify all SQL_Dump files containing Legacy_Database name
2. THE System SHALL update CREATE DATABASE statements in SQL_Dump files to use Target_Database name
3. THE System SHALL update USE DATABASE statements in SQL_Dump files to use Target_Database name
4. THE System SHALL update Backup_Record file paths in SQL_Dump data to use current project structure
5. THE System SHALL preserve all SQL statement syntax when updating SQL_Dump files

### Requirement 6: Backup Records Cleanup

**User Story:** As a system administrator, I want backup file path records updated in the database, so that the system can locate backup files correctly.

#### Acceptance Criteria

1. THE System SHALL identify all Backup_Record rows containing Legacy_Database name in file paths
2. THE System SHALL update Backup_Record file paths to use the current project directory structure
3. THE System SHALL preserve backup metadata when updating Backup_Record rows
4. THE System SHALL verify that updated Backup_Record file paths point to existing files or mark records appropriately

### Requirement 7: Documentation Updates

**User Story:** As a developer, I want all documentation updated to remove legacy database references, so that documentation accurately reflects the current system.

#### Acceptance Criteria

1. THE System SHALL identify all documentation files (markdown, text files) containing Legacy_Database name
2. THE System SHALL update documentation references to use Target_Database name or current project structure
3. THE System SHALL preserve documentation formatting when updating references
4. THE System SHALL create a migration changelog documenting all changes performed

### Requirement 8: Post-Migration Validation

**User Story:** As a system administrator, I want comprehensive validation after migration, so that I can confirm the system works correctly with consolidated database.

#### Acceptance Criteria

1. THE Data_Validator SHALL verify that Target_Database contains all expected tables
2. THE Data_Validator SHALL verify that all tables contain expected row counts
3. THE Data_Validator SHALL verify that Database_Connection_Config successfully connects to Target_Database
4. THE Data_Validator SHALL verify that no PHP files contain Legacy_Database references except in comments
5. WHEN validation passes, THE System SHALL generate a success report with migration statistics
6. IF validation fails, THEN THE System SHALL generate an error report with specific failure details

### Requirement 9: Legacy Database Cleanup

**User Story:** As a system administrator, I want the legacy database optionally removed after successful migration, so that only one database exists in the system.

#### Acceptance Criteria

1. WHEN migration and validation complete successfully, THE System SHALL prompt administrator for Legacy_Database removal confirmation
2. IF administrator confirms removal, THEN THE System SHALL drop Legacy_Database
3. IF administrator declines removal, THEN THE System SHALL preserve Legacy_Database and log the decision
4. WHEN Legacy_Database is dropped, THE System SHALL log the removal with timestamp and administrator identifier

### Requirement 10: Rollback Capability

**User Story:** As a system administrator, I want the ability to rollback the migration if issues are discovered, so that the system can be restored to its previous state.

#### Acceptance Criteria

1. BEFORE starting migration, THE Migration_Script SHALL create a complete backup of Target_Database
2. THE Migration_Script SHALL store the backup file path in a migration metadata file
3. WHEN rollback is requested, THE Migration_Script SHALL restore Target_Database from the pre-migration backup
4. WHEN rollback is requested, THE Migration_Script SHALL restore all modified files to their original state from backup
5. THE Migration_Script SHALL log all rollback operations with timestamps and affected resources
