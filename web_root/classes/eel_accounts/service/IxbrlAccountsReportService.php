<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds the single source model used by iXBRL facts, rendering and freshness. */
final class IxbrlAccountsReportService
{
    public function build(int $companyId, int $accountingPeriodId): array
    {
        $company = \InterfaceDB::fetchOne(
            'SELECT * FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );
        $period = \InterfaceDB::fetchOne(
            'SELECT * FROM accounting_periods
             WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($company) || !is_array($period)) {
            throw new \InvalidArgumentException('Select a valid company and accounting period before building iXBRL facts.');
        }
        (new YearEndLockService())->assertLocked(
            $companyId,
            $accountingPeriodId,
            'build the iXBRL facts'
        );

        $identityService = new IxbrlCompanyIdentityService();
        $company = $identityService->normalise($company);
        $identityErrors = $identityService->errors($company);
        if ($identityErrors !== []) {
            throw new \DomainException(
                'Complete the supported Companies House identity before building iXBRL facts: '
                . implode(' ', $identityErrors)
            );
        }
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $presentationCurrency = strtoupper(trim((string)($settings['default_currency'] ?? '')));
        if ($presentationCurrency !== 'GBP') {
            throw new \DomainException(
                'The current iXBRL filing profile supports presentation currency GBP only.'
            );
        }

        $disclosureService = new IxbrlAccountsDisclosureService();
        $disclosureContext = $disclosureService->fetch($companyId, $accountingPeriodId);
        if (empty($disclosureContext['complete'])) {
            $missing = array_values(array_map('strval', (array)($disclosureContext['missing_labels'] ?? [])));
            throw new \DomainException(
                'Complete the iXBRL accounts disclosures before building facts'
                . ($missing !== [] ? ': ' . implode(', ', $missing) . '.' : '.')
            );
        }
        $disclosures = (array)($disclosureContext['disclosures'] ?? []);
        foreach ([
            'prepared_under_small_companies_regime',
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
        ] as $requiredConfirmation) {
            if (empty($disclosures[$requiredConfirmation])) {
                throw new \DomainException(
                    'The supported FRS 105 unaudited micro-entity profile requires all statutory statement confirmations to be Yes.'
                );
            }
        }
        foreach ([
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
        ] as $unsupportedPositiveDisclosure) {
            if (!array_key_exists($unsupportedPositiveDisclosure, $disclosures)
                || (int)$disclosures[$unsupportedPositiveDisclosure] !== 0) {
                throw new \DomainException(
                    'The supported simple-note profile requires explicit No answers for material arrangements, director advances and guarantees, and commitments or contingencies.'
                );
            }
        }
        if (!in_array((string)($disclosures['entity_trading_status'] ?? ''), [
            'trading',
            'never_traded',
            'no_longer_trading',
        ], true)) {
            throw new \DomainException(
                'Confirm whether the entity is trading, has never traded, or is no longer trading before building iXBRL facts.'
            );
        }
        $mappingService = new IxbrlAccountsMappingService();
        $current = $mappingService->getAccountsMapping($companyId, $accountingPeriodId);
        $currentBuckets = (array)($current['buckets'] ?? []);
        $eligibility = (new IxbrlMicroEntityEligibilityService())->evaluate(
            (string)$period['period_start'],
            (string)$period['period_end'],
            (float)($currentBuckets['turnover'] ?? 0),
            (float)($currentBuckets['fixed_assets'] ?? 0)
                + (float)($currentBuckets['current_assets'] ?? 0)
                + (float)($currentBuckets['prepayments_accrued_income'] ?? 0),
            (int)($disclosures['average_number_employees'] ?? 0)
        );
        if (empty($eligibility['qualifies'])) {
            throw new \DomainException(
                'The company does not pass all three period-start FRS 105 micro-entity size thresholds. '
                . (new IxbrlMicroEntityEligibilityService())->detail($eligibility)
            );
        }

        $comparativePeriod = $this->priorLockedPeriod($companyId, (string)$period['period_start']);
        $comparative = null;
        if ($comparativePeriod !== null) {
            $comparativeDisclosureContext = $disclosureService->fetch($companyId, (int)$comparativePeriod['id']);
            $comparativeDisclosures = (array)($comparativeDisclosureContext['disclosures'] ?? []);
            $comparativeEmployees = $comparativeDisclosures['average_number_employees'] ?? null;
            if ($comparativeEmployees === null || $comparativeEmployees === '' || !is_numeric($comparativeEmployees)) {
                throw new \DomainException(
                    'Confirm the average number of employees for the prior locked accounting period before building comparative iXBRL facts.'
                );
            }
            $comparative = [
                'period' => $comparativePeriod,
                'mapping' => $mappingService->getAccountsMapping($companyId, (int)$comparativePeriod['id']),
                'disclosures' => [
                    'average_number_employees' => (int)$comparativeEmployees,
                    'revision' => (int)($comparativeDisclosures['revision'] ?? 0),
                ],
            ];
        }

        $profile = new IxbrlTaxonomyProfileService();
        $yearEnd = $this->yearEndState($companyId, $accountingPeriodId);
        $basis = [
            'basis_version' => IxbrlTaxonomyProfileService::BASIS_VERSION,
            'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
            'company' => [
                'id' => (int)$company['id'],
                'company_name' => (string)($company['company_name'] ?? ''),
                'company_number' => (string)($company['company_number'] ?? ''),
                'companies_house_type' => (string)($company['companies_house_type'] ?? ''),
                'companies_house_jurisdiction' => (string)($company['companies_house_jurisdiction'] ?? ''),
                'company_status' => (string)($company['company_status'] ?? ''),
                'registered_office_address_line_1' => (string)($company['registered_office_address_line_1'] ?? ''),
                'registered_office_address_line_2' => (string)($company['registered_office_address_line_2'] ?? ''),
                'registered_office_address_line_3' => (string)($company['registered_office_address_line_3'] ?? ''),
                'registered_office_postal_code' => (string)($company['registered_office_postal_code'] ?? ''),
                'registered_office_country' => (string)($company['registered_office_country'] ?? ''),
            ],
            'period' => [
                'id' => (int)$period['id'],
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
            ],
            'year_end' => $yearEnd,
            'disclosures' => $this->disclosureBasis($disclosures),
            'current_mapping' => $this->mappingBasis($current),
            'micro_entity_eligibility' => $eligibility,
            'presentation_currency' => $presentationCurrency,
            'comparative' => $comparative !== null ? [
                'period' => [
                    'id' => (int)$comparative['period']['id'],
                    'period_start' => (string)$comparative['period']['period_start'],
                    'period_end' => (string)$comparative['period']['period_end'],
                ],
                'mapping' => $this->mappingBasis((array)$comparative['mapping']),
                'disclosures' => (array)$comparative['disclosures'],
            ] : null,
            'taxonomy_mappings' => $profile->mappings(),
            'application_name' => trim((string)\AppConfigurationStore::get('app_name', 'EEL Accounts')),
            'application_version' => IxbrlTaxonomyProfileService::BASIS_VERSION,
        ];

        return [
            'company' => $company,
            'accounting_period' => $period,
            'disclosures' => $disclosures,
            'current' => $current,
            'comparative' => $comparative,
            'application_name' => $basis['application_name'],
            'application_version' => $basis['application_version'],
            'micro_entity_eligibility' => $eligibility,
            'presentation_currency' => $presentationCurrency,
            'basis' => $basis,
            'basis_hash' => hash('sha256', $this->canonicalJson($basis)),
        ];
    }

    private function priorLockedPeriod(int $companyId, string $periodStart): ?array
    {
        if (!\InterfaceDB::tableExists('year_end_reviews')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT ap.*
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yr
               ON yr.company_id = ap.company_id
              AND yr.accounting_period_id = ap.id
              AND yr.is_locked = 1
             WHERE ap.company_id = :company_id
               AND ap.period_end < :period_start
             ORDER BY ap.period_end DESC, ap.id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        );

        return is_array($row) ? $row : null;
    }

    private function yearEndState(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::tableExists('year_end_reviews')) {
            return ['locked' => false, 'locked_at' => null];
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT is_locked, locked_at
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return [
            'locked' => is_array($row) && !empty($row['is_locked']),
            'locked_at' => is_array($row) ? ($row['locked_at'] ?? null) : null,
        ];
    }

    private function disclosureBasis(array $row): array
    {
        $keys = [
            'accounting_standard', 'average_number_employees', 'entity_dormant', 'entity_trading_status',
            'accounts_approval_date', 'approving_director_name',
            'prepared_under_small_companies_regime', 'audit_exempt_section_477',
            'directors_acknowledge_responsibilities', 'members_have_not_required_audit',
            'micro_entity_eligibility_confirmed', 'going_concern_basis_appropriate',
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
            'revision',
        ];
        return array_intersect_key($row, array_flip($keys));
    }

    private function mappingBasis(array $mapping): array
    {
        return [
            'buckets' => (array)($mapping['buckets'] ?? []),
            'sources' => (array)($mapping['sources'] ?? []),
            'reliable_closing_balance' => !empty($mapping['reliable_closing_balance']),
            'director_loan_reporting_presentation' => (array)($mapping['director_loan_reporting_presentation'] ?? []),
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            $isList = array_is_list($item);
            if (!$isList) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            if ($isList) {
                usort($item, static fn(mixed $left, mixed $right): int => strcmp(
                    json_encode($left, JSON_UNESCAPED_SLASHES) ?: '',
                    json_encode($right, JSON_UNESCAPED_SLASHES) ?: ''
                ));
            }
            return $item;
        };

        return json_encode($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
