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
    /** Increment whenever the deterministic report-basis construction changes. */
    public const BASIS_VERSION = 'ixbrl-accounts-report-v8';

    public function build(int $companyId, int $accountingPeriodId): array
    {
        $cacheKey = \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'ixbrl.accounts-report',
            $cacheKey,
            function () use ($companyId, $accountingPeriodId): array {
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
        $taxonomyCompatibility = (new IxbrlTaxonomyCompatibilityService())->assess(
            'FRS_105',
            (string)$period['period_start'],
            (string)$period['period_end'],
            null,
            IxbrlTaxonomyProfileService::SCHEMA_REF
        );
        if (empty($taxonomyCompatibility['reporting_compatible'])) {
            throw new \DomainException(
                (string)(($taxonomyCompatibility['errors'] ?? [])[0]
                    ?? 'The selected accounting period is not compatible with the configured accounts taxonomy.')
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
        $directorLoanDisclosure = (new DirectorLoanService())->fetchDisclosureSummary($companyId, $accountingPeriodId);
        if (empty($directorLoanDisclosure['success'])) {
            throw new \DomainException(
                'The Director Loan Statement could not be read before building iXBRL facts.'
            );
        }
        $directorLoanApproval = $this->directorLoanYearEndApproval($companyId, $accountingPeriodId);
        $this->assertDirectorLoanApproval(
            $directorLoanDisclosure,
            $directorLoanApproval,
            'selected accounting period'
        );
        $directorLoanDisclosure = $this->directorOnlyDisclosure($companyId, $directorLoanDisclosure);
        $directorLoanDisclosure['party_facts'] = $this->directorPartyFacts($directorLoanApproval);
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
            $comparativeDirectorLoanDisclosure = (new DirectorLoanService())->fetchDisclosureSummary(
                $companyId,
                (int)$comparativePeriod['id']
            );
            if (empty($comparativeDirectorLoanDisclosure['success'])) {
                throw new \DomainException(
                    'The comparative Director Loan Statement could not be read before building iXBRL facts.'
                );
            }
            $comparativeDirectorLoanApproval = $this->directorLoanYearEndApproval(
                $companyId,
                (int)$comparativePeriod['id']
            );
            $this->assertDirectorLoanApproval(
                $comparativeDirectorLoanDisclosure,
                $comparativeDirectorLoanApproval,
                'comparative accounting period'
            );
            $comparativeDirectorLoanDisclosure = $this->directorOnlyDisclosure(
                $companyId,
                $comparativeDirectorLoanDisclosure
            );
            $comparativeDirectorLoanDisclosure['party_facts'] = $this->directorPartyFacts(
                $comparativeDirectorLoanApproval
            );
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
                'director_loan_disclosure' => $comparativeDirectorLoanDisclosure,
                'director_loan_year_end_approval' => $comparativeDirectorLoanApproval,
            ];
        }

        $profile = new IxbrlTaxonomyProfileService();
        $yearEnd = $this->yearEndState($companyId, $accountingPeriodId);
        $companiesHouseFiling = $this->companiesHouseFilingClassification($companyId, $accountingPeriodId);
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
            'companies_house_filing' => $companiesHouseFiling,
            'disclosures' => $this->disclosureBasis($disclosures),
            'director_loan_disclosure' => $directorLoanDisclosure,
            'director_loan_year_end_approval' => $directorLoanApproval,
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
                'director_loan_disclosure' => (array)$comparative['director_loan_disclosure'],
                'director_loan_year_end_approval' => (array)$comparative['director_loan_year_end_approval'],
            ] : null,
            'taxonomy_mappings' => $profile->mappings(),
            'taxonomy_compatibility' => [
                'policy_version' => IxbrlTaxonomyCompatibilityService::POLICY_VERSION,
                'schema_ref' => (string)(($taxonomyCompatibility['policy'] ?? [])['schema_ref'] ?? ''),
                'accounting_standard' => (string)(($taxonomyCompatibility['policy'] ?? [])['accounting_standard'] ?? ''),
                'reporting_period_start_from' => (string)(($taxonomyCompatibility['policy'] ?? [])['reporting_period_start_from'] ?? ''),
                'reporting_period_end_to' => ($taxonomyCompatibility['policy'] ?? [])['reporting_period_end_to'] ?? null,
            ],
            'application_name' => trim((string)\AppConfigurationStore::get('app_name', 'EEL Accounts')),
            'application_version' => IxbrlTaxonomyProfileService::BASIS_VERSION,
        ];

        return [
            'company' => $company,
            'accounting_period' => $period,
            'disclosures' => $disclosures,
            'director_loan_disclosure' => $directorLoanDisclosure,
            'director_loan_year_end_approval' => $directorLoanApproval,
            'current' => $current,
            'comparative' => $comparative,
            'application_name' => $basis['application_name'],
            'application_version' => $basis['application_version'],
            'basis_version' => self::BASIS_VERSION,
            'taxonomy_compatibility' => $taxonomyCompatibility,
            'micro_entity_eligibility' => $eligibility,
            'presentation_currency' => $presentationCurrency,
            'companies_house_filing' => $companiesHouseFiling,
            'basis' => $basis,
            'basis_hash' => hash('sha256', $this->canonicalJson($basis)),
        ];
            }
        );
    }

    private function companiesHouseFilingClassification(int $companyId, int $accountingPeriodId): array
    {
        $review = (new YearEndSectionApprovalService())->fetchCompaniesHouseReview(
            $companyId,
            $accountingPeriodId
        );
        $comparison = (array)(($review['display'] ?? [])['comparison'] ?? []);
        $filingKind = strtolower(trim((string)($comparison['filing_kind'] ?? '')));
        if (empty($review['available'])
            || empty($review['acknowledgement_current'])
            || !in_array($filingKind, ['original', 'revised'], true)) {
            throw new \DomainException(
                'Approve the current Companies House Original/Revised filing classification before building iXBRL facts.'
            );
        }

        $acknowledgement = (array)($review['acknowledgement'] ?? []);
        return [
            'filing_kind' => $filingKind,
            'filing_reason' => (string)($comparison['filing_reason'] ?? ''),
            'filing_evidence' => (array)($comparison['filing_evidence'] ?? []),
            'correction_required' => (int)(($review['display'] ?? [])['mismatch_count'] ?? 0) > 0,
            'check_code' => (string)($review['check_code'] ?? ''),
            'approval_basis_version' => (string)($acknowledgement['basis_version'] ?? ''),
            'approval_basis_hash' => (string)($acknowledgement['basis_hash'] ?? ''),
            'approved_at' => (string)($review['acknowledged_at'] ?? ''),
            'approved_by' => (string)($review['acknowledged_by'] ?? ''),
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
            'principal_activity_sic_code', 'principal_activity_statement',
            'accounts_approval_date', 'approving_director_id', 'approving_director_name',
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

    /**
     * Freeze the exact Director Loan Year End approval which authorised the
     * locked reporting position. The acknowledgement hash is deliberately in
     * the report basis so a replacement approval makes existing facts stale.
     *
     * @return array<string, mixed>
     */
    private function directorLoanYearEndApproval(int $companyId, int $accountingPeriodId): array
    {
        $acknowledgement = (new YearEndAcknowledgementService())->fetch(
            $companyId,
            $accountingPeriodId,
            DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE
        );
        if (!is_array($acknowledgement)) {
            return [
                'available' => false,
                'check_code' => DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE,
                'basis_version' => '',
                'basis_hash' => '',
                'party_facts' => [],
            ];
        }

        $basis = json_decode((string)($acknowledgement['basis_json'] ?? ''), true);
        $facts = is_array($basis) ? (array)($basis['facts'] ?? []) : [];
        $partyFacts = (array)($facts['party_facts'] ?? $facts['director_facts'] ?? []);

        return [
            'available' => true,
            'check_code' => DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE,
            'basis_version' => (string)($acknowledgement['basis_version'] ?? ''),
            'basis_hash' => (string)($acknowledgement['basis_hash'] ?? ''),
            'acknowledged_at' => (string)($acknowledgement['acknowledged_at'] ?? ''),
            'party_facts' => array_values(array_filter(
                $partyFacts,
                static fn(mixed $row): bool => is_array($row)
            )),
        ];
    }

    private function assertDirectorLoanApproval(array $disclosure, array $approval, string $periodLabel): void
    {
        if (empty($disclosure['has_activity'])) {
            return;
        }

        $hash = trim((string)($approval['basis_hash'] ?? ''));
        if (empty($approval['available'])
            || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1
            || (string)($approval['basis_version'] ?? '') !== YearEndSectionApprovalService::CONTRACT_VERSION) {
            throw new \DomainException(
                'Approve the Director Loan Year End review for the ' . $periodLabel
                . ' before building iXBRL facts.'
            );
        }
    }

    /**
     * The Companies House directors-reporting taxonomy is narrower than the
     * participator-loan subledger. Only parties linked to a company director
     * may contribute to direp:* narrative and numeric facts.
     *
     * @return array<string, mixed>
     */
    private function directorOnlyDisclosure(int $companyId, array $summary): array
    {
        $links = [];
        if ($companyId > 0 && \InterfaceDB::tableExists('company_parties')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT p.id AS party_id,
                        p.linked_director_id,
                        p.legal_name,
                        cd.full_name AS director_name
                 FROM company_parties p
                 LEFT JOIN company_directors cd
                   ON cd.id = p.linked_director_id
                  AND cd.company_id = p.company_id
                 WHERE p.company_id = :company_id
                   AND p.linked_director_id IS NOT NULL',
                ['company_id' => $companyId]
            ) as $row) {
                $partyId = (int)($row['party_id'] ?? 0);
                $directorId = (int)($row['linked_director_id'] ?? 0);
                if ($partyId <= 0 || $directorId <= 0) {
                    continue;
                }
                $links[$partyId] = [
                    'linked_director_id' => $directorId,
                    'director_name' => trim((string)($row['director_name'] ?? '')) !== ''
                        ? trim((string)$row['director_name'])
                        : trim((string)($row['legal_name'] ?? '')),
                ];
            }
        }

        return $this->filterDirectorLoanDisclosure($summary, $links);
    }

    /**
     * @param array<int, array{linked_director_id: int, director_name?: string}> $links
     * @return array<string, mixed>
     */
    private function filterDirectorLoanDisclosure(array $summary, array $links): array
    {
        $directorDisclosures = [];
        foreach ((array)($summary['disclosures'] ?? []) as $disclosure) {
            if (!is_array($disclosure)) {
                continue;
            }
            $partyId = (int)($disclosure['party_id'] ?? $disclosure['director_id'] ?? 0);
            $link = $links[$partyId] ?? null;
            if (!is_array($link) || (int)($link['linked_director_id'] ?? 0) <= 0) {
                continue;
            }
            $directorDisclosures[] = array_merge($disclosure, [
                'party_id' => $partyId,
                'director_id' => (int)$link['linked_director_id'],
                'linked_director_id' => (int)$link['linked_director_id'],
                'is_director' => true,
                'director_name' => trim((string)($link['director_name'] ?? '')) !== ''
                    ? trim((string)$link['director_name'])
                    : (string)($disclosure['director_name'] ?? ''),
            ]);
        }

        $summary['disclosures'] = $directorDisclosures;
        $summary['has_company_to_director_exposure'] = !empty(array_filter(
            $directorDisclosures,
            static fn(array $row): bool => !array_key_exists('section_413_required', $row)
                || !empty($row['section_413_required'])
        ));
        $summary['director_party_count'] = count($directorDisclosures);
        foreach ([
            'total_advances' => 'advances',
            'total_cash_repayments' => 'cash_repayments',
            'total_amounts_legally_set_off' => 'amounts_legally_set_off',
            'total_amounts_written_off' => 'amounts_written_off',
            'total_amounts_waived' => 'amounts_waived',
            'total_unclassified_reductions' => 'unclassified_reductions',
            'total_repayments' => 'repayments',
            'total_director_funding' => 'director_funding',
            'closing_company_to_director_balance' => 'closing_company_to_director_balance',
            'closing_company_liability' => 'closing_company_liability',
        ] as $totalKey => $rowKey) {
            $summary[$totalKey] = round(array_sum(array_map(
                static fn(array $row): float => (float)($row[$rowKey] ?? 0),
                $directorDisclosures
            )), 2);
        }
        $summary['has_unclassified_reductions'] = (float)$summary['total_unclassified_reductions'] >= 0.005;

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function directorPartyFacts(array $approval): array
    {
        return array_values(array_filter(
            (array)($approval['party_facts'] ?? []),
            static fn(mixed $row): bool => is_array($row)
                && (!empty($row['is_director']) || (int)($row['linked_director_id'] ?? 0) > 0)
        ));
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
                    \eel_accounts\Support\Utf8::json($left, JSON_UNESCAPED_SLASHES) ?: '',
                    \eel_accounts\Support\Utf8::json($right, JSON_UNESCAPED_SLASHES) ?: ''
                ));
            }
            return $item;
        };

        return \eel_accounts\Support\Utf8::json($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
