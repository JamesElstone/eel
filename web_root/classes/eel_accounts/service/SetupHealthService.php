<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class SetupHealthService
{
    public function buildContext(int $companyId): array
    {
        $companyRepository = new \eel_accounts\Repository\CompanyRepository();
        $accountingPeriodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
        $nominalAccountRepository = new \eel_accounts\Repository\NominalAccountRepository();
        $companySettingsService = new \eel_accounts\Service\CompanySettingsService();

        $dbStatus = $this->buildDatabaseStatus();
        $companies = $companyRepository->fetchCompanies();
        $settings = $companyId > 0
            ? $companySettingsService->loadFromDatabase(new \eel_accounts\Store\CompanySettingsStore($companyId), $companyId, 0)
            : \eel_accounts\Store\CompanySettingsStore::defaults();
        $accountingPeriods = $accountingPeriodRepository->fetchAccountingPeriods($companyId);
        $nominalAccounts = $nominalAccountRepository->fetchNominalAccounts($companyId);
        $accountingPeriodStatus = $this->buildAccountingPeriodStatus($companyId, $accountingPeriods);
        $setupHealthItems = $this->buildSetupHealthItems(
            $dbStatus,
            $companies,
            $accountingPeriods,
            $nominalAccounts,
            $settings,
            $companySettingsService->hasCompanySettingsRow($companyId),
            $accountingPeriodStatus
        );

        return [
            'installation_setup_health_items' => $this->filterSetupHealthItems(
                $setupHealthItems,
                ['Database connection']
            ),
            'company_setup_health_items' => $this->filterSetupHealthItems(
                $setupHealthItems,
                ['Company', 'Selected company', 'Tax years', 'Nominal accounts', 'Default nominals', 'Company Settings', 'Corporation tax UTR']
            ),
        ];
    }

    private function buildDatabaseStatus(): array
    {
        try {
            $driverName = \InterfaceDB::driverName();
            $serverVersion = trim(\InterfaceDB::getServerVersion());

            return [
                'connected' => true,
                'message' => 'Connected using ' . strtoupper($driverName) . ($serverVersion !== '' ? ' (' . $serverVersion . ').' : '.'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'connected' => false,
                'message' => $throwable->getMessage() !== ''
                    ? $throwable->getMessage()
                    : 'The database connection could not be established.',
            ];
        }
    }

    private function filterSetupHealthItems(array $items, array $titles): array
    {
        $allowed = array_flip($titles);

        return array_values(array_filter(
            $items,
            static fn(array $item): bool => isset($allowed[$item['title'] ?? ''])
        ));
    }

    private function buildSetupHealthItems(
        array $dbStatus,
        array $companies,
        array $accountingPeriods,
        array $nominalAccounts,
        array $settings,
        bool $hasCompanySettings,
        ?array $accountingPeriodStatus = null
    ): array {
        $selectedCompanyLoaded = ($settings['company_id'] ?? '') !== '' && ($settings['company_name'] ?? '') !== '';
        $accountingPeriodStatus = $accountingPeriodStatus ?? $this->buildAccountingPeriodStatus(0, $accountingPeriods);
        $defaultNominalStatus = $this->buildDefaultNominalStatus($settings, $nominalAccounts);
        $utrSet = (int)($settings['utr'] ?? 0) > 0;

        return [
            [
                'ok' => !empty($dbStatus['connected']),
                'title' => 'Database connection',
                'detail' => (string)($dbStatus['message'] ?? ''),
            ],
            [
                'ok' => count($companies) > 0,
                'title' => 'Company',
                'detail' => count($companies) > 0
                    ? count($companies) . ' compan' . (count($companies) === 1 ? 'y' : 'ies') . ' available.'
                    : 'No companies found yet.',
            ],
            [
                'ok' => $selectedCompanyLoaded,
                'title' => 'Selected company',
                'detail' => $selectedCompanyLoaded
                    ? 'Loaded: ' . (string)$settings['company_name']
                    : 'No company is selected.',
            ],
            [
                'ok' => (string)($accountingPeriodStatus['state'] ?? 'bad') === 'ok',
                'state' => (string)($accountingPeriodStatus['state'] ?? 'bad'),
                'title' => 'Tax years',
                'detail' => (string)($accountingPeriodStatus['detail'] ?? 'No accounting periods defined.'),
            ],
            [
                'ok' => count($nominalAccounts) > 0,
                'title' => 'Nominal accounts',
                'detail' => count($nominalAccounts) > 0
                    ? count($nominalAccounts) . ' nominal account' . (count($nominalAccounts) === 1 ? '' : 's') . ' loaded.'
                    : 'No nominal accounts are available yet.',
            ],
            [
                'ok' => (string)($defaultNominalStatus['state'] ?? 'bad') === 'ok',
                'state' => (string)($defaultNominalStatus['state'] ?? 'bad'),
                'title' => 'Default nominals',
                'detail' => (string)($defaultNominalStatus['detail'] ?? 'No default nominals have been assigned for the selected company.'),
            ],
            [
                'ok' => $hasCompanySettings,
                'title' => 'Company Settings',
                'detail' => $hasCompanySettings
                    ? 'Settings data is present for the selected company.'
                    : 'No saved company settings were detected for the selected company.',
            ],
            [
                'ok' => $utrSet,
                'title' => 'Corporation tax UTR',
                'detail' => $utrSet
                    ? 'A UTR is saved for the selected company.'
                    : 'No Corporation Tax UTR is saved for the selected company.',
            ],
        ];
    }

    private function buildDefaultNominalStatus(array $settings, array $nominalAccounts): array
    {
        $requiredKeys = [
            'default_bank_nominal_id' => 'Default bank',
            'default_trade_nominal_id' => 'Default trade',
            'default_expense_nominal_id' => 'Expense claims payable',
            'director_loan_asset_nominal_id' => 'Director loan asset',
            'director_loan_liability_nominal_id' => 'Director loan liability',
            'vat_nominal_id' => 'VAT control',
            'uncategorised_nominal_id' => 'Fallback uncategorised',
            'corporation_tax_expense_nominal_id' => 'Corporation Tax expense',
            'corporation_tax_liability_nominal_id' => 'Corporation Tax liability',
        ];
        $validNominalIds = [];

        foreach ($nominalAccounts as $nominalAccount) {
            $id = (int)($nominalAccount['id'] ?? 0);
            if ($id > 0) {
                $validNominalIds[$id] = true;
            }
        }

        $assignedCount = 0;
        $missingLabels = [];

        foreach ($requiredKeys as $key => $label) {
            $nominalId = (int)($settings[$key] ?? 0);
            if ($nominalId > 0 && isset($validNominalIds[$nominalId])) {
                $assignedCount++;
                continue;
            }

            $missingLabels[] = $label;
        }

        $requiredCount = count($requiredKeys);
        if ($assignedCount < 1) {
            return [
                'state' => 'bad',
                'detail' => 'No default nominals have been created and assigned.',
            ];
        }

        if ($assignedCount < $requiredCount) {
            return [
                'state' => 'warn',
                'detail' => $assignedCount . ' of ' . $requiredCount . ' default nominals are assigned. Missing: ' . implode(', ', $missingLabels) . '.',
            ];
        }

        return [
            'state' => 'ok',
            'detail' => 'All default nominals have been created and assigned.',
        ];
    }

    private function buildAccountingPeriodStatus(int $companyId, array $accountingPeriods): array
    {
        $accountingPeriodCount = count($accountingPeriods);

        if ($accountingPeriodCount < 1) {
            return [
                'state' => 'bad',
                'detail' => 'No accounting periods defined.',
            ];
        }

        $gapCount = $this->countAccountingPeriodGaps($accountingPeriods);
        $missingCount = 0;

        if ($companyId > 0) {
            $guidance = (new \eel_accounts\Service\AccountingGuidanceService())->build($companyId);
            $missingCount = count((array)($guidance['missing_suggested_periods'] ?? []));
        } elseif (!$this->allRowsHavePeriodDates($accountingPeriods)) {
            $missingCount = 1;
        }

        if ($missingCount > 0 || $gapCount > 0) {
            $parts = [];
            $parts[] = $accountingPeriodCount . ' accounting period' . ($accountingPeriodCount === 1 ? '' : 's') . ' defined, but there ' . ($gapCount === 1 ? 'is an ' : 'are ') . 'accounting gap' . ($gapCount === 1 ? '' : 's') . '.';

            if ($missingCount > 0) {
                $parts[] = $missingCount . ' more accounting period' . ($missingCount === 1 ? ' is ' : 's are ') . 'recommended.';
            }

            if ($gapCount > 0) {
                $parts[] = $gapCount . ' gap' . ($gapCount === 1 ? '' : 's') . ' found between accounting periods.';
            }

            return [
                'state' => 'warn',
                'detail' => implode(' ', $parts),
            ];
        }

        return [
            'state' => 'ok',
            'detail' => 'All possible accounting periods are defined.',
        ];
    }

    private function countAccountingPeriodGaps(array $accountingPeriods): int
    {
        $periods = [];

        foreach ($accountingPeriods as $accountingPeriod) {
            $start = trim((string)($accountingPeriod['period_start'] ?? ''));
            $end = trim((string)($accountingPeriod['period_end'] ?? ''));

            if ($start === '' || $end === '') {
                continue;
            }

            $periods[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        if (count($periods) < 2) {
            return 0;
        }

        usort($periods, static fn(array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]);

        $gaps = 0;
        for ($index = 1, $count = count($periods); $index < $count; $index++) {
            try {
                $previousEnd = new \DateTimeImmutable((string)$periods[$index - 1]['end']);
                $currentStart = new \DateTimeImmutable((string)$periods[$index]['start']);
            } catch (\Throwable) {
                $gaps++;
                continue;
            }

            if ($currentStart->format('Y-m-d') !== $previousEnd->modify('+1 day')->format('Y-m-d')) {
                $gaps++;
            }
        }

        return $gaps;
    }

    private function allRowsHavePeriodDates(array $accountingPeriods): bool
    {
        foreach ($accountingPeriods as $accountingPeriod) {
            if (trim((string)($accountingPeriod['period_start'] ?? '')) === '' || trim((string)($accountingPeriod['period_end'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
