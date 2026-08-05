# Implementation Plan: Database Consolidation Cleanup

## Overview

This implementation plan addresses the consolidation of the Petron POS system from the legacy database name `group31petron_system_official4` to the standardized name `petron_pos_db_secure`. The approach follows a phased strategy: backup creation, data migration (if needed), reference updates across the codebase, file system cleanup, and comprehensive validation.

## Tasks

- [ ] 1. Create backup infrastructure and pre-migration safety net
  - [ ] 1.1 Implement BackupManager class with database and file backup capabilities
    - Create `database/migration/BackupManager.php`
    - Implement `createDatabaseBackup()` to export current Target_Database state
    - Implement `createFileBackup()` to create snapshots of files before modification
    - Implement `restore()` to restore from backup on rollback
    - Implement `listBackups()` to list available backups with metadata
    - _Requirements: 10.1, 10.2_
  
  - [ ] 1.2 Create pre-migration backup of Target_Database and all files to be modified
    - Execute BackupManager to create full database dump with timestamp
    - Create file manifest of all PHP, JavaScript, SQL, and documentation files
    - Store backup metadata in `database/migration/backups/migration_metadata.json`
    - Log backup paths and verification checksums
    - _Requirements: 10.1, 10.2_

- [ ] 2. Checkpoint - Verify backups created successfully
  - Ensure all backups created, verify backup file integrity, ask the user if questions arise.

- [ ] 3. Implement database migration components
  - [ ] 3.1 Create DatabaseMigrator class for legacy database operations
    - Create `database/migration/DatabaseMigrator.php`
    - Implement `checkLegacyDatabase()` to detect if Legacy_Database exists and contains tables
    - Implement `exportLegacyData()` to create SQL dump of all tables and data
    - Implement `importToTarget()` to import data with conflict handling (IGNORE/UPDATE)
    - Implement `mergeTables()` for table-specific merge logic when tables exist in both databases
    - Add transaction safety with BEGIN/COMMIT/ROLLBACK wrappers
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_
  
  - [ ]* 3.2 Write unit tests for DatabaseMigrator
    - Test `checkLegacyDatabase()` with mocked database connections (exists vs. not exists)
    - Test `exportLegacyData()` with sample table structures
    - Test `importToTarget()` with scenarios: empty tables, duplicates, conflicts
    - Test merge logic for overlapping tables
    - Test error handling for connection failures
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ] 4. Execute data migration from Legacy_Database (if exists)
  - [ ] 4.1 Check for Legacy_Database and analyze contents
    - Run DatabaseMigrator `checkLegacyDatabase()` to determine if migration needed
    - Log Legacy_Database status (exists, table count, table list)
    - Generate pre-migration report with table sizes and row counts
    - _Requirements: 1.3, 1.4_
  
  - [ ] 4.2 Migrate data from Legacy_Database to Target_Database
    - IF Legacy_Database exists with data, run `exportLegacyData()` to create SQL dump
    - Run `importToTarget()` to import all tables and data into Target_Database
    - Handle duplicate detection and merging for overlapping tables
    - Log all migration operations with timestamps and affected tables
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ] 5. Implement validation components
  - [ ] 5.1 Create Validator class for data integrity verification
    - Create `database/migration/Validator.php`
    - Implement `validateSchema()` to verify all Legacy_Database tables exist in Target_Database
    - Implement `validateDataMigration()` to verify row counts match for each table
    - Implement `validateReferences()` to check for remaining legacy references in active code
    - Implement `generateReport()` to create comprehensive validation report
    - _Requirements: 2.6, 8.1, 8.2, 8.3, 8.4_
  
  - [ ]* 5.2 Write unit tests for Validator
    - Test schema validation with sample table structures
    - Test data validation with known row counts
    - Test reference validation with sample code files (should find legacy refs)
    - Test validation report generation format
    - _Requirements: 2.6, 8.1, 8.2, 8.3, 8.4_

- [ ] 6. Validate data migration success
  - [ ] 6.1 Run Validator to verify data migration integrity
    - Execute `validateSchema()` to confirm all tables migrated
    - Execute `validateDataMigration()` to confirm row counts match
    - Generate migration validation report
    - Log validation results with pass/fail status
    - _Requirements: 2.6, 8.1, 8.2_

- [ ] 7. Checkpoint - Ensure data migration validated
  - Ensure validation passed, review migration report, ask the user if questions arise.

- [ ] 8. Implement reference update components
  - [ ] 8.1 Create ReferenceUpdater class for codebase cleanup
    - Create `database/migration/ReferenceUpdater.php`
    - Implement `updateFilePaths()` to replace hardcoded file paths with relative paths using `__DIR__`
    - Implement `updateUrls()` to replace URLs containing Legacy_Database name with current project structure
    - Implement `updateSqlDumps()` to update CREATE/USE DATABASE statements in SQL files
    - Implement `updateBackupRecords()` to update file paths in `system_backups` table
    - Implement `scanFiles()` using RecursiveDirectoryIterator to find files with legacy references
    - Implement `replaceInFile()` to perform safe string replacements with streaming for large files
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3, 6.4_
  
  - [ ]* 8.2 Write unit tests for ReferenceUpdater
    - Test file path pattern matching and replacement (forward/back slashes)
    - Test URL pattern matching and replacement
    - Test that replacements preserve file functionality
    - Test edge cases: no matches, multiple matches on same line
    - Test file scanning with various extensions
    - _Requirements: 3.1, 3.2, 4.1, 4.2, 5.1, 5.2_

- [ ] 9. Update file path references across codebase
  - [ ] 9.1 Scan for and update hardcoded file paths
    - Run `scanFiles()` to identify all files containing 'group31petron_system_official4' in paths
    - Update patterns:
      - `C:/xampp/htdocs/group31petron_system_official4/` → `__DIR__ . '/../'`
      - `C:\\xampp\\htdocs\\group31petron_system_official4\\` → `__DIR__ . '/../'`
      - `c:/xampp/htdocs/group31petron_system_official4/` → `__DIR__ . '/../'`
    - Process files: PHP files, configuration files, scratch files, utility scripts
    - Log all file modifications with before/after line samples
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [ ] 10. Update URL references across codebase
  - [ ] 10.1 Scan for and update hardcoded URLs
    - Run `scanFiles()` to identify all files containing '/group31petron_system_official4/' in URLs
    - Update pattern: `/group31petron_system_official4/` → `/petron_pos/`
    - Process files: PHP files (partials/header.php, public/*.php), JavaScript files, email templates
    - Specifically update:
      - partials/header.php: base_path, dash_href, API paths
      - public/kpis.php: user management link
      - public/receipt_pdf.php, public/receipt.php, public/verify.php: logo paths
      - public/staff_record_delivery.php: print PO URL
      - public/admin_reset_password.php: back button URLs
      - config/email_config.php: login link
      - app/master_data/users/archived_users.php: back button URL
    - Log all URL updates with file paths
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [ ] 11. Update SQL dump files and backup records
  - [ ] 11.1 Update SQL dump files with correct database name
    - Scan for SQL dump files in `database/backups/` and other backup directories
    - Update CREATE DATABASE statements to use `petron_pos_db_secure`
    - Update USE DATABASE statements to use `petron_pos_db_secure`
    - Update file path references within SQL dump data
    - Use streaming approach for large SQL files to avoid memory issues
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_
  
  - [ ] 11.2 Update backup records in database
    - Query `system_backups` table for records containing 'group31petron_system_official4' in file paths
    - Update file path columns to use current project directory structure
    - Verify updated paths point to existing files or mark records as archived
    - Log all database record updates
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

- [ ] 12. Checkpoint - Ensure reference updates completed
  - Ensure all references updated, review update logs, ask the user if questions arise.

- [ ] 13. Implement file cleanup components
  - [ ] 13.1 Create FileCleanupManager class for temporary file removal
    - Create `database/migration/FileCleanupManager.php`
    - Implement `scanTemporaryFiles()` to identify temporary markdown and scratch files
    - Implement `removeFiles()` to safely delete files with permission checking
    - Implement `verifyRemoval()` to confirm files deleted successfully
    - Add space calculation to report freed disk space
    - _Requirements: 7.1, 7.2, 7.3_
  
  - [ ]* 13.2 Write unit tests for FileCleanupManager
    - Test file scanning and identification
    - Test safe file removal with permissions checking
    - Test handling of missing files (already deleted)
    - Test space calculation accuracy
    - _Requirements: 7.1, 7.2_

- [ ] 14. Remove obsolete and temporary files from system
  - [ ] 14.1 Remove temporary markdown documentation files from root directory
    - Remove files:
      - ACTIONS_COLUMN_VISIBILITY_FIX.md
      - FILTER_VERIFICATION_REPORT.md
      - HORIZONTAL_SCROLL_FIX_SUMMARY.md
      - SAVE_DRAFT_BUTTON_REMOVED.md
      - SERVICE_CATEGORIES_COMPLETE.md
      - SERVICE_CATEGORY_IMPLEMENTATION_COMPLETE.md
      - SERVICE_MANAGEMENT_SPEC.md
      - VERIFICATION_CHECKLIST.md
      - SHIFT_ACCESS_CONTROL.md
    - Log each file removal
    - _Requirements: 7.1, 7.2, 7.3_
  
  - [ ] 14.2 Remove utility and scratch scripts from root directory
    - Remove files:
      - check_prices_db.php
      - fix_encoding.js, fix_encoding.php, fix_encoding.py
      - fix_layout_match.php
      - fix_pr_review_bottom.php
      - fix_safe_user_id.php
      - fix_staff_popup.php
      - scratch_check_schema.php, scratch_check_schema2.php
      - scratch_dump_users.php
      - scratch_find_items.php
      - scratch_find_sidebar_html.php, scratch_find_sidebar.php
    - Log each file removal
    - _Requirements: 7.1, 7.2, 7.3_
  
  - [ ] 14.3 Remove entire scratch/ directory with all contents
    - Remove scratch/ directory and files:
      - scratch/check_adjustments.php
      - scratch/check_fuel_tables.php
      - scratch/find_all_beginning_lines.php
      - scratch/find_fuel_tables_schema.php
      - scratch/find_meter_form_lines.php
      - scratch/inspect_fuel_data.php
    - Log directory removal and space freed
    - _Requirements: 7.1, 7.2, 7.3_
  
  - [ ] 14.4 Remove test and debug files from public/ directory
    - Remove files:
      - public/add_manager.php, public/add_staff.php
      - public/autologin_test.php
      - public/check_tables_pub.php
      - public/debug_cols.php, public/debug_cols2.php
      - public/debug_request.php
      - public/delete_all_pumps.php, public/force_delete_pumps_now.php
      - public/dump_schema.php
      - public/generate_hash.php, public/hash.php
      - public/test_hash.php, public/test_hash2.php, public/test_hash3.php
      - public/inspect_fuel_tx.php
      - public/query_pumps.php
      - public/scratch_fuel.php, public/scratch_schema.php
      - public/simulate_forgot.php
      - public/test_notif.php
      - public/test_offline_qr.html, public/test_out.html
      - public/verify_cleanup.php
    - Log each file removal
    - _Requirements: 7.1, 7.2, 7.3_
  
  - [ ] 14.5 Remove log and orphaned files from root
    - Remove files:
      - email_send.log
      - image.png
      - public/notifications_out.html
    - Log each file removal and total space freed
    - _Requirements: 7.1, 7.2, 7.3_

- [ ] 15. Implement migration controller and orchestration
  - [ ] 15.1 Create MigrationController class to orchestrate all phases
    - Create `database/migration/MigrationController.php`
    - Implement `execute()` to run all migration phases in order
    - Implement `rollback()` to restore from backups on failure
    - Implement `getStatus()` to return current migration status
    - Coordinate between BackupManager, DatabaseMigrator, ReferenceUpdater, FileCleanupManager, and Validator
    - Implement error handling with automatic rollback on fatal errors
    - Implement progress tracking with callbacks for UI updates
    - Add PSR-3 compatible logging throughout
    - _Requirements: All_
  
  - [ ]* 15.2 Write integration tests for MigrationController
    - Test end-to-end migration with no Legacy_Database (reference updates only)
    - Test full migration with Legacy_Database containing sample data
    - Test migration with existing data in both databases (merge scenario)
    - Test rollback scenario after partial migration
    - _Requirements: All_

- [ ] 16. Create migration execution script
  - [ ] 16.1 Create command-line migration script for administrators
    - Create `database/migration/execute_migration.php` as CLI entry point
    - Implement administrator authentication check
    - Implement maintenance mode activation before migration
    - Add interactive prompts for confirmation before destructive operations
    - Display progress updates during long-running operations
    - Generate comprehensive migration report at completion
    - _Requirements: All_

- [ ] 17. Run comprehensive post-migration validation
  - [ ] 17.1 Execute full validation suite
    - Run `validateSchema()` to confirm all tables present
    - Run `validateDataMigration()` to confirm row counts match
    - Run `validateReferences()` to ensure no legacy references in active code
    - Test database connection from application
    - Generate validation report with pass/fail status for each check
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6_
  
  - [ ]* 17.2 Run smoke tests on core application functionality
    - Test login and authentication flows
    - Test core business operations (sales, inventory lookups)
    - Test report generation
    - Test backup/restore functionality
    - Verify no errors in application logs
    - _Requirements: 8.3, 8.5_

- [ ] 18. Checkpoint - Ensure validation passed
  - Ensure all validation checks passed, review validation report, ask the user if questions arise.

- [ ] 19. Create documentation and migration report
  - [ ] 19.1 Generate comprehensive migration report
    - Create report including:
      - Migration start/end timestamps
      - Phase-by-phase results (backup, data migration, reference updates, cleanup, validation)
      - Statistics: tables migrated, files modified, files removed, space freed
      - List of all modified files
      - List of all removed files
      - Validation results with pass/fail for each check
      - Any errors or warnings encountered
      - Backup file paths for rollback capability
    - Save report to `database/migration/reports/migration_report_[timestamp].md`
    - _Requirements: 7.4, 8.5_
  
  - [ ] 19.2 Update system documentation
    - Update README or system documentation to reflect database consolidation
    - Document rollback procedure for administrators
    - Document location of migration reports and logs
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [ ] 20. Implement optional Legacy_Database cleanup
  - [ ] 20.1 Add Legacy_Database removal option after successful migration
    - Prompt administrator for confirmation to drop Legacy_Database
    - IF confirmed, execute DROP DATABASE statement
    - Log removal with timestamp and administrator identifier
    - IF declined, log decision to preserve Legacy_Database
    - _Requirements: 9.1, 9.2, 9.3, 9.4_

- [ ] 21. Final checkpoint - System ready for production
  - Ensure all tests pass, validation complete, migration report generated, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster execution
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation at critical points
- This is an infrastructure/operations feature - no property-based testing required
- Rollback capability is available throughout via BackupManager
- Migration should be run during maintenance window with database backups verified
- All file operations use safe methods with permission checking
- Large SQL files use streaming to avoid memory exhaustion
- Progress callbacks enable UI updates during long-running operations
