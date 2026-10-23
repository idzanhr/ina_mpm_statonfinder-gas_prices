<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'InaPostaje\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

use InaPostaje\Config;

$configFile = getenv('INA_POSTAJE_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("Missing app/config.php - copy app/config.example.php and fill it in.\n");
}
Config::load(require $configFile);
date_default_timezone_set('Europe/Zagreb');
