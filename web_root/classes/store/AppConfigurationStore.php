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
        $config = array_replace_recursive(self::defaults(), is_array($loaded) ? $loaded : []);

        return $config;
    }

    private static function defaults(): array
    {
        return [
            'api_keys' => [
                'path' => '../secure/api.keys',
            ],
            'hmrc' => [
                'fraud_prevention_validator' => [
                    'accept_header' => 'application/vnd.hmrc.1.0+json',
                    'credential_provider' => 'HMRC',
                    'credential_tag' => 'FPH_VALIDATOR',
                    'keys_path' => '',
                    'mode' => 'TEST',
                    'oauth_path' => '/oauth/token',
                    'timeout_seconds' => 10,
                    'token_scope' => '',
                    'validate_method' => 'GET',
                    'validate_path' => '/test/fraud-prevention-headers/validate',
                ],
            ],
            'security_keys' => [
                'path' => '../secure/security.keys',
            ],
        ];
    }
}
