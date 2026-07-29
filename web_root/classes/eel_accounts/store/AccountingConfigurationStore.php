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
    public const HMRC_XML_DISABLED = 'DISABLED';
    public const HMRC_XML_TEST = 'TEST';
    public const HMRC_XML_LIVE = 'LIVE';

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

    /** A missing or invalid HMRC XML filing mode always fails closed. */
    public static function hmrcXmlMode(bool $reload = false): string
    {
        $mode = strtoupper(trim((string)\AppConfigurationStore::get(
            'runtime.hmrc_xml_mode',
            self::HMRC_XML_DISABLED,
            $reload
        )));

        return in_array($mode, [
            self::HMRC_XML_TEST,
            self::HMRC_XML_LIVE,
        ], true) ? $mode : self::HMRC_XML_DISABLED;
    }

    public static function setHmrcXmlMode(string $mode): array
    {
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, [
            self::HMRC_XML_DISABLED,
            self::HMRC_XML_TEST,
            self::HMRC_XML_LIVE,
        ], true)) {
            $mode = self::HMRC_XML_DISABLED;
        }

        return \AppConfigurationStore::set('runtime.hmrc_xml_mode', $mode);
    }

    public static function uploads(): array
    {
        $uploads = \AppConfigurationStore::get('uploads', []);
        if (!is_array($uploads)) {
            return [];
        }

        $baseDirectory = trim((string)($uploads['upload_base_dir'] ?? ''));
        if ($baseDirectory !== '') {
            $uploads['upload_base_dir'] = self::absoluteProjectPath($baseDirectory);
        }

        return $uploads;
    }

    public static function hmrcConfig(string $service): array
    {
        $config = \AppConfigurationStore::get('hmrc.' . trim($service), []);

        return is_array($config) ? $config : [];
    }

    public static function isConfiguredTestUploadPath(string $path): bool
    {
        if (PHP_SAPI !== 'cli' || !defined('APP_ROOT') || !defined('APP_CONFIG')) {
            return false;
        }

        $appRoot = rtrim((string)APP_ROOT, '\\/');
        $expectedConfigRoot = self::normaliseComparablePath(
            $appRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
                . 'tmp' . DIRECTORY_SEPARATOR . 'config'
        );
        $configuredPath = self::normaliseComparablePath((string)APP_CONFIG);
        if (!str_starts_with($configuredPath, $expectedConfigRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $testRoot = $appRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
        $normalisedPath = self::normaliseComparablePath($path);
        $normalisedRoot = self::normaliseComparablePath($testRoot);

        return $normalisedPath === $normalisedRoot
            || str_starts_with($normalisedPath, $normalisedRoot . DIRECTORY_SEPARATOR);
    }

    private static function absoluteProjectPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/D', $path) === 1) {
            return rtrim($path, '\\/');
        }

        $projectRoot = defined('PROJECT_ROOT')
            ? (string)PROJECT_ROOT
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;

        return rtrim($projectRoot, '\\/')
            . DIRECTORY_SEPARATOR
            . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private static function normaliseComparablePath(string $path): string
    {
        $normalised = rtrim(
            str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($path)),
            DIRECTORY_SEPARATOR
        );

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalised) : $normalised;
    }
}
