<?php
declare(strict_types=1);

final class VatRegistrationServiceFactory
{
    public static function createFromConfig(?array $config = null, ?string $hmrcMode = null): VatRegistrationService {
        $config ??= FrameworkHelper::config();
        $hmrcConfig = is_array($config['hmrc']['vat'] ?? null) ? $config['hmrc']['vat'] : [];

        if ($hmrcMode !== null && trim($hmrcMode) !== '') {
            $hmrcConfig['mode'] = FrameworkHelper::normaliseEnvironmentMode($hmrcMode);
        }

        return new VatRegistrationService(
            new HmrcVatValidator($hmrcConfig)
        );
    }
}
