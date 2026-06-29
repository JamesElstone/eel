<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Store;

final class AccountingConfigurationStore
{
    public static function companiesHouseMode(bool $reload = false): string
    {
        return \HelperFramework::normaliseEnvironmentMode(
            (string)\AppConfigurationStore::get('runtime.ch_mode', 'TEST', $reload)
        );
    }

    public static function hmrcMode(bool $reload = false): string
    {
        return \HelperFramework::normaliseEnvironmentMode(
            (string)\AppConfigurationStore::get('runtime.hmrc_mode', 'TEST', $reload)
        );
    }

    public static function setCompaniesHouseMode(string $mode): array
    {
        return \AppConfigurationStore::set('runtime.ch_mode', \HelperFramework::normaliseEnvironmentMode($mode));
    }

    public static function setHmrcMode(string $mode): array
    {
        return \AppConfigurationStore::set('runtime.hmrc_mode', \HelperFramework::normaliseEnvironmentMode($mode));
    }

    public static function uploads(): array
    {
        $uploads = \AppConfigurationStore::get('uploads', []);

        return is_array($uploads) ? $uploads : [];
    }

    public static function hmrcConfig(string $service): array
    {
        $config = \AppConfigurationStore::get('hmrc.' . trim($service), []);

        return is_array($config) ? $config : [];
    }
}
