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
    private const DEFAULT_APP_STRAPLINE = 'Bookkeeping without the fog and panic';

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

    public static function setEditableApplicationSettings(array $settings): array
    {
        $config = self::readStoredConfig();

        foreach ([
            'app_name',
            'app_strapline',
            'brand-mark',
            'developer_options',
            'navigation',
            'antifraud',
            'session',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                $config[$key] = $settings[$key];
            }
        }

        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function setInvitationSettings(array $settings): array
    {
        $config = self::readStoredConfig();
        $current = is_array($config['invitation'] ?? null) ? $config['invitation'] : [];
        $config['invitation'] = array_replace($current, $settings);
        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function setWebEnvironmentSettings(array $settings): array
    {
        $config = self::readStoredConfig();
        $currentInvitation = is_array($config['invitation'] ?? null) ? $config['invitation'] : [];
        $currentReverseProxy = is_array($config['reverse_proxy'] ?? null) ? $config['reverse_proxy'] : [];

        $config['invitation'] = array_replace($currentInvitation, [
            'base_url_override' => (string)($settings['base_url_override'] ?? ''),
        ]);
        $reverseProxySettings = [];
        if (array_key_exists('trusted_proxy_ips', $settings)) {
            $reverseProxySettings['trusted_proxy_ips'] = array_values((array)$settings['trusted_proxy_ips']);
        }
        if (array_key_exists('client_ip_headers', $settings)) {
            $reverseProxySettings['client_ip_headers'] = array_values((array)$settings['client_ip_headers']);
        }
        $config['reverse_proxy'] = array_replace($currentReverseProxy, $reverseProxySettings);
        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function setSmsSettings(array $settings): array
    {
        $config = self::readStoredConfig();
        $current = is_array($config['sms'] ?? null) ? $config['sms'] : [];
        if (($settings['auth_token'] ?? '') === '__unchanged__') {
            unset($settings['auth_token']);
        }
        $config['sms'] = array_replace($current, $settings);
        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function setSmtpSettings(array $settings): array
    {
        $config = self::readStoredConfig();
        $current = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
        if (($settings['password'] ?? '') === '__unchanged__') {
            unset($settings['password']);
        }
        $config['smtp'] = array_replace($current, $settings);
        self::writeStoredConfig($config);

        return self::config(true);
    }

    public static function set(string $path, mixed $value): array
    {
        $segments = self::configPathSegments($path);
        $lastIndex = count($segments) - 1;
        $config = self::readStoredConfig();
        $target =& $config;

        foreach ($segments as $index => $segment) {
            if ($index === $lastIndex) {
                $target[$segment] = $value;
                continue;
            }

            if (!array_key_exists($segment, $target) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target =& $target[$segment];
        }

        unset($target);
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

    public static function appStrapline(bool $reload = false): string
    {
        $appStrapline = trim((string)self::get('app_strapline', self::DEFAULT_APP_STRAPLINE, $reload));

        return $appStrapline !== '' ? $appStrapline : self::DEFAULT_APP_STRAPLINE;
    }

    private static function defaults(): array
    {
        return [
            'app_name' => 'eelKit Framework',
            'brand-mark' => 'E',
            'app_strapline' => self::DEFAULT_APP_STRAPLINE,
            'developer_options' => true,
            'db' => [
                'dsn' => '',
                'user' => '',
                'pass' => '',
                'logfile' => '',
            ],
            'trace' => [
                'log_path' => '',
            ],
            'navigation' => [
                'default_order' => [],
                'developer_only_pages' => [
                    'test',
                ],
                'hide_collapsed_link_initials' => false,
            ],
            'reverse_proxy' => [
                'trusted_proxy_ips' => [],
                'client_ip_headers' => [
                    'X-Forwarded-For',
                    'X-Real-IP',
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
            'site_context' => [
                'service' => '',
            ],
            'invitation' => [
                'enabled' => true,
                'expiry_days' => 5,
                'base_url_override' => '',
                'sms_template' => 'You have been invited to complete your account setup for {app_name}. Use this secure link: {link}',
                'email_subject_template' => 'Complete your {app_name} account setup',
                'email_body_template' => "You have been invited to complete your account setup for {app_name}.\n\nUse this secure link:\n\n{link}\n\nThis link will expire on {expires_at}.",
            ],
            'sms' => [
                'enabled' => false,
                'api_url' => 'http://sms.api.server/sms-gateway/send/{telephone_number}',
                'method' => 'POST',
                'auth_header' => 'X-SMS-Gateway-Token',
                'auth_token' => '',
                'sender_id' => '',
                'development_mode' => true,
            ],
            'smtp' => [
                'enabled' => false,
                'transport' => 'smtp',
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'starttls',
                'auth_mode' => 'login',
                'from_address' => '',
                'from_name' => '',
                'development_mode' => true,
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

    private static function configPathSegments(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('Configuration path cannot be empty.');
        }

        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new RuntimeException('Configuration path cannot contain empty segments: ' . $path);
            }
        }

        return $segments;
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
