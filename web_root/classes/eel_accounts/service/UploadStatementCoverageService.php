<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class UploadStatementCoverageService
{
    public function __construct(
        private readonly \eel_accounts\Service\StatementUploadService $statementUploadService,
        private readonly \eel_accounts\Service\BankingReconciliationService $bankingReconciliationService,
    ) {
    }

    public function buildHeatmapOptions(int $companyId, int $accountingPeriodId): array
    {
        $companyId = \HelperFramework::sanitiseId($companyId);
        $accountingPeriodId = \HelperFramework::sanitiseId($accountingPeriodId);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);

        if ($accountingPeriod === null) {
            return [];
        }

        $monthStatus = $this->statementUploadService->buildMonthStatus($companyId, $accountingPeriodId);
        $reconciliationPanels = $this->bankingReconciliationService->fetchBankAccountPanelsWithAdjacentStatements($companyId, $accountingPeriodId, 0);
        $uniqueUploadedRowsByMonth = $this->statementUploadService->buildUniqueUploadedRowsByMonth($companyId, $accountingPeriodId);
        $options = $this->buildOptionsFromInputs(
            $accountingPeriod,
            $monthStatus,
            $reconciliationPanels,
            $uniqueUploadedRowsByMonth
        );

        $accountHeatmaps = $this->buildAccountHeatmapOptions(
            $accountingPeriod,
            $reconciliationPanels,
            $this->buildUniqueUploadedRowsByAccountMonth($companyId, $accountingPeriodId, $accountingPeriod),
            $this->buildCommittedTransactionsByAccountMonth($companyId, $accountingPeriod)
        );

        if ($accountHeatmaps !== []) {
            $options['account_heatmaps'] = $accountHeatmaps;
        }

        return $options;
    }

    private function buildOptionsFromInputs(array $accountingPeriod, array $monthStatus, array $reconciliationPanels, array $uniqueUploadedRowsByMonth = []): array
    {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if ($periodStart === '' || $periodEnd === '') {
            return [];
        }

        $months = [];
        foreach ($monthStatus as $month) {
            if (!is_array($month)) {
                continue;
            }

            $monthKey = trim((string)($month['month_key'] ?? ''));
            if ($monthKey === '') {
                continue;
            }

            if (array_key_exists($monthKey, $uniqueUploadedRowsByMonth)) {
                $month['raw_rows'] = (int)$uniqueUploadedRowsByMonth[$monthKey];
            }

            $months[$monthKey] = $this->heatmapMonthFromStatus($month);
        }

        foreach ($this->balanceContinuitySignalsByMonth($reconciliationPanels, $periodStart, $periodEnd) as $monthKey => $signals) {
            if (!isset($months[$monthKey])) {
                continue;
            }

            $hasFail = in_array('fail', array_column($signals, 'status'), true);
            if ($hasFail) {
                $months[$monthKey]['status'] = 'fail';
            } elseif ($months[$monthKey]['status'] !== 'fail' && (int)($months[$monthKey]['value'] ?? 0) === 0) {
                $months[$monthKey]['status'] = 'pass';
            }

            $months[$monthKey]['tooltip'] .= ' ' . implode(' ', array_map(
                static fn(array $signal): string => (string)$signal['message'],
                $signals
            ));
        }

        foreach ($this->reconciliationIssuesByMonth($reconciliationPanels) as $monthKey => $issues) {
            if (!isset($months[$monthKey])) {
                continue;
            }

            $hasFail = in_array('fail', array_column($issues, 'status'), true);
            $months[$monthKey]['status'] = $hasFail ? 'fail' : ($months[$monthKey]['status'] === 'fail' ? 'fail' : 'warning');
            $months[$monthKey]['tooltip'] .= ' ' . implode(' ', array_map(
                static fn(array $issue): string => (string)$issue['message'],
                $issues
            ));
        }

        return [
            'id' => 'uploads-statement-coverage',
            'label' => 'Statement coverage',
            'start' => $periodStart,
            'end' => $periodEnd,
            'months' => array_values($months),
            'missing_status' => 'fail',
            'empty_message' => 'Select a company and accounting period to see statement coverage.',
            'legend' => [
                'pass' => 'Covered',
                'warning' => 'Needs review',
                'fail' => 'Gap',
                'muted' => 'No data',
            ],
        ];
    }

    private function heatmapMonthFromStatus(array $month): array
    {
        $monthKey = (string)($month['month_key'] ?? '');
        $label = $this->heatmapMonthLabel($monthKey, (string)($month['label'] ?? ''));
        $rawRows = (int)($month['raw_rows'] ?? 0);
        $transactions = (int)($month['transactions'] ?? 0);
        $status = $rawRows > 0 ? 'pass' : 'warning';

        $tooltip = $rawRows > 0 || $transactions > 0
            ? sprintf(
                '%s: %d uploaded row(s), %d committed transaction(s).',
                $label,
                $rawRows,
                $transactions
            )
            : sprintf('%s: no uploaded CSV rows or committed transactions found.', $label);

        return [
            'month_key' => $monthKey,
            'label' => $label,
            'status' => $status,
            'value' => $rawRows,
            'display_value' => '(' . $rawRows . ')',
            'tooltip' => $tooltip,
        ];
    }

    private function buildAccountHeatmapOptions(array $accountingPeriod, array $reconciliationPanels, array $uniqueRowsByAccountMonth, array $transactionsByAccountMonth): array
    {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if ($periodStart === '' || $periodEnd === '') {
            return [];
        }

        $heatmaps = [];

        foreach ($reconciliationPanels as $panel) {
            if (!is_array($panel)) {
                continue;
            }

            $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
            if ((string)($account['account_type'] ?? '') !== \eel_accounts\Service\CompanyAccountService::TYPE_BANK) {
                continue;
            }

            $accountId = (int)($account['id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }

            $accountLabel = $this->accountLabel($account);
            $months = $this->baseAccountHeatmapMonths(
                $periodStart,
                $periodEnd,
                $accountLabel,
                (array)($uniqueRowsByAccountMonth[$accountId] ?? []),
                (array)($transactionsByAccountMonth[$accountId] ?? [])
            );

            foreach ($this->balanceContinuitySignalsByMonth([$panel], $periodStart, $periodEnd) as $monthKey => $signals) {
                if (!isset($months[$monthKey])) {
                    continue;
                }

                $hasFail = in_array('fail', array_column($signals, 'status'), true);
                if ($hasFail) {
                    $months[$monthKey]['status'] = 'fail';
                } elseif ($months[$monthKey]['status'] !== 'fail' && (int)($months[$monthKey]['value'] ?? 0) === 0) {
                    $months[$monthKey]['status'] = 'pass';
                }

                $months[$monthKey]['tooltip'] .= ' ' . implode(' ', array_map(
                    static fn(array $signal): string => (string)$signal['message'],
                    $signals
                ));
            }

            foreach ($this->reconciliationIssuesByMonth([$panel]) as $monthKey => $issues) {
                if (!isset($months[$monthKey])) {
                    continue;
                }

                $hasFail = in_array('fail', array_column($issues, 'status'), true);
                $months[$monthKey]['status'] = $hasFail ? 'fail' : ($months[$monthKey]['status'] === 'fail' ? 'fail' : 'warning');
                $months[$monthKey]['tooltip'] .= ' ' . implode(' ', array_map(
                    static fn(array $issue): string => (string)$issue['message'],
                    $issues
                ));
            }

            $heatmaps[] = [
                'id' => 'uploads-statement-coverage-account-' . $accountId,
                'label' => $accountLabel,
                'account_label' => $accountLabel,
                'start' => $periodStart,
                'end' => $periodEnd,
                'months' => array_values($months),
                'missing_status' => 'warning',
                'empty_message' => 'No statement coverage data is available for ' . $accountLabel . '.',
                'legend' => [
                    'pass' => 'Covered',
                    'warning' => 'Needs review',
                    'fail' => 'Gap',
                    'muted' => 'No data',
                ],
            ];
        }

        return $heatmaps;
    }

    private function baseAccountHeatmapMonths(string $periodStart, string $periodEnd, string $accountLabel, array $uploadedRowsByMonth, array $transactionsByMonth): array
    {
        $months = [];
        $cursor = (new \DateTimeImmutable($periodStart))->modify('first day of this month');
        $end = (new \DateTimeImmutable($periodEnd))->modify('first day of this month');

        while ($cursor <= $end) {
            $monthKey = $cursor->format('Y-m-01');
            $label = $cursor->format('M Y');
            $rawRows = (int)($uploadedRowsByMonth[$monthKey] ?? 0);
            $transactions = (int)($transactionsByMonth[$monthKey] ?? 0);
            $status = $rawRows > 0 ? 'pass' : 'warning';
            $tooltip = $rawRows > 0 || $transactions > 0
                ? sprintf(
                    '%s, %s: %d uploaded row(s), %d committed transaction(s).',
                    $accountLabel,
                    $label,
                    $rawRows,
                    $transactions
                )
                : sprintf('%s, %s: no uploaded CSV rows or committed transactions found.', $accountLabel, $label);

            $months[$monthKey] = [
                'month_key' => $monthKey,
                'label' => $label,
                'status' => $status,
                'value' => $rawRows,
                'display_value' => '(' . $rawRows . ')',
                'tooltip' => $tooltip,
            ];

            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    private function accountLabel(array $account): string
    {
        $name = trim((string)($account['account_name'] ?? 'Bank account'));
        $identifier = trim((string)($account['account_identifier'] ?? ''));
        $institution = trim((string)($account['institution_name'] ?? ''));
        $detail = $identifier !== '' ? $identifier : $institution;

        if ($name === '') {
            $name = 'Bank account';
        }

        return $detail !== '' ? $name . ' (' . $detail . ')' : $name;
    }

    private function buildUniqueUploadedRowsByAccountMonth(int $companyId, int $accountingPeriodId, array $accountingPeriod): array
    {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if ($periodStart === '' || $periodEnd === '') {
            return [];
        }

        $stmt = \InterfaceDB::prepare($this->uniqueUploadedRowsByAccountMonthSql());
        $stmt->execute([
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $periodStart,
            $periodEnd,
        ]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $accountId = (int)($row['account_id'] ?? 0);
            $monthKey = (string)($row['month_key'] ?? '');

            if ($accountId > 0 && $monthKey !== '') {
                $rows[$accountId][$monthKey] = (int)($row['raw_row_count'] ?? 0);
            }
        }

        return $rows;
    }

    private function uniqueUploadedRowsByAccountMonthSql(): string
    {
        return "SELECT account_id,
                       month_key,
                       SUM(raw_row_count) AS raw_row_count
                FROM (
                    SELECT account_id,
                           month_key,
                           COUNT(*) AS raw_row_count
                    FROM (
                        SELECT su.account_id,
                               DATE_FORMAT(sir.chosen_txn_date, '%Y-%m-01') AS month_key,
                               COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id)) AS file_key,
                               sir.`row_number`
                        FROM statement_import_rows sir
                        INNER JOIN statement_uploads su
                           ON su.id = sir.upload_id
                          AND su.company_id = ?
                        WHERE sir.accounting_period_id = ?
                          AND sir.chosen_txn_date BETWEEN ? AND ?
                          AND su.account_id IS NOT NULL
                        GROUP BY su.account_id,
                                 DATE_FORMAT(sir.chosen_txn_date, '%Y-%m-01'),
                                 COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id)),
                                 sir.`row_number`
                    ) unique_import_rows
                    GROUP BY account_id,
                             month_key
                    UNION ALL
                    SELECT su.account_id,
                           DATE_FORMAT(su.statement_month, '%Y-%m-01') AS month_key,
                           MAX(su.rows_parsed) AS raw_row_count
                    FROM statement_uploads su
                    LEFT JOIN statement_import_rows sir
                       ON sir.upload_id = su.id
                    WHERE su.company_id = ?
                      AND sir.id IS NULL
                      AND su.rows_parsed > 0
                      AND su.account_id IS NOT NULL
                      AND (
                          su.accounting_period_id = ?
                          OR (
                              su.accounting_period_id IS NULL
                              AND su.statement_month BETWEEN ? AND ?
                          )
                      )
                      AND su.statement_month BETWEEN ? AND ?
                    GROUP BY su.account_id,
                             DATE_FORMAT(su.statement_month, '%Y-%m-01'),
                             COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id))
                ) monthly_unique_rows
                GROUP BY account_id,
                         month_key
                ORDER BY account_id,
                         month_key";
    }

    private function buildCommittedTransactionsByAccountMonth(int $companyId, array $accountingPeriod): array
    {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if ($periodStart === '' || $periodEnd === '') {
            return [];
        }

        $stmt = \InterfaceDB::prepare("SELECT account_id,
                                             DATE_FORMAT(txn_date, '%Y-%m-01') AS month_key,
                                             COUNT(*) AS transaction_count
                                      FROM transactions
                                      WHERE company_id = ?
                                        AND account_id IS NOT NULL
                                        AND txn_date BETWEEN ? AND ?
                                      GROUP BY account_id,
                                               DATE_FORMAT(txn_date, '%Y-%m-01')
                                      ORDER BY account_id,
                                               month_key");
        $stmt->execute([$companyId, $periodStart, $periodEnd]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $accountId = (int)($row['account_id'] ?? 0);
            $monthKey = (string)($row['month_key'] ?? '');

            if ($accountId > 0 && $monthKey !== '') {
                $rows[$accountId][$monthKey] = (int)($row['transaction_count'] ?? 0);
            }
        }

        return $rows;
    }

    private function heatmapMonthLabel(string $monthKey, string $fallback): string
    {
        $monthKey = trim($monthKey);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthKey) === 1) {
            return (new \DateTimeImmutable($monthKey))->format('M Y');
        }

        $fallback = trim($fallback);

        return $fallback !== '' ? $fallback : $monthKey;
    }

    private function reconciliationIssuesByMonth(array $panels): array
    {
        $issues = [];

        foreach ($panels as $panel) {
            if (!is_array($panel)) {
                continue;
            }

            $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
            $accountName = trim((string)($account['account_name'] ?? 'Bank account'));

            foreach ((array)($panel['uploads'] ?? []) as $uploadCheck) {
                if (!is_array($uploadCheck)) {
                    continue;
                }

                $monthKey = $this->monthKeyForUploadCheck($uploadCheck);
                if ($monthKey === '') {
                    continue;
                }

                $status = (string)($uploadCheck['running_balance_status'] ?? 'not_available');
                if ($status === 'not_available' && $this->uploadCheckHasStatementBalances($uploadCheck)) {
                    continue;
                }

                if (!in_array($status, ['fail', 'warning', 'not_available'], true)) {
                    continue;
                }

                $note = trim((string)($uploadCheck['running_balance_note'] ?? ''));
                $issues[$monthKey][] = [
                    'status' => $status === 'fail' ? 'fail' : 'warning',
                    'message' => trim($accountName . ': ' . ($note !== '' ? $note : 'Running balance needs review.')),
                ];
            }
        }

        return $issues;
    }

    private function balanceContinuitySignalsByMonth(array $panels, string $periodStart, string $periodEnd): array
    {
        $signals = [];

        foreach ($panels as $panel) {
            if (!is_array($panel)) {
                continue;
            }

            $account = is_array($panel['account'] ?? null) ? $panel['account'] : [];
            $accountName = trim((string)($account['account_name'] ?? 'Bank account'));
            $previous = null;

            foreach ($this->orderedUploadChecks((array)($panel['uploads'] ?? [])) as $uploadCheck) {
                if (!is_array($uploadCheck)) {
                    continue;
                }

                if ($previous !== null && $this->uploadCheckHasStatementBalances($uploadCheck)) {
                    $startDate = $this->startDateForUploadCheck($uploadCheck);
                    $previousClosingDate = trim((string)($previous['closing_date'] ?? ''));

                    if ($this->statementsOverlap($previousClosingDate, $startDate)) {
                        $failureMonthKey = $this->continuityFailureMonthKey($previous, $uploadCheck, $periodStart, $periodEnd);

                        if ($failureMonthKey !== '') {
                            $signals[$failureMonthKey][] = [
                                'status' => 'fail',
                                'message' => $this->statementOverlapMessage($accountName, $previous, $uploadCheck),
                            ];
                        }

                        if ($this->uploadClosesAfter($uploadCheck, $previous)) {
                            $previous = $uploadCheck;
                        }

                        continue;
                    }

                    $previousClosingBalance = (float)$previous['closing_balance'];
                    $currentOpeningBalance = (float)$uploadCheck['opening_balance'];
                    $matches = $this->moneyMatches($previousClosingBalance, $currentOpeningBalance);

                    if (!$matches) {
                        $failureMonthKey = $this->continuityFailureMonthKey($previous, $uploadCheck, $periodStart, $periodEnd);

                        if ($failureMonthKey !== '') {
                            $signals[$failureMonthKey][] = [
                                'status' => 'fail',
                                'message' => $this->continuityMismatchMessage(
                                    $accountName,
                                    $previous,
                                    $uploadCheck,
                                    $failureMonthKey === $this->monthKeyForUploadCheck($uploadCheck)
                                ),
                            ];
                        }
                    }

                    foreach ($this->intermediateMonthKeys($previousClosingDate, $startDate) as $gapMonthKey) {
                        $signals[$gapMonthKey][] = [
                            'status' => $matches ? 'pass' : 'fail',
                            'message' => $matches
                                ? trim($accountName . ': No uploaded rows, but surrounding statement balances match.')
                                : $this->gapContinuityMismatchMessage($accountName, $previous, $uploadCheck),
                        ];
                    }
                }

                if ($this->uploadCheckHasStatementBalances($uploadCheck) && trim((string)($uploadCheck['closing_date'] ?? '')) !== '') {
                    $previous = $uploadCheck;
                }
            }
        }

        return $signals;
    }

    private function continuityFailureMonthKey(array $previous, array $current, string $periodStart, string $periodEnd): string
    {
        $currentDate = $this->startDateForUploadCheck($current);
        if ($this->dateInPeriod($currentDate, $periodStart, $periodEnd)) {
            return substr($currentDate, 0, 7) . '-01';
        }

        $previousDate = trim((string)($previous['closing_date'] ?? ''));
        if ($this->dateInPeriod($previousDate, $periodStart, $periodEnd)) {
            return substr($previousDate, 0, 7) . '-01';
        }

        return '';
    }

    private function continuityMismatchMessage(string $accountName, array $previous, array $current, bool $currentMonthIsProblemMarker): string
    {
        $previousDate = $this->displayDate((string)($previous['closing_date'] ?? ''));
        $currentDate = $this->displayDate($this->startDateForUploadCheck($current));
        $previousBalance = $this->displayMoney((float)$previous['closing_balance']);
        $currentBalance = $this->displayMoney((float)$current['opening_balance']);

        if ($currentMonthIsProblemMarker) {
            return sprintf(
                '%s: Opening boundary mismatch. Previous statement closed on %s at %s; this statement opens on %s at %s.',
                $accountName,
                $previousDate,
                $previousBalance,
                $currentDate,
                $currentBalance
            );
        }

        return sprintf(
            '%s: Closing boundary mismatch. This statement closed on %s at %s; next statement opens on %s at %s.',
            $accountName,
            $previousDate,
            $previousBalance,
            $currentDate,
            $currentBalance
        );
    }

    private function gapContinuityMismatchMessage(string $accountName, array $previous, array $current): string
    {
        return sprintf(
            '%s: Gap boundary mismatch. Previous statement closed on %s at %s; next statement opens on %s at %s, so movement may be missing in this gap.',
            $accountName,
            $this->displayDate((string)($previous['closing_date'] ?? '')),
            $this->displayMoney((float)$previous['closing_balance']),
            $this->displayDate($this->startDateForUploadCheck($current)),
            $this->displayMoney((float)$current['opening_balance'])
        );
    }

    private function statementOverlapMessage(string $accountName, array $previous, array $current): string
    {
        return sprintf(
            '%s: Statement date-range overlap. Previous statement closes on %s, but this statement opens on %s before that closing date.',
            $accountName,
            $this->displayDate((string)($previous['closing_date'] ?? '')),
            $this->displayDate($this->startDateForUploadCheck($current))
        );
    }

    private function dateInPeriod(string $date, string $periodStart, string $periodEnd): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && $date >= $periodStart
            && $date <= $periodEnd;
    }

    private function displayDate(string $date): string
    {
        $date = trim($date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return (new \DateTimeImmutable($date))->format('d/m/Y');
        }

        return $date !== '' ? $date : 'unknown date';
    }

    private function displayMoney(float $value): string
    {
        return 'GBP ' . number_format($value, 2, '.', '');
    }

    private function orderedUploadChecks(array $uploadChecks): array
    {
        $ordered = array_values(array_filter($uploadChecks, 'is_array'));
        usort($ordered, function (array $left, array $right): int {
            $leftDate = $this->startDateForUploadCheck($left);
            $rightDate = $this->startDateForUploadCheck($right);

            if ($leftDate === $rightDate) {
                $leftUpload = is_array($left['upload'] ?? null) ? $left['upload'] : [];
                $rightUpload = is_array($right['upload'] ?? null) ? $right['upload'] : [];

                return (int)($leftUpload['id'] ?? 0) <=> (int)($rightUpload['id'] ?? 0);
            }

            return strcmp($leftDate, $rightDate);
        });

        $unique = [];
        $seen = [];

        foreach ($ordered as $uploadCheck) {
            $dedupeKey = $this->uploadCheckDedupeKey($uploadCheck);

            if ($dedupeKey !== '' && isset($seen[$dedupeKey])) {
                continue;
            }

            if ($dedupeKey !== '') {
                $seen[$dedupeKey] = true;
            }

            $unique[] = $uploadCheck;
        }

        return $unique;
    }

    private function uploadCheckDedupeKey(array $uploadCheck): string
    {
        $parts = [
            $this->startDateForUploadCheck($uploadCheck),
            (string)($uploadCheck['closing_date'] ?? ''),
            $this->balanceKey($uploadCheck['opening_balance'] ?? null),
            $this->balanceKey($uploadCheck['closing_balance'] ?? null),
        ];

        $key = implode('|', array_map('trim', $parts));

        return trim(str_replace('|', '', $key)) !== '' ? $key : '';
    }

    private function balanceKey(mixed $value): string
    {
        return $value === null ? '' : number_format((float)$value, 2, '.', '');
    }

    private function statementsOverlap(string $previousClosingDate, string $currentStartDate): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $previousClosingDate) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentStartDate) === 1
            && $currentStartDate <= $previousClosingDate;
    }

    private function uploadClosesAfter(array $left, array $right): bool
    {
        $leftClosingDate = trim((string)($left['closing_date'] ?? ''));
        $rightClosingDate = trim((string)($right['closing_date'] ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $leftClosingDate) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rightClosingDate) === 1
            && $leftClosingDate > $rightClosingDate;
    }

    private function uploadCheckHasStatementBalances(array $uploadCheck): bool
    {
        return array_key_exists('opening_balance', $uploadCheck)
            && array_key_exists('closing_balance', $uploadCheck)
            && $uploadCheck['opening_balance'] !== null
            && $uploadCheck['closing_balance'] !== null;
    }

    private function monthKeyForUploadCheck(array $uploadCheck): string
    {
        $date = $this->startDateForUploadCheck($uploadCheck);

        return $date !== '' ? substr($date, 0, 7) . '-01' : '';
    }

    private function startDateForUploadCheck(array $uploadCheck): string
    {
        $upload = is_array($uploadCheck['upload'] ?? null) ? $uploadCheck['upload'] : [];

        foreach ([
            (string)($upload['date_range_start'] ?? ''),
            (string)($uploadCheck['closing_date'] ?? ''),
            (string)($upload['statement_month'] ?? ''),
        ] as $date) {
            $date = trim($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                return $date;
            }
        }

        return '';
    }

    private function intermediateMonthKeys(string $previousDate, string $currentDate): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $previousDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentDate) !== 1) {
            return [];
        }

        $cursor = (new \DateTimeImmutable($previousDate))->modify('first day of next month');
        $currentMonth = (new \DateTimeImmutable($currentDate))->modify('first day of this month');
        $monthKeys = [];

        while ($cursor < $currentMonth) {
            $monthKeys[] = $cursor->format('Y-m-01');
            $cursor = $cursor->modify('first day of next month');
        }

        return $monthKeys;
    }

    private function moneyMatches(float $left, float $right): bool
    {
        return abs(round($left - $right, 2)) < 0.005;
    }
}
