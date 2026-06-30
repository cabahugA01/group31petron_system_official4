@echo off
echo ================================================================
echo RESTORING DATABASE NOW...
echo ================================================================
echo.

cd c:\xampp\mysql\bin

echo Dropping corrupted database...
mysql -u root -e "DROP DATABASE IF EXISTS petron_pos_db_secure;"

echo Creating fresh database...
mysql -u root -e "CREATE DATABASE petron_pos_db_secure CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

echo Importing from backup (this takes 1-2 minutes)...
mysql -u root petron_pos_db_secure < "c:\xampp\htdocs\group31petron_system_official4\database\petron_pos_db_secure.sql"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ================================================================
    echo SUCCESS! Database restored!
    echo ================================================================
    echo.
    echo Now test login at:
    echo http://localhost/group31petron_system_official4/public/login.php
    echo.
    echo If still error, run FIX_DATABASE_ENGINE.sql in phpMyAdmin
    echo.
) else (
    echo.
    echo ================================================================
    echo ERROR! Failed to import!
    echo ================================================================
    echo.
    echo Make sure MySQL is running in XAMPP Control Panel!
    echo.
)

pause
