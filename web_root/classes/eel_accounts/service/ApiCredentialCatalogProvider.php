<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class ApiCredentialCatalogProvider implements \ApiCredentialCatalogProviderInterface
{
    public function credentialCatalog(): array
    {
        $entries = [];

        foreach (['TEST', 'LIVE'] as $environment) {
            $entries[] = $this->entry('COMPANIESHOUSE', 'REST', 'COMPANY_LOOKUP', $environment);
            foreach ([
                'XML_PRESENTER_CREDENTIALS',
                'ACCOUNTS_FILING_PACKAGE_REFERENCE',
            ] as $tag) {
                $entries[] = $this->entry('COMPANIESHOUSE', 'XML', $tag, $environment);
            }

            $entries[] = $this->entry('HMRC', 'REST', 'VAT_CHECK', $environment);
            $entries[] = $this->entry('HMRC', 'REST', 'FPH_VALIDATOR', $environment);
            $entries[] = $this->entry('HMRC', 'XML', 'CT600_XML', $environment);
        }

        return $entries;
    }

    private function entry(string $provider, string $gateway, string $tag, string $environment): array
    {
        return compact('provider', 'gateway', 'tag', 'environment');
    }
}
