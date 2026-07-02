<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class EmptyMonthConfirmationService
{
    private const TABLE = 'accounting_period_month_confirmations';
    private const CONFIRMATION_TYPE_NO_ACTIVITY = 'no_financial_activity';

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableAvailable()) {
            return [
                'available' => false,
                'errors' => ['Empty month confirmations are not available until the database migration has been applied.'],
                'months' => [],
            ];
        }

        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
                'months' => [],
            ];
        }

        return [
            'available' => true,
            'accounting_period' => $period,
            'months' => $this->reviewMonths($companyId, $accountingPeriodId, $period),
        ];
    }

    public function activeConfirmationMap(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $map = [];
        foreach ($this->fetchActiveConfirmations($companyId, $accountingPeriodId) as $row) {
            $monthStart = (string)($row['month_start'] ?? '');
            if ($monthStart !== '') {
                $map[$monthStart] = $row;
            }
        }

        return $map;
    }

    public function confirmMonth(
        int $companyId,
        int $accountingPeriodId,
        string $monthStart,
        string $notes = '',
        string $confirmedBy = 'web_app'
    ): array {
        if (!$this->tableAvailable()) {
            return $this->failure('Empty month confirmations are not available until the database migration has been applied.');
        }

        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->failure('The selected accounting period could not be found.');
        }

        $monthStart = $this->normaliseMonthStart($monthStart);
        if ($monthStart === '') {
            return $this->failure('Select a valid month before confirming no financial activity.');
        }

        $candidate = $this->candidateForMonth($companyId, $accountingPeriodId, $period, $monthStart);
        if (empty($candidate['can_confirm'])) {
            return $this->failure((string)($candidate['reason'] ?? 'This month is not eligible for empty activity confirmation.'));
        }

        $evidenceJson = json_encode((array)($candidate['evidence'] ?? []), JSON_UNESCAPED_SLASHES);
        if (!is_string($evidenceJson) || $evidenceJson === '') {
            $evidenceJson = '{}';
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::TABLE . ' (
                company_id,
                accounting_period_id,
                month_start,
                confirmation_type,
                notes,
                evidence_json,
                confirmed_at,
                confirmed_by,
                revoked_at,
                revoked_by
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :month_start,
                :confirmation_type,
                :notes,
                :evidence_json,
                CURRENT_TIMESTAMP,
                :confirmed_by,
                NULL,
                NULL
             )
             ON DUPLICATE KEY UPDATE
                notes = VALUES(notes),
                evidence_json = VALUES(evidence_json),
                confirmed_at = CURRENT_TIMESTAMP,
                confirmed_by = VALUES(confirmed_by),
                revoked_at = NULL,
                revoked_by = NULL',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'month_start' => $monthStart,
                'confirmation_type' => self::CONFIRMATION_TYPE_NO_ACTIVITY,
                'notes' => trim($notes) !== '' ? trim($notes) : null,
                'evidence_json' => $evidenceJson,
                'confirmed_by' => $this->actorValue($confirmedBy),
            ]
        );

        return [
            'success' => true,
            'month_start' => $monthStart,
        ];
    }

    public function revokeMonth(
        int $companyId,
        int $accountingPeriodId,
        string $monthStart,
        string $revokedBy = 'web_app'
    ): array {
        if (!$this->tableAvailable()) {
            return $this->failure('Empty month confirmations are not available until the database migration has been applied.');
        }

        $monthStart = $this->normaliseMonthStart($monthStart);
        if ($monthStart === '') {
            return $this->failure('Select a valid month before revoking a confirmation.');
        }

        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::TABLE . '
             SET revoked_at = CURRENT_TIMESTAMP,
                 revoked_by = :revoked_by
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND month_start = :month_start
               AND confirmation_type = :confirmation_type
               AND revoked_at IS NULL',
            [
                'revoked_by' => $this->actorValue($revokedBy),
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'month_start' => $monthStart,
                'confirmation_type' => self::CONFIRMATION_TYPE_NO_ACTIVITY,
            ]
        );

        return [
            'success' => true,
            'month_start' => $monthStart,
        ];
    }

    private function reviewMonths(int $companyId, int $accountingPeriodId, array $period): array
    {
        $months = [];
        $candidate = $this->candidateForIncorporationMonth($companyId, $accountingPeriodId, $period);
        if ($candidate !== null) {
            $months[(string)$candidate['month_start']] = $candidate;
        }

        foreach ($this->fetchConfirmations($companyId, $accountingPeriodId) as $confirmation) {
            $monthStart = (string)($confirmation['month_start'] ?? '');
            if ($monthStart === '') {
                continue;
            }

            if (!isset($months[$monthStart])) {
                $months[$monthStart] = $this->candidateForMonth($companyId, $accountingPeriodId, $period, $monthStart);
            }

            $months[$monthStart]['confirmation'] = $this->normaliseConfirmation($confirmation);
            if (!empty($confirmation['revoked_at'])) {
                $months[$monthStart]['status'] = 'revoked';
            } elseif (!$this->monthHasNoActivity($companyId, $accountingPeriodId, $monthStart)) {
                $months[$monthStart]['status'] = 'superseded';
                $months[$monthStart]['can_confirm'] = false;
                $months[$monthStart]['reason'] = 'Source activity now exists for this month, so the old confirmation is no longer used.';
            } else {
                $months[$monthStart]['status'] = 'confirmed';
                $months[$monthStart]['can_confirm'] = false;
            }
        }

        ksort($months);

        return array_values($months);
    }

    private function candidateForIncorporationMonth(int $companyId, int $accountingPeriodId, array $period): ?array
    {
        $incorporationDate = trim((string)($period['incorporation_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $incorporationDate)) {
            return null;
        }

        $monthStart = (new \DateTimeImmutable($incorporationDate))->modify('first day of this month')->format('Y-m-01');

        return $this->candidateForMonth($companyId, $accountingPeriodId, $period, $monthStart);
    }

    private function candidateForMonth(int $companyId, int $accountingPeriodId, array $period, string $monthStart): array
    {
        $monthStart = $this->normaliseMonthStart($monthStart);
        $monthEnd = $monthStart !== ''
            ? (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d')
            : '';
        $incorporationMonth = $this->incorporationMonth($period);
        $counts = $monthStart !== '' ? $this->activityCounts($companyId, $accountingPeriodId, $monthStart, $monthEnd) : [];
        $evidence = $monthStart !== '' ? $this->firstLaterStatementOpeningEvidence($companyId, $monthEnd) : null;
        $canConfirm = false;
        $reason = '';

        if ($monthStart === '') {
            $reason = 'The month is not valid.';
        } elseif (!$this->monthWithinPeriod($monthStart, $period)) {
            $reason = 'The month is outside the selected accounting period.';
        } elseif ($incorporationMonth === '' || $monthStart !== $incorporationMonth) {
            $reason = 'Only the incorporation month can be confirmed through this first-month workflow.';
        } elseif (!$this->countsAreEmpty($counts)) {
            $reason = 'Source activity already exists for this month.';
        } elseif ($evidence === null) {
            $reason = 'No later statement row with opening balance evidence is available.';
        } elseif (!$this->moneyMatches((float)$evidence['opening_balance'], 0.0)) {
            $reason = 'The first later statement does not open at 0.00.';
        } else {
            $canConfirm = true;
            $reason = 'First-month no-activity confirmation is available.';
        }

        return [
            'month_start' => $monthStart,
            'month_label' => $monthStart !== '' ? \HelperFramework::displayMonthYear(new \DateTimeImmutable($monthStart)) : '',
            'status' => $canConfirm ? 'available' : 'not_available',
            'can_confirm' => $canConfirm,
            'reason' => $reason,
            'counts' => $counts,
            'evidence' => [
                'confirmation_basis' => 'incorporation_month_first_later_statement_opening_zero',
                'incorporation_date' => (string)($period['incorporation_date'] ?? ''),
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
                'activity_counts' => $counts,
                'first_later_statement' => $evidence,
                'assertion' => 'No company financial activity existed in this month.',
            ],
        ];
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT ap.id,
                    ap.company_id,
                    ap.label,
                    ap.period_start,
                    ap.period_end,
                    c.company_name,
                    c.incorporation_date
             FROM accounting_periods ap
             INNER JOIN companies c ON c.id = ap.company_id
             WHERE ap.company_id = :company_id
               AND ap.id = :accounting_period_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function activityCounts(int $companyId, int $accountingPeriodId, string $monthStart, string $monthEnd): array
    {
        return [
            'transactions' => (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM transactions
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND txn_date BETWEEN :month_start AND :month_end',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_start' => $monthStart,
                    'month_end' => $monthEnd,
                ]
            ),
            'uploads' => (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM statement_uploads
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND COALESCE(date_range_start, statement_month, date_range_end) BETWEEN :month_start AND :month_end',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_start' => $monthStart,
                    'month_end' => $monthEnd,
                ]
            ),
            'posted_journals' => (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journals
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND is_posted = 1
                   AND journal_date BETWEEN :month_start AND :month_end',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_start' => $monthStart,
                    'month_end' => $monthEnd,
                ]
            ),
        ];
    }

    private function firstLaterStatementOpeningEvidence(int $companyId, string $monthEnd): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT su.id AS upload_id,
                    su.original_filename,
                    su.account_id,
                    COALESCE(ca.account_name, "") AS account_name,
                    su.statement_month,
                    su.date_range_start,
                    su.date_range_end,
                    sir.row_number,
                    sir.chosen_txn_date,
                    sir.normalised_amount,
                    sir.normalised_balance,
                    ROUND(sir.normalised_balance - sir.normalised_amount, 2) AS opening_balance
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su ON su.id = sir.upload_id
             LEFT JOIN company_accounts ca ON ca.id = su.account_id
             WHERE su.company_id = :company_id
               AND sir.chosen_txn_date > :month_end
               AND sir.normalised_amount IS NOT NULL
               AND sir.normalised_balance IS NOT NULL
             ORDER BY sir.chosen_txn_date ASC,
                      su.id ASC,
                      sir.row_number ASC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'month_end' => $monthEnd,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'upload_id' => (int)($row['upload_id'] ?? 0),
            'original_filename' => (string)($row['original_filename'] ?? ''),
            'account_id' => (int)($row['account_id'] ?? 0),
            'account_name' => (string)($row['account_name'] ?? ''),
            'statement_month' => (string)($row['statement_month'] ?? ''),
            'date_range_start' => (string)($row['date_range_start'] ?? ''),
            'date_range_end' => (string)($row['date_range_end'] ?? ''),
            'row_number' => (int)($row['row_number'] ?? 0),
            'chosen_txn_date' => (string)($row['chosen_txn_date'] ?? ''),
            'normalised_amount' => round((float)($row['normalised_amount'] ?? 0), 2),
            'normalised_balance' => round((float)($row['normalised_balance'] ?? 0), 2),
            'opening_balance' => round((float)($row['opening_balance'] ?? 0), 2),
        ];
    }

    private function fetchConfirmations(int $companyId, int $accountingPeriodId): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND confirmation_type = :confirmation_type
             ORDER BY month_start ASC, id ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'confirmation_type' => self::CONFIRMATION_TYPE_NO_ACTIVITY,
            ]
        );
    }

    private function fetchActiveConfirmations(int $companyId, int $accountingPeriodId): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND confirmation_type = :confirmation_type
               AND revoked_at IS NULL
             ORDER BY month_start ASC, id ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'confirmation_type' => self::CONFIRMATION_TYPE_NO_ACTIVITY,
            ]
        );
    }

    private function normaliseConfirmation(array $confirmation): array
    {
        $evidence = json_decode((string)($confirmation['evidence_json'] ?? ''), true);

        return [
            'id' => (int)($confirmation['id'] ?? 0),
            'confirmation_type' => (string)($confirmation['confirmation_type'] ?? ''),
            'notes' => (string)($confirmation['notes'] ?? ''),
            'evidence' => is_array($evidence) ? $evidence : [],
            'confirmed_at' => (string)($confirmation['confirmed_at'] ?? ''),
            'confirmed_by' => (string)($confirmation['confirmed_by'] ?? ''),
            'revoked_at' => (string)($confirmation['revoked_at'] ?? ''),
            'revoked_by' => (string)($confirmation['revoked_by'] ?? ''),
        ];
    }

    private function monthHasNoActivity(int $companyId, int $accountingPeriodId, string $monthStart): bool
    {
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        return $this->countsAreEmpty($this->activityCounts($companyId, $accountingPeriodId, $monthStart, $monthEnd));
    }

    private function countsAreEmpty(array $counts): bool
    {
        return (int)($counts['transactions'] ?? 0) === 0
            && (int)($counts['uploads'] ?? 0) === 0
            && (int)($counts['posted_journals'] ?? 0) === 0;
    }

    private function incorporationMonth(array $period): string
    {
        $incorporationDate = trim((string)($period['incorporation_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $incorporationDate)) {
            return '';
        }

        return (new \DateTimeImmutable($incorporationDate))->modify('first day of this month')->format('Y-m-01');
    }

    private function monthWithinPeriod(string $monthStart, array $period): bool
    {
        $periodStart = trim((string)($period['period_start'] ?? ''));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '') {
            return false;
        }

        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        return $monthEnd >= $periodStart && $monthStart <= $periodEnd;
    }

    private function normaliseMonthStart(string $monthStart): string
    {
        $monthStart = trim($monthStart);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthStart)) {
            return '';
        }

        return (new \DateTimeImmutable($monthStart))->modify('first day of this month')->format('Y-m-01');
    }

    private function moneyMatches(float $left, float $right): bool
    {
        return abs(round($left - $right, 2)) < 0.005;
    }

    private function actorValue(string $actor): string
    {
        $actor = trim($actor);

        return $actor !== '' ? substr($actor, 0, 100) : 'web_app';
    }

    private function tableAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }

        try {
            $available = \InterfaceDB::tableExists(self::TABLE);
        } catch (\Throwable) {
            $available = false;
        }

        return $available;
    }

    private function failure(string $message): array
    {
        return [
            'success' => false,
            'errors' => [$message],
        ];
    }
}
