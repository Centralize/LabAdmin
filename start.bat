@echo off
REM Start LabAdmin: the web UI (PHP built-in server) + the terminal service in its own window.
cd /d "%~dp0"
if not exist vendor\autoload.php call composer install --no-dev
if "%LABADMIN_LISTEN%"=="" set LABADMIN_LISTEN=0.0.0.0:8080

REM Terminal service (WebSocket, port 8081 by default). Close its window to stop it.
start "LabAdmin terminal service" /min php bin\terminal-server.php

echo LabAdmin on http://%LABADMIN_LISTEN%/
php -S %LABADMIN_LISTEN% -t public
