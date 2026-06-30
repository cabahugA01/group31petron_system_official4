@echo off
echo ================================================================
echo RESTORE DATABASE - PETRON SYSTEM
echo ================================================================
echo.
echo This will restore your database from backup.
echo.
echo IMPORTANT: Make sure MySQL is running in XAMPP!
echo.
pause

cd c:\xampp\mysql\bin

echo.
echo ================================================================
echo Step 1: Dropping old database (if exists)
echo ================================================================
mysql -u root -e "DROP DATABASE IF EXISTS petron_pos_db_secure;"
echo Done!

echo.
echo ================================================================
echo Step 2: Creating fresh database
echo ================================================================
mysql -u root -e "CREATE DATABASE petron_pos_db_secure CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
echo Done!

echo.
echo ================================================================
echo Step 3: Importing database from backup
echo ================================================================
echo This may take 1-2 minutes...
echo.

mysql -u root petron_pos_db_secure < "c:\xampp\htdocs\group31petron_system_official4\database\petron_pos_db_secure.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ================================================================
    echo SUCCESS! Database restored successfully!
    echo ================================================================
    echo.
    echo Next steps:
    echo 1. Go to: http://localhost/phpmyadmin
    echo 2. Select: petron_pos_db_secure
    echo 3. Run: FIX_DATABASE_ENGINE.sql to convert tables to InnoDB
    echo 4. Test login: http://localhost/group31petron_system_official4/public/login.php
    echo.
) else (
    echo.
    echo ================================================================
    echo ERROR! Import failed!
    echo ================================================================
    echo.
    echo Possible reasons:
    echo 1. MySQL is not running (Start it in XAMPP)
    echo 2. Backup file not found
    echo 3. MySQL permissions issue
    echo.
    echo Check XAMPP Control Panel - MySQL should be green/running
    echo.
)

pause
