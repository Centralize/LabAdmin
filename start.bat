@echo off
REM Start LabAdmin with PHP's built-in server (single worker on Windows).
cd /d "%~dp0"
if not exist vendor\autoload.php call composer install --no-dev
if "%LABADMIN_LISTEN%"=="" set LABADMIN_LISTEN=0.0.0.0:8080
echo LabAdmin on http://%LABADMIN_LISTEN%/
php -S %LABADMIN_LISTEN% -t public
