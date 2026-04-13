<?php
declare(strict_types=1);

final class AppConfigurationStore
{
    public static function config(): array
    {
        static $config = null;

        if (is_array($config)) {
            return $config;
        }

        $loaded = require APP_CONFIG . 'app.php';
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }
}
