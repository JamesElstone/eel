<?php
declare(strict_types=1);

final class VatRegistrationFactoryService
{
    public static function createFromConfig(?array $config = null, ?string $hmrcMode = null): VatRegistrationService {
        $config ??= AppConfigurationStore::config();
        $hmrcConfig = is_array($config['hmrc']['vat'] ?? null) ? $config['hmrc']['vat'] : [];

        if ($hmrcMode !== null && trim($hmrcMode) !== '') {
            $hmrcConfig['mode'] = HelperFramework::normaliseEnvironmentMode($hmrcMode);
        }

        return new VatRegistrationService(
            new HmrcOutbound($hmrcConfig)
        );
    }
}


