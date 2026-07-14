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

        $initialMonthChecksEnabled = $this->isEarliestAccountingPeriod($companyId, $accountingPeriodId);

        return [
            'available' => true,
            'accounting_period' => $period,
            'initial_month_checks_enabled' => $initialMonthChecksEnabled,
            'empty_message' => $initialMonthChecksEnabled
                ? 'No initial/opening or ordinary empty-month confirmations are available for this accounting period.'
                : 'No empty-month confirmations are available for this accounting period.',
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

    public function acceptedInitialStatementEvidence(int $companyId, int $accountingPeriodId): array
    {
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [];
        }

        $evidence = [];
        foreach ((array)($context['months'] ?? []) as $month) {
            if (!is_array($month) || (string)($month['status'] ?? '') !== 'confirmed') {
                continue;
            }

            $confirmation = is_array($month['confirmation'] ?? null) ? (array)$month['confirmation'] : [];
            $confirmationEvidence = is_array($confirmation['evidence'] ?? null) ? (array)$confirmation['evidence'] : [];
            if (!in_array((string)($confirmationEvidence['confirmation_basis'] ?? ''), [
                'initial_opening_month',
                'incorporation_month_first_later_statement_opening_zero',
            ], true)) {
                continue;
            }

            $statement = is_array($confirmationEvidence['first_later_statement'] ?? null)
                ? (array)$confirmationEvidence['first_later_statement']
                : [];
            if ((int)($statement['upload_id'] ?? 0) <= 0) {
                continue;
            }

            $statement['confirmed_month_start'] = (string)($month['month_start'] ?? '');
            $statement['confirmed_at'] = (string)($confirmation['confirmed_at'] ?? '');
            $evidence[] = $statement;
        }

        return $evidence;
    }

    public function activeConfirmationsAffectedByUpload(int $companyId, int $accountingPeriodId, int $uploadId): array
    {
        $companyId = max(0, $companyId);
        $accountingPeriodId = max(0, $accountingPeriodId);
        $uploadId = max(0, $uploadId);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $uploadId <= 0 || !$this->tableAvailable()) {
            return [];
        }

        $activeConfirmations = $this->activeConfirmationMap($companyId, $accountingPeriodId);
        if ($activeConfirmations === []) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT sir.chosen_txn_date
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su
                ON su.id = sir.upload_id
             WHERE su.company_id = :company_id
               AND sir.upload_id = :upload_id
               AND sir.accounting_period_id = :accounting_period_id
               AND sir.validation_status = :validation_status
               AND COALESCE(sir.is_duplicate_within_upload, 0) = 0
               AND COALESCE(sir.is_duplicate_existing, 0) = 0
               AND sir.committed_transaction_id IS NULL
               AND sir.chosen_txn_date IS NOT NULL
             ORDER BY sir.chosen_txn_date ASC, sir.row_number ASC, sir.id ASC',
            [
                'company_id' => $companyId,
                'upload_id' => $uploadId,
                'accounting_period_id' => $accountingPeriodId,
                'validation_status' => 'valid',
            ]
        );

        $affected = [];
        foreach ($rows as $row) {
            $monthStart = $this->normaliseMonthStart((string)($row['chosen_txn_date'] ?? ''));
            if ($monthStart === '' || !isset($activeConfirmations[$monthStart])) {
                continue;
            }

            if (!isset($affected[$monthStart])) {
                $affected[$monthStart] = [
                    'month_start' => $monthStart,
                    'month_label' => \HelperFramework::displayMonthYear(new \DateTimeImmutable($monthStart)),
                    'row_count' => 0,
                    'confirmation' => $this->normaliseConfirmation((array)$activeConfirmations[$monthStart]),
                ];
            }

            $affected[$monthStart]['row_count']++;
        }

        return array_values($affected);
    }

    public function revokeActiveConfirmationsForMonths(
        int $companyId,
        int $accountingPeriodId,
        array $monthStarts,
        string $revokedBy = 'web_app'
    ): array {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'revoke empty-month confirmations for this period');
        if (!$this->tableAvailable()) {
            return $this->failure('Empty month confirmations are not available until the database migration has been applied.');
        }

        $normalisedMonths = [];
        foreach ($monthStarts as $monthStart) {
            $monthStart = $this->normaliseMonthStart((string)$monthStart);
            if ($monthStart !== '') {
                $normalisedMonths[$monthStart] = true;
            }
        }

        if ($normalisedMonths === []) {
            return [
                'success' => true,
                'revoked_count' => 0,
                'months' => [],
            ];
        }

        $activeConfirmations = $this->activeConfirmationMap($companyId, $accountingPeriodId);
        $revokedMonths = [];
        foreach (array_keys($normalisedMonths) as $monthStart) {
            if (!isset($activeConfirmations[$monthStart])) {
                continue;
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

            $revokedMonths[] = [
                'month_start' => $monthStart,
                'month_label' => \HelperFramework::displayMonthYear(new \DateTimeImmutable($monthStart)),
            ];
        }

        return [
            'success' => true,
            'revoked_count' => count($revokedMonths),
            'months' => $revokedMonths,
        ];
    }

    public function confirmMonth(
        int $companyId,
        int $accountingPeriodId,
        string $monthStart,
        string $notes = '',
        string $confirmedBy = 'web_app'
    ): array {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'confirm an empty month for this period');
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

        $this->storeConfirmation($companyId, $accountingPeriodId, $monthStart, $candidate, $notes, $confirmedBy);

        return [
            'success' => true,
            'month_start' => $monthStart,
        ];
    }

    public function confirmMonths(
        int $companyId,
        int $accountingPeriodId,
        array $monthStarts,
        string $notes = '',
        string $confirmedBy = 'web_app'
    ): array {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'confirm empty months for this period');
        if (!$this->tableAvailable()) {
            return $this->failure('Empty month confirmations are not available until the database migration has been applied.');
        }

        $period = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->failure('The selected accounting period could not be found.');
        }

        $normalisedMonths = [];
        foreach ($monthStarts as $monthStart) {
            $monthStart = $this->normaliseMonthStart((string)$monthStart);
            if ($monthStart !== '') {
                $normalisedMonths[$monthStart] = true;
            }
        }

        if ($normalisedMonths === []) {
            return $this->failure('Select at least one valid month before confirming no financial activity.');
        }

        $candidates = [];
        $errors = [];
        foreach (array_keys($normalisedMonths) as $monthStart) {
            $candidate = $this->candidateForMonth($companyId, $accountingPeriodId, $period, $monthStart);
            if (empty($candidate['can_confirm'])) {
                $monthLabel = (string)($candidate['month_label'] ?? $monthStart);
                $errors[] = $monthLabel . ': ' . (string)($candidate['reason'] ?? 'This month is not eligible for empty activity confirmation.');
                continue;
            }

            $candidates[$monthStart] = $candidate;
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        \InterfaceDB::transaction(function () use ($companyId, $accountingPeriodId, $candidates, $notes, $confirmedBy): void {
            foreach ($candidates as $monthStart => $candidate) {
                $this->storeConfirmation($companyId, $accountingPeriodId, (string)$monthStart, $candidate, $notes, $confirmedBy);
            }
        });

        return [
            'success' => true,
            'month_starts' => array_keys($candidates),
            'confirmed_count' => count($candidates),
        ];
    }

    private function storeConfirmation(
        int $companyId,
        int $accountingPeriodId,
        string $monthStart,
        array $candidate,
        string $notes,
        string $confirmedBy
    ): void {
        $evidenceJson = json_encode((array)($candidate['evidence'] ?? []), JSON_UNESCAPED_SLASHES);
        if (!is_string($evidenceJson) || $evidenceJson === '') {
            $evidenceJson = '{}';
        }

        $upsertSql = 'INSERT INTO ' . self::TABLE . ' (
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
             )';

        if (\InterfaceDB::driverName() === 'sqlite') {
            $upsertSql .= '
             ON CONFLICT(company_id, accounting_period_id, month_start, confirmation_type) DO UPDATE SET
                notes = excluded.notes,
                evidence_json = excluded.evidence_json,
                confirmed_at = CURRENT_TIMESTAMP,
                confirmed_by = excluded.confirmed_by,
                revoked_at = NULL,
                revoked_by = NULL,
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $upsertSql .= '
             ON DUPLICATE KEY UPDATE
                notes = VALUES(notes),
                evidence_json = VALUES(evidence_json),
                confirmed_at = CURRENT_TIMESTAMP,
                confirmed_by = VALUES(confirmed_by),
                revoked_at = NULL,
                revoked_by = NULL';
        }

        \InterfaceDB::prepareExecute(
            $upsertSql,
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
    }

    public function revokeMonth(
        int $companyId,
        int $accountingPeriodId,
        string $monthStart,
        string $revokedBy = 'web_app'
    ): array {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'revoke an empty-month confirmation for this period');
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
        $isFirstAccountingPeriod = $this->isEarliestAccountingPeriod($companyId, $accountingPeriodId);
        $monthStarts = $this->periodMonthStarts($period);
        $activityCountsByMonth = $this->activityCountsByMonth($companyId, $accountingPeriodId, $period, $monthStarts);

        foreach ($monthStarts as $monthStart) {
            $candidate = $this->candidateForMonth(
                $companyId,
                $accountingPeriodId,
                $period,
                $monthStart,
                $isFirstAccountingPeriod,
                $activityCountsByMonth[$monthStart] ?? null
            );
            if ($this->shouldRenderCandidate($candidate)) {
                $months[(string)$candidate['month_start']] = $candidate;
            }
        }

        foreach ($this->fetchConfirmations($companyId, $accountingPeriodId) as $confirmation) {
            $monthStart = (string)($confirmation['month_start'] ?? '');
            if ($monthStart === '') {
                continue;
            }

            if (!$this->monthWithinPeriod($monthStart, $period)) {
                continue;
            }

            if (!isset($months[$monthStart])) {
                $months[$monthStart] = $this->candidateForMonth(
                    $companyId,
                    $accountingPeriodId,
                    $period,
                    $monthStart,
                    $isFirstAccountingPeriod,
                    $activityCountsByMonth[$monthStart] ?? null
                );
            }

            $months[$monthStart]['confirmation'] = $this->normaliseConfirmation($confirmation);
            $activityCounts = (array)($months[$monthStart]['counts'] ?? ($activityCountsByMonth[$monthStart] ?? []));
            if (!empty($confirmation['revoked_at'])) {
                $months[$monthStart]['status'] = 'revoked';
            } elseif (!$this->countsAreEmpty($activityCounts)) {
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

    private function shouldRenderCandidate(array $candidate): bool
    {
        if (!empty($candidate['can_confirm'])) {
            return true;
        }

        return (string)($candidate['confirmation_basis'] ?? '') === 'initial_opening_month'
            && $this->countsAreEmpty((array)($candidate['counts'] ?? []));
    }

    private function candidateForMonth(
        int $companyId,
        int $accountingPeriodId,
        array $period,
        string $monthStart,
        ?bool $isFirstAccountingPeriod = null,
        ?array $activityCounts = null
    ): array
    {
        $monthStart = $this->normaliseMonthStart($monthStart);
        $monthEnd = $monthStart !== ''
            ? (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d')
            : '';
        $incorporationMonth = $this->incorporationMonth($period);
        $isFirstAccountingPeriod = $isFirstAccountingPeriod ?? $this->isEarliestAccountingPeriod($companyId, $accountingPeriodId);
        $isInitialOpeningMonth = $isFirstAccountingPeriod && $incorporationMonth !== '' && $monthStart === $incorporationMonth;
        $basis = $isInitialOpeningMonth ? 'initial_opening_month' : 'no_activity_month';
        $basisLabel = $isInitialOpeningMonth ? 'First-period initial month' : 'No-activity month';
        $counts = $activityCounts !== null
            ? $this->normaliseActivityCounts($activityCounts)
            : ($monthStart !== '' ? $this->activityCounts($companyId, $accountingPeriodId, $monthStart, $monthEnd) : []);
        $evidence = $isInitialOpeningMonth && $monthStart !== '' ? $this->firstLaterStatementOpeningEvidence($companyId, $monthEnd) : null;
        $canConfirm = false;
        $reason = '';

        if ($monthStart === '') {
            $reason = 'The month is not valid.';
        } elseif (!$this->monthWithinPeriod($monthStart, $period)) {
            $reason = 'The month is outside the selected accounting period.';
        } elseif (!$this->countsAreEmpty($counts)) {
            $reason = 'Source activity already exists for this month.';
        } elseif ($isInitialOpeningMonth && $evidence === null) {
            $reason = 'No later statement row with opening balance evidence is available.';
        } elseif ($isInitialOpeningMonth && !$this->moneyMatches((float)$evidence['opening_balance'], 0.0)) {
            $reason = 'The first later statement does not open at 0.00.';
        } else {
            $canConfirm = true;
            $reason = $isInitialOpeningMonth
                ? 'First-period initial-month no-activity confirmation is available.'
                : 'No-activity month confirmation is available.';
        }

        return [
            'month_start' => $monthStart,
            'month_label' => $monthStart !== '' ? \HelperFramework::displayMonthYear(new \DateTimeImmutable($monthStart)) : '',
            'confirmation_basis' => $basis,
            'basis_label' => $basisLabel,
            'status' => $canConfirm ? 'available' : 'not_available',
            'can_confirm' => $canConfirm,
            'reason' => $reason,
            'counts' => $counts,
            'evidence' => [
                'confirmation_basis' => $basis,
                'confirmation_basis_label' => $basisLabel,
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
        $rawRows = $this->rawRowCount($companyId, $accountingPeriodId, $monthStart, $monthEnd);

        return $this->normaliseActivityCounts([
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
            'raw_rows' => $rawRows,
            'uploads' => $rawRows,
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
        ]);
    }

    private function activityCountsByMonth(int $companyId, int $accountingPeriodId, array $period, array $monthStarts): array
    {
        $map = [];
        foreach ($monthStarts as $monthStart) {
            $monthStart = $this->normaliseMonthStart((string)$monthStart);
            if ($monthStart !== '') {
                $map[$monthStart] = $this->emptyActivityCounts();
            }
        }

        if ($map === []) {
            return [];
        }

        $periodStart = trim((string)($period['period_start'] ?? ''));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '') {
            return $map;
        }

        $transactionMonthExpression = $this->monthKeyExpression('txn_date');
        foreach (\InterfaceDB::fetchAll(
            "SELECT {$transactionMonthExpression} AS month_key,
                    COUNT(*) AS row_count
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :period_end
             GROUP BY {$transactionMonthExpression}",
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if (isset($map[$monthKey])) {
                $map[$monthKey]['transactions'] = (int)($row['row_count'] ?? 0);
            }
        }

        $importRowMonthExpression = $this->monthKeyExpression('sir.chosen_txn_date');
        foreach (\InterfaceDB::fetchAll(
            "SELECT {$importRowMonthExpression} AS month_key,
                    COUNT(*) AS row_count
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su
                ON su.id = sir.upload_id
               AND su.company_id = :company_id
             WHERE sir.accounting_period_id = :accounting_period_id
               AND sir.chosen_txn_date BETWEEN :period_start AND :period_end
             GROUP BY {$importRowMonthExpression}",
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if (isset($map[$monthKey])) {
                $map[$monthKey]['raw_rows'] += (int)($row['row_count'] ?? 0);
            }
        }

        $uploadMonthExpression = $this->monthKeyExpression('su.statement_month');
        foreach (\InterfaceDB::fetchAll(
            "SELECT {$uploadMonthExpression} AS month_key,
                    COALESCE(SUM(su.rows_parsed), 0) AS row_count
             FROM statement_uploads su
             LEFT JOIN statement_import_rows sir
                ON sir.upload_id = su.id
             WHERE su.company_id = :company_id
               AND sir.id IS NULL
               AND su.rows_parsed > 0
               AND (
                    su.accounting_period_id = :accounting_period_id
                    OR su.accounting_period_id IS NULL
               )
               AND su.statement_month BETWEEN :period_start AND :period_end
             GROUP BY {$uploadMonthExpression}",
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if (isset($map[$monthKey])) {
                $map[$monthKey]['raw_rows'] += (int)($row['row_count'] ?? 0);
            }
        }

        $journalMonthExpression = $this->monthKeyExpression('journal_date');
        foreach (\InterfaceDB::fetchAll(
            "SELECT {$journalMonthExpression} AS month_key,
                    COUNT(*) AS row_count
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND is_posted = 1
               AND journal_date BETWEEN :period_start AND :period_end
             GROUP BY {$journalMonthExpression}",
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if (isset($map[$monthKey])) {
                $map[$monthKey]['posted_journals'] = (int)($row['row_count'] ?? 0);
            }
        }

        foreach ($map as $monthKey => $counts) {
            $map[$monthKey]['uploads'] = (int)($counts['raw_rows'] ?? 0);
        }

        return $map;
    }

    private function monthKeyExpression(string $column): string
    {
        return \InterfaceDB::driverName() === 'sqlite'
            ? "strftime('%Y-%m-01', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-01')";
    }

    private function emptyActivityCounts(): array
    {
        return [
            'transactions' => 0,
            'raw_rows' => 0,
            'uploads' => 0,
            'posted_journals' => 0,
        ];
    }

    private function normaliseActivityCounts(array $counts): array
    {
        $rawRows = (int)($counts['raw_rows'] ?? $counts['uploads'] ?? 0);

        return [
            'transactions' => (int)($counts['transactions'] ?? 0),
            'raw_rows' => $rawRows,
            'uploads' => $rawRows,
            'posted_journals' => (int)($counts['posted_journals'] ?? 0),
        ];
    }

    private function rawRowCount(int $companyId, int $accountingPeriodId, string $monthStart, string $monthEnd): int
    {
        $stagedRows = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su
                ON su.id = sir.upload_id
               AND su.company_id = :company_id
             WHERE sir.accounting_period_id = :accounting_period_id
               AND sir.chosen_txn_date BETWEEN :month_start AND :month_end',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
            ]
        );

        $unstagedRows = (int)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(su.rows_parsed), 0)
             FROM statement_uploads su
             LEFT JOIN statement_import_rows sir
                ON sir.upload_id = su.id
             WHERE su.company_id = :company_id
               AND sir.id IS NULL
               AND su.rows_parsed > 0
               AND (
                    su.accounting_period_id = :accounting_period_id
                    OR su.accounting_period_id IS NULL
               )
               AND su.statement_month BETWEEN :month_start AND :month_end',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'month_start' => $monthStart,
                'month_end' => $monthEnd,
            ]
        );

        return $stagedRows + $unstagedRows;
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
            && (int)($counts['raw_rows'] ?? $counts['uploads'] ?? 0) === 0
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

    private function periodMonthStarts(array $period): array
    {
        $periodStart = trim((string)($period['period_start'] ?? ''));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '') {
            return [];
        }

        $months = [];
        $cursor = (new \DateTimeImmutable($periodStart))->modify('first day of this month');
        $end = (new \DateTimeImmutable($periodEnd))->modify('first day of this month');
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m-01');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    private function isEarliestAccountingPeriod(int $companyId, int $accountingPeriodId): bool
    {
        $earliestId = (int)(\InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start ASC, id ASC
             LIMIT 1',
            ['company_id' => $companyId]
        ) ?: 0);

        return $earliestId > 0 && $earliestId === $accountingPeriodId;
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
