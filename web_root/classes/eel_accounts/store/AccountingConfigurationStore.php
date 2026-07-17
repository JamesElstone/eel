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
    public const CH_ACCOUNTS_FILING_DISABLED = 'DISABLED';
    public const CH_ACCOUNTS_FILING_TEST = 'TEST';
    public const CH_ACCOUNTS_FILING_LIVE = 'LIVE';

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

    /**
     * Accounts filing is deliberately isolated from the read-only Companies House mode.
     * A missing or invalid value always fails closed.
     */
    public static function companiesHouseAccountsFilingMode(bool $reload = false): string
    {
        $mode = strtoupper(trim((string)\AppConfigurationStore::get(
            'runtime.ch_accounts_filing_mode',
            self::CH_ACCOUNTS_FILING_DISABLED,
            $reload
        )));

        return in_array($mode, [
            self::CH_ACCOUNTS_FILING_TEST,
            self::CH_ACCOUNTS_FILING_LIVE,
        ], true) ? $mode : self::CH_ACCOUNTS_FILING_DISABLED;
    }

    public static function setCompaniesHouseAccountsFilingMode(string $mode): array
    {
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, [
            self::CH_ACCOUNTS_FILING_DISABLED,
            self::CH_ACCOUNTS_FILING_TEST,
            self::CH_ACCOUNTS_FILING_LIVE,
        ], true)) {
            $mode = self::CH_ACCOUNTS_FILING_DISABLED;
        }

        return \AppConfigurationStore::set('runtime.ch_accounts_filing_mode', $mode);
    }

    public static function companiesHouseAccountsLiveApproved(bool $reload = false): bool
    {
        $value = \AppConfigurationStore::get(
            'runtime.ch_accounts_filing_live_approved',
            false,
            $reload
        );

        return filter_var($value, FILTER_VALIDATE_BOOL);
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
