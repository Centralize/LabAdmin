<?php
declare(strict_types=1);

/**
 * LabAdmin interactive terminal service.
 *
 *   php bin/terminal-server.php
 *
 * Listens on terminal_listen:terminal_port (default 0.0.0.0:8081). start.bat / start.sh
 * start it together with the web UI. Stop it with Ctrl+C.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(0);
ini_set('memory_limit', '256M');

(new LabAdmin\TerminalServer($GLOBALS['LABADMIN_CONFIG']))->run();
