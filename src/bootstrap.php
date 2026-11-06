<?php
declare(strict_types=1);

/**
 * LabAdmin bootstrap: autoload, config, error handling.
 */

define('LABADMIN_ROOT', dirname(__DIR__));

$autoload = LABADMIN_ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "LabAdmin: dependencies missing. Run `composer install` in " . LABADMIN_ROOT . "\n";
    exit(1);
}
require $autoload;

// Fallback PSR-4 loader for our own namespace (in case composer dump-autoload was not run).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'LabAdmin\\')) {
        $file = LABADMIN_ROOT . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$config = [
    'data_dir'        => LABADMIN_ROOT . '/data',
    'ssh_timeout'     => 6,      // seconds to connect (LAN - keep it short)
    'cmd_timeout'     => 120,    // seconds per command
    'session_name'    => 'labadmin_sid',
    'session_lifetime'=> 60 * 60 * 8,
    'login_max_fails' => 5,      // per 15 minutes per IP
];
$local = LABADMIN_ROOT . '/config.local.php';
if (is_file($local)) {
    $config = array_replace($config, (array) require $local);
}
$GLOBALS['LABADMIN_CONFIG'] = $config;

if (!is_dir($config['data_dir'])) {
    mkdir($config['data_dir'], 0700, true);
}

date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
