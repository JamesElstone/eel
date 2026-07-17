<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Normalises and validates the Companies House identity supported by the filing profile. */
final class IxbrlCompanyIdentityService
{
    /** @return array<string, mixed> */
    public function normalise(array $company): array
    {
        foreach ([
            'company_name',
            'company_number',
            'companies_house_type',
            'companies_house_jurisdiction',
            'company_status',
            'registered_office_address_line_1',
            'registered_office_address_line_2',
            'registered_office_locality',
            'registered_office_region',
            'registered_office_postal_code',
            'registered_office_country',
        ] as $key) {
            $company[$key] = trim((string)($company[$key] ?? ''));
        }

        $postcode = (string)$company['registered_office_postal_code'];
        $locality = (string)$company['registered_office_locality'];
        $region = (string)$company['registered_office_region'];
        if ($this->normalisedPart($region) === $this->normalisedPart($postcode)) {
            $region = '';
        }
        if ($this->normalisedPart($region) === $this->normalisedPart($locality)) {
            $region = '';
        }
        $company['registered_office_address_line_3'] = implode(', ', array_values(array_filter(
            [$locality, $region],
            static fn(string $part): bool => $part !== ''
        )));
        $company['companies_house_type'] = strtolower((string)$company['companies_house_type']);
        $company['companies_house_jurisdiction'] = strtolower((string)$company['companies_house_jurisdiction']);
        $company['company_status'] = strtolower((string)$company['company_status']);

        return $company;
    }

    /** @return string[] */
    public function errors(array $company): array
    {
        $company = $this->normalise($company);
        $errors = [];
        if ((string)$company['company_name'] === '') {
            $errors[] = 'Company legal name is missing.';
        }
        if ((string)$company['company_number'] === '') {
            $errors[] = 'Companies House company number is missing.';
        }
        if ((string)$company['companies_house_type'] === '') {
            $errors[] = 'Companies House company type is missing.';
        } elseif ((string)$company['companies_house_type'] !== 'ltd') {
            $errors[] = 'The current filing profile supports Companies House type ltd only.';
        }
        if ((string)$company['companies_house_jurisdiction'] === '') {
            $errors[] = 'Companies House jurisdiction is missing.';
        } elseif ((string)$company['companies_house_jurisdiction'] !== 'england-wales') {
            $errors[] = 'The current filing profile supports the England and Wales jurisdiction only.';
        }
        if ((string)$company['company_status'] === '') {
            $errors[] = 'Companies House company status is missing.';
        } elseif ((string)$company['company_status'] !== 'active') {
            $errors[] = 'The current filing profile supports active companies only; winding-up and other status disclosures are not implemented.';
        }
        if ((string)$company['registered_office_address_line_1'] === '') {
            $errors[] = 'Registered office address line 1 is missing.';
        }
        if ((string)$company['registered_office_address_line_3'] === '') {
            $errors[] = 'Registered office locality or region is missing.';
        }
        if ((string)$company['registered_office_postal_code'] === '') {
            $errors[] = 'Registered office postal code is missing.';
        }
        $country = strtolower((string)$company['registered_office_country']);
        if ($country === '') {
            $errors[] = 'Registered office country is missing.';
        } elseif (!in_array($country, ['united kingdom', 'uk', 'england', 'wales', 'england and wales'], true)) {
            $errors[] = 'The registered office country is not supported by the United Kingdom address profile.';
        }

        return $errors;
    }

    private function normalisedPart(string $value): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]+/i', '', $value));
    }
}
