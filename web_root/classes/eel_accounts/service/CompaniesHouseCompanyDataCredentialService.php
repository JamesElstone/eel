<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseCompanyDataCredentialService
{
    private const PROVIDER = 'COMPANIESHOUSE';
    private const PRESENTER_TAG = 'XML_PRESENTER_CREDENTIALS';

    public function __construct(private readonly ?string $keysPath = null)
    {
    }

    /** @return array{presenter_id:string,presenter_code:string,package_reference:string} */
    public function load(string $environment): array
    {
        $environment = $this->environment($environment);
        $presenter = $this->presenterCredential($environment);
        $credentials = [
            'presenter_id' => trim((string)($presenter['api_identity'] ?? '')),
            'presenter_code' => trim((string)($presenter['api_key'] ?? '')),
            'package_reference' => '',
        ];
        if ($credentials['presenter_id'] === '' || $credentials['presenter_code'] === '') {
            throw new \RuntimeException(
                'Companies House CompanyData XML Output credentials are not configured for '
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

    private function presenterCredential(string $environment): array
    {
        return \SecurityStore::loadCredential(
            self::PROVIDER,
            'XML',
            self::PRESENTER_TAG,
            $environment,
            $this->keysPath
        );
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
