<?php
declare(strict_types=1);

namespace App;

final class Bootstrap
{
    public static array $config = [];
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $rootDir = dirname(__DIR__);
        $configFile = $rootDir . '/config.php';
        if (!is_file($configFile)) {
            http_response_code(500);
            exit('config.php ausente. Copie config.example.php e preencha.');
        }
        self::$config = require $configFile;

        date_default_timezone_set(self::$config['app']['timezone'] ?? 'America/Recife');

        $debug = (bool)(self::$config['app']['debug'] ?? false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        $logsDir = self::$config['paths']['logs'] ?? ($rootDir . '/storage/logs');
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0775, true);
        }
        ini_set('error_log', $logsDir . '/php-error.log');

        spl_autoload_register(static function (string $class) use ($rootDir): void {
            $prefix = 'App\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $rootDir . '/app/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        Database::init(self::$config['db']);
    }

    public static function config(string $path, $default = null)
    {
        $segments = explode('.', $path);
        $node = self::$config;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }
}
