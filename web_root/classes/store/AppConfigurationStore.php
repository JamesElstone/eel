<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AppConfigurationStore
{
    private static ?array $config = null;

    public static function config(bool $reload = false): array
    {
        if (!$reload && is_array(self::$config)) {
            return self::$config;
        }

        self::ensureStoredConfig();

        $loaded = require self::storedConfigPath();
        self::$config = array_replace_recursive(self::defaults(), is_array($loaded) ? $loaded : []);

        return self::$config;
    }

    public static function configPath(): string
    {
        return self::storedConfigPath();
    }

    public static function ensureStoredConfig(): string
    {
        $path = self::storedConfigPath();

        if (is_file($path)) {
            return $path;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create application configuration directory: ' . $directory);
        }

        self::writeConfigFile($path, self::defaults());

        return $path;
    }

    public static function setAntifraudVendorPublicIp(string $ip): array
    {
        $config = self::readStoredConfig();

        if (!is_array($config['antifraud'] ?? null)) {
            $config['antifraud'] = [];
        }

        $config['antifraud']['vendor_public_ip'] = $ip;

        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function setDatabaseConfig(string $dsn, string $user, string $password): array
    {
        $dsn = trim($dsn);

        if ($dsn === '') {
            throw new RuntimeException('Database DSN cannot be empty.');
        }

        $config = self::readStoredConfig();
        $db = is_array($config['db'] ?? null) ? $config['db'] : [];

        $db['dsn'] = $dsn;
        $db['user'] = $user;
        $db['pass'] = $password;
        $config['db'] = $db;

        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function ensureUploadExportKey(int $length = 32): string
    {
        $config = self::readStoredConfig();
        $uploads = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
        $existing = trim((string)($uploads['export_key'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $key = self::randomAsciiString(max(16, $length));
        $config['uploads'] = $uploads;
        $config['uploads']['export_key'] = $key;
        self::writeStoredConfig($config);
        self::config(true);

        return $key;
    }

    public static function get(string $path, mixed $default = null, bool $reload = false): mixed
    {
        $config = self::config($reload);

        $path = trim($path);
        if ($path === '') {
            return $config;
        }

        $segments = explode('.', $path);
        $value = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function defaults(): array
    {
        return [
            'app_name' => 'eelKit Framework',
            'app_strapline' => 'A php framework',
            'developer_options' => true,
            'db' => [
                'dsn' => '',
                'user' => '',
                'pass' => '',
                'logfile' => '',
            ],
            'navigation' => [
                'default_order' => [],
                'developer_only_pages' => [
                    'test',
                ],
            ],
            'antifraud' => [
                'vendor_license_ids' => '1234',
                'vendor_product_name' => 'eelKit',
                'vendor_public_ip' => '',
                'vendor_version' => 'dev',
            ],
            'api_keys' => [
                'path' => '../secure/api.keys',
            ],
            'security_keys' => [
                'path' => '../secure/security.keys',
            ],
            'session' => [
                'cookie_secure' => 'auto',
                'cookie_samesite' => 'Strict',
            ],
            'totp' => [
                'encryption_key_fact' => 'totp_encryption_key',
                'pending_secret_lifetime_seconds' => 300,
            ],
        ];
    }

    private static function readStoredConfig(): array
    {
        self::ensureStoredConfig();

        $loaded = require self::storedConfigPath();

        return is_array($loaded) ? $loaded : [];
    }

    private static function writeStoredConfig(array $config): void
    {
        self::writeConfigFile(self::storedConfigPath(), $config);
        self::$config = null;
    }

    private static function writeConfigFile(string $path, array $config): void
    {
        self::assertConfigPathWritable($path);

        $content = "<?php\n"
            . "/**\n"
            . " * eelKit Framework\n"
            . " * Copyright (c) 2026 James Elstone\n"
            . " * Licensed under the BSD 3-Clause License\n"
            . " * See LICENSE file for details.\n"
            . " */\n"
            . "declare(strict_types=1);\n\n"
            . 'return ' . var_export($config, true) . ";\n";

        $result = @file_put_contents($path, $content, LOCK_EX);

        if ($result === false) {
            throw new RuntimeException('Unable to write application configuration file: ' . $path);
        }
    }

    private static function assertConfigPathWritable(string $path): void
    {
        clearstatcache(true, $path);

        if (is_dir($path)) {
            throw new RuntimeException('Application configuration file path is a directory: ' . $path);
        }

        if (is_file($path)) {
            if (!is_writable($path)) {
                throw new RuntimeException('Application configuration file is not writable: ' . $path);
            }

            return;
        }

        $directory = dirname($path);
        clearstatcache(true, $directory);

        if (!is_dir($directory)) {
            throw new RuntimeException('Application configuration directory does not exist: ' . $directory);
        }

        if (!is_writable($directory)) {
            throw new RuntimeException('Application configuration directory is not writable: ' . $directory);
        }
    }

    private static function storedConfigPath(): string
    {
        return APP_CONFIG . 'app.php';
    }

    private static function randomAsciiString(int $length): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $value = '';

        for ($index = 0; $index < $length; $index++) {
            $value .= $alphabet[random_int(0, $max)];
        }

        return $value;
    }
}
