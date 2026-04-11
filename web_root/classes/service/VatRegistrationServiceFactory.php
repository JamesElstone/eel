<?php
declare(strict_types=1);

final class VatRegistrationServiceFactory
{
    public static function createFromConfig(?array $config = null, ?string $hmrcMode = null): VatRegistrationService {
        $config ??= FrameWorkHelper::config();
        $hmrcConfig = is_array($config['hmrc']['vat'] ?? null) ? $config['hmrc']['vat'] : [];

        if ($hmrcMode !== null && trim($hmrcMode) !== '') {
            $hmrcConfig['mode'] = ctrl_normalise_environment_mode($hmrcMode);
        }

        return new VatRegistrationService(
            new HmrcVatValidator($hmrcConfig)
        );
    }
}
