<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountingGuidanceService
{
    public function createPeriodsFromCompaniesHouseFiledPeriods(int $companyId, ?string $companyNumber = null): array
    {
        $result = [
            'filed_period_count' => 0,
            'created_count' => 0,
            'existing_count' => 0,
            'skipped_overlap_count' => 0,
            'created_periods' => [],
            'existing_periods' => [],
            'skipped_overlap_periods' => [],
        ];

        if ($companyId <= 0) {
            return $result;
        }

        $documentRepository = new CompaniesHouseDocumentRepository();
        $taxYearRepository = new TaxYearRepository();
        $filedPeriods = $documentRepository->fetchFiledAccountingPeriods($companyId, $companyNumber);
        $result['filed_period_count'] = count($filedPeriods);

        $findExact = InterfaceDB::prepare(
            'SELECT id
             FROM tax_years
             WHERE company_id = ?
               AND period_start = ?
               AND period_end = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $findOverlap = InterfaceDB::prepare(
            'SELECT COUNT(*)
             FROM tax_years
             WHERE company_id = ?
               AND NOT (period_end < ? OR period_start > ?)
               AND NOT (period_start = ? AND period_end = ?)'
        );

        foreach ($filedPeriods as $period) {
            $periodStart = (string)$period['period_start'];
            $periodEnd = (string)$period['period_end'];

            $findExact->execute([$companyId, $periodStart, $periodEnd]);
            $existingId = $findExact->fetchColumn();

            if ($existingId !== false) {
                $result['existing_count']++;
                $result['existing_periods'][] = $period;
                continue;
            }

            $findOverlap->execute([$companyId, $periodStart, $periodEnd, $periodStart, $periodEnd]);

            if ((int)$findOverlap->fetchColumn() > 0) {
                $result['skipped_overlap_count']++;
                $result['skipped_overlap_periods'][] = $period;
                continue;
            }

            $taxYearRepository->createPeriod($companyId, $periodStart, $periodEnd);
            $result['created_count']++;
            $result['created_periods'][] = $period;
        }

        return $result;
    }

    public function build(?int $companyId = null): array
    {
        $accountingContext = new AccountingContextService();
        $companyId = HelperFramework::sanitiseId($companyId, $accountingContext->companyId());
        $taxYearId = HelperFramework::sanitiseId($accountingContext->taxYearId());
        $companyRepository = new CompanyRepository();
        $taxYearRepository = new TaxYearRepository();
        $company = $companyId > 0 ? $companyRepository->fetchCompanyDetails($companyId) : null;
        $accountingPeriods = $companyId > 0 ? $taxYearRepository->fetchTaxYears($companyId) : [];
        $selectedTaxYear = $companyId > 0 && $taxYearId > 0
            ? $taxYearRepository->fetchTaxYear($companyId, $taxYearId)
            : null;
        $incorporationDate = trim((string)($company['incorporation_date'] ?? ''));
        $companyNumber = trim((string)($company['company_number'] ?? ''));
        $periodStart = trim((string)($selectedTaxYear['period_start'] ?? ''));
        $periodEnd = trim((string)($selectedTaxYear['period_end'] ?? ''));

        $guidance = [
            'incorporation_date' => $incorporationDate,
            'incorporation_date_display' => '',
            'filed_periods' => [],
            'latest_filed_period_end' => '',
            'latest_filed_period_end_display' => '',
            'suggestion_basis' => '',
            'suggested_periods' => [],
            'missing_suggested_periods' => [],
            'ct_periods' => [],
            'ct600_summary' => '',
            'coverage' => ['months' => [], 'missing_months' => [], 'outside_period_count' => 0],
            'messages' => [],
        ];

        $guidance['incorporation_date_display'] = $guidance['incorporation_date'] !== ''
            ? HelperFramework::displayDate($guidance['incorporation_date'])
            : '';

        $suggester = new TaxPeriodService();
        $documentRepository = new CompaniesHouseDocumentRepository();
        $guidance['filed_periods'] = $documentRepository->fetchFiledAccountingPeriods($companyId, $companyNumber);

        if ($guidance['filed_periods'] !== []) {
            $latestFiledPeriod = end($guidance['filed_periods']);
            $guidance['latest_filed_period_end'] = (string)($latestFiledPeriod['period_end'] ?? '');
            $guidance['latest_filed_period_end_display'] = $guidance['latest_filed_period_end'] !== ''
                ? HelperFramework::displayDate($guidance['latest_filed_period_end'])
                : '';
            $guidance['suggestion_basis'] = 'companies_house_filed_periods';
            $guidance['suggested_periods'] = $suggester->suggestFollowOnPeriodsThroughDate(
                new DateTimeImmutable($guidance['latest_filed_period_end']),
                new DateTimeImmutable('today'),
                $companyId
            );
        } elseif ($incorporationDate !== '') {
            $guidance['suggestion_basis'] = 'incorporation_date';
            $guidance['suggested_periods'] = $suggester->suggestPeriodsThroughDate(
                new DateTimeImmutable($incorporationDate),
                new DateTimeImmutable('today'),
                $companyId
            );
        } else {
            $guidance['messages'][] = 'No incorporation date or filed iXBRL accounting periods are stored yet, so accounting-period guidance is limited.';

            return $guidance;
        }

        $guidance['missing_suggested_periods'] = $suggester->missingSuggestedPeriods($accountingPeriods, $guidance['suggested_periods']);

        foreach ($guidance['suggested_periods'] as &$period) {
            $period['display_range'] = HelperFramework::accountingPeriodLabel(
                (string)$period['start'],
                (string)$period['end']
            );
        }
        unset($period);

        if (!empty($guidance['missing_suggested_periods'])) {
            if ($guidance['suggestion_basis'] === 'companies_house_filed_periods') {
                $guidance['messages'][] = 'Suggested accounting periods now continue from the latest imported Companies House filed period, so only periods after the filed accounts are proposed.';
            } else {
                $guidance['messages'][] = 'Suggested accounting periods are based on the incorporation date and the month-end of the anniversary month. Confirm them before relying on them for filing.';
            }
        }

        if ($periodStart !== '' && $periodEnd !== '') {
            $guidance['ct_periods'] = $suggester->derive($periodStart, $periodEnd, $companyId);

            foreach ($guidance['ct_periods'] as &$period) {
                $period['display_range'] = HelperFramework::accountingPeriodLabel(
                    (string)$period['start'],
                    (string)$period['end']
                );
            }
            unset($period);
            $guidance['ct600_summary'] = count($guidance['ct_periods']) === 1
                ? 'This accounting period needs 1 CT600 return.'
                : 'This accounting period needs ' . count($guidance['ct_periods']) . ' CT600 returns because HMRC accounting periods cannot exceed 12 months.';

            $coverageService = new AccountingPeriodCoverageService();
            $guidance['coverage'] = $coverageService->summarise(
                $companyId,
                $taxYearId,
                $periodStart,
                $periodEnd
            );

            if (!empty($guidance['coverage']['missing_months'])) {
                $guidance['messages'][] = 'Some months inside the selected accounting period currently have no uploaded transactions.';
            }

            if (($guidance['coverage']['outside_period_count'] ?? 0) > 0) {
                $guidance['messages'][] = 'Some transactions linked to this accounting period currently sit outside the selected dates.';
            }

            $guidance['messages'][] = 'Editing the accounting period may affect both Companies House and HMRC obligations. Confirm the historic year-end before saving changes.';
        }

        if (count($guidance['messages']) > 1) {
            $guidance['messages'] = [implode(' ', $guidance['messages'])];
        }

        return $guidance;
    }
}
