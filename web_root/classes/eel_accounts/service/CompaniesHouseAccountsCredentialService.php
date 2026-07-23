<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseAccountsCredentialService
{
    private const PROVIDER = 'COMPANIESHOUSE';
    private const PRESENTER_TAG = 'ACCOUNTS_FILING_PRESENTER_ID';
    private const AUTHENTICATION_TAG = 'ACCOUNTS_FILING_AUTHENTICATION';
    private const PACKAGE_TAG = 'ACCOUNTS_FILING_PACKAGE_REFERENCE';

    public function __construct(private readonly ?string $keysPath = null)
    {
    }

    /** @return array{presenter_id:string,presenter_code:string,package_reference:string} */
    public function load(string $environment): array
    {
        $environment = $this->environment($environment);
        $credentials = [
            'presenter_id' => $this->value(self::PRESENTER_TAG, $environment),
            'presenter_code' => $this->value(self::AUTHENTICATION_TAG, $environment),
            'package_reference' => $this->value(self::PACKAGE_TAG, $environment),
        ];

        if ($credentials['presenter_id'] === '' || $credentials['presenter_code'] === '') {
            throw new \RuntimeException(
                'Companies House accounts filing presenter credentials are not configured for '
                . $environment . ' in secure/api.keys.'
            );
        }
        if ($credentials['package_reference'] === '') {
            throw new \RuntimeException(
                'Companies House accounts filing package reference is not configured for '
                . $environment . ' in secure/api.keys.'
            );
        }

        return $credentials;
    }

    public function configured(string $environment): bool
    {
        try {
            $this->load($environment);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function presenterFingerprint(string $environment): string
    {
        return hash('sha256', strtoupper($this->load($environment)['presenter_id']));
    }

    private function value(string $tag, string $environment): string
    {
        $credential = \SecurityStore::loadCredential(
            self::PROVIDER,
            $tag,
            $environment,
            $this->keysPath
        );

        return trim((string)($credential['api_key'] ?? ''));
    }

    private function environment(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            throw new \InvalidArgumentException('Companies House credential environment must be TEST or LIVE.');
        }

        return $environment;
    }
}
