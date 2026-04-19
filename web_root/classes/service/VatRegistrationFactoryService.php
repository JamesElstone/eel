<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
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


