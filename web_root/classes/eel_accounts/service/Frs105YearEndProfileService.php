<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Pre-lock readiness for the application's sole supported accounts profile. */
final class Frs105YearEndProfileService
{
    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        $period = $companyId > 0 && $accountingPeriodId > 0
            ? \InterfaceDB::fetchOne(
                'SELECT * FROM accounting_periods WHERE id = :id AND company_id = :company_id LIMIT 1',
                ['id' => $accountingPeriodId, 'company_id' => $companyId]
            )
            : null;
        if (!is_array($period)) {
            return ['available' => false, 'pass' => false, 'errors' => ['Select a valid accounting period before checking the FRS 105 profile.'], 'checks' => []];
        }

        $disclosures = (new IxbrlAccountsDisclosureService())->fetch($companyId, $accountingPeriodId);
        $row = (array)($disclosures['disclosures'] ?? []);

        $microEligibility = null;
        $microError = '';
        if (is_numeric($row['average_number_employees'] ?? null)) {
            try {
                $mapping = (new IxbrlAccountsMappingService())->getAccountsMapping($companyId, $accountingPeriodId);
                $buckets = (array)($mapping['buckets'] ?? []);
                $microEligibility = (new IxbrlMicroEntityEligibilityService())->evaluate(
                    (string)$period['period_start'],
                    (string)$period['period_end'],
                    (float)($buckets['turnover'] ?? 0),
                    (float)($buckets['fixed_assets'] ?? 0)
                        + (float)($buckets['current_assets'] ?? 0)
                        + (float)($buckets['prepayments_accrued_income'] ?? 0),
                    (int)$row['average_number_employees']
                );
            } catch (\Throwable $exception) {
                $microError = $exception->getMessage();
            }
        }
        $deferredTax = (new Frs105ValidationService())->deferredTaxNominalExposure($companyId, $accountingPeriodId);
        return $this->evaluate($disclosures, $microEligibility, $deferredTax, $microError);
    }

    /**
     * @param array<string, mixed> $disclosures
     * @param null|array<string, mixed> $microEligibility
     * @param array<string, mixed> $deferredTax
     */
    public function evaluate(array $disclosures, ?array $microEligibility, array $deferredTax, string $microError = ''): array
    {
        $row = (array)($disclosures['disclosures'] ?? []);
        $checks = [];
        $checks[] = $this->check(
            'frs105_accounting_standard',
            (string)($row['accounting_standard'] ?? '') === IxbrlAccountsDisclosureService::ACCOUNTING_STANDARD_FRS_105,
            'The accounts profile is fixed to FRS 105.',
            'Complete the FRS 105 accounts disclosures.'
        );
        $checks[] = $this->check(
            'ordinary_uk_trading_profile',
            (string)($row['entity_trading_status'] ?? '') === 'trading' && empty($row['entity_dormant']),
            'Journal evidence and disclosures identify an active trading company.',
            'The supported profile requires an active, non-dormant trading company.'
        );
        $checks[] = $this->check(
            'frs105_disclosures_supported',
            !empty($disclosures['complete']) && !empty($disclosures['profile_supported']),
            'The required FRS 105 micro-entity disclosures are complete and supported.',
            (string)(($disclosures['profile_errors'] ?? $disclosures['errors'] ?? [])[0]
                ?? ('Complete: ' . implode(', ', (array)($disclosures['missing_labels'] ?? [])) . '.'))
        );
        $microPass = is_array($microEligibility)
            && !empty($microEligibility['qualifies'])
            && (int)($row['micro_entity_eligibility_confirmed'] ?? 0) === 1;
        $checks[] = $this->check(
            'frs105_micro_entity_eligibility',
            $microPass,
            is_array($microEligibility) ? (new IxbrlMicroEntityEligibilityService())->detail($microEligibility) : '',
            $microError !== '' ? $microError : 'Confirm the employee count and FRS 105 micro-entity eligibility.'
        );
        $hasJournalValue = abs((float)($deferredTax['total_debit'] ?? 0)) + abs((float)($deferredTax['total_credit'] ?? 0)) >= 0.005;
        $checks[] = $this->check(
            'frs105_deferred_tax_journal_value',
            !$hasJournalValue,
            'No posted journal value is assigned to deferred tax.',
            (string)($deferredTax['detail'] ?? 'FRS 105 does not support deferred-tax journal value.')
        );

        $failed = array_values(array_filter($checks, static fn(array $check): bool => empty($check['pass'])));
        return [
            'available' => true,
            'pass' => $failed === [],
            'errors' => array_map(static fn(array $check): string => (string)$check['detail'], $failed),
            'checks' => $checks,
            'disclosures' => $disclosures,
            'micro_entity_eligibility' => $microEligibility,
        ];
    }

    private function check(string $code, bool $pass, string $passDetail, string $failDetail): array
    {
        return ['code' => $code, 'pass' => $pass, 'detail' => $pass ? $passDetail : $failDetail];
    }
}
