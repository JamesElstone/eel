<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanService
{
    public function fetchPeriods(int $companyId): array
    {
        if ($companyId <= 0) {
            return $this->error('Select a company first.');
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC',
            ['company_id' => $companyId]
        );

        return [
            'success' => true,
            'periods' => $periods,
            'selected_accounting_period_id' => (int)($periods[0]['id'] ?? 0),
            'accounting_period_id' => (int)($periods[0]['id'] ?? 0),
        ];
    }

    public function fetchStatement(int $companyId, int $accountingPeriodId): array
    {
        $requestCacheKey = $companyId . ':' . $accountingPeriodId;
        if (\eel_accounts\Support\RequestCache::has('director-loan.statement', $requestCacheKey)) {
            return (array)\eel_accounts\Support\RequestCache::get('director-loan.statement', $requestCacheKey);
        }

        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->error('The selected accounting period could not be found for this company.');
        }

        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        $assetNominal = $this->nominal((int)$controls['asset']);
        $liabilityNominal = $this->nominal((int)$controls['liability']);
        $errors = [];
        $missingControlNominals = $assetNominal === null || $liabilityNominal === null;
        if ($missingControlNominals) {
            $errors[] = 'Configure both Participator Loan control nominals in Company Nominals.';
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'accounting_period' => $period,
                'asset_nominal' => $assetNominal,
                'liability_nominal' => $liabilityNominal,
                'missing_control_nominals' => $missingControlNominals,
            ];
        }

        $periodStart = (string)$period['period_start'];
        $periodEnd = (string)$period['period_end'];
        $rawLines = $this->rawLines(
            $companyId,
            $periodStart,
            $periodEnd,
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );
        $postedReclassification = $this->postedReclassification(
            $companyId,
            $periodEnd,
            (int)$assetNominal['id'],
            (int)$liabilityNominal['id']
        );

        $ownership = (new OwnershipPartyService())->fetchSummary($companyId, $periodEnd);
        $directors = array_map(static function (array $party): array {
            return [
                'id' => (int)$party['id'],
                'company_id' => (int)$party['company_id'],
                'full_name' => (string)$party['legal_name'] . ((int)($party['linked_director_id'] ?? 0) > 0 ? ' (Director)' : ''),
                'is_active' => 1,
                'appointed_on' => '',
                'resigned_on' => '',
            ];
        }, array_values(array_filter((array)($ownership['parties'] ?? []), static fn(mixed $party): bool => is_array($party))));
        $directorMap = [];
        foreach ($directors as $director) {
            $directorMap[(int)$director['id']] = $director;
        }

        $groups = [];
        $statementRows = [];
        $attributionEntries = [];
        $unattributed = [];
        $invalid = [];

        foreach ($rawLines as $line) {
            $directorId = (int)($line['director_id'] ?? 0);
            $wasUnattributed = $directorId <= 0;
            $wasInvalid = false;
            $director = $directorId > 0 ? ($directorMap[$directorId] ?? null) : null;
            $sameCompany = $directorId <= 0 || ((int)($line['director_company_id'] ?? 0) === $companyId);
            if ($directorId > 0 && (!$sameCompany || $director === null)) {
                $invalid[] = $this->entryRow($line, null);
                $wasInvalid = true;
                $directorId = 0;
                $director = null;
            }

            $key = $directorId > 0 ? (string)$directorId : 'unattributed';
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyDirectorPosition($director);
            }

            $role = (int)$line['nominal_account_id'] === (int)$assetNominal['id'] ? 'asset' : 'liability';
            $normalAmount = $role === 'asset'
                ? round((float)$line['debit'] - (float)$line['credit'], 2)
                : round((float)$line['credit'] - (float)$line['debit'], 2);
            $isOpening = !empty($line['is_opening']);
            $bucket = $isOpening ? 'opening' : 'movement';
            $groups[$key][$bucket . '_' . $role] = round(
                (float)$groups[$key][$bucket . '_' . $role] + $normalAmount,
                2
            );

            $entry = $this->entryRow($line, $director);
            $entry['normal_amount'] = $normalAmount;
            $entry['signed_amount'] = round((float)$line['credit'] - (float)$line['debit'], 2);
            $entry['nominal_role'] = $role;
            $entry['account_label'] = $role === 'asset'
                ? \FormattingFramework::nominalLabel($assetNominal)
                : \FormattingFramework::nominalLabel($liabilityNominal);
            $attributionEntries[] = $entry;

            if ($isOpening) {
                if ($wasUnattributed && !$wasInvalid) {
                    $unattributed[] = $entry;
                }
                continue;
            }

            if ($wasUnattributed && !$wasInvalid) {
                $unattributed[] = $entry;
            }
            $statementRows[] = $entry;
        }

        foreach ($postedReclassification as $directorId => $amount) {
            $key = $directorId > 0 ? (string)$directorId : 'unattributed';
            if (!isset($groups[$key])) {
                $groups[$key] = $this->emptyDirectorPosition($directorMap[$directorId] ?? null);
            }
            $groups[$key]['posted_reclassification'] = round((float)$amount, 2);
        }

        $perDirector = [];
        foreach ($groups as $key => $position) {
            $asset = round((float)$position['opening_asset'] + (float)$position['movement_asset'], 2);
            $liability = round((float)$position['opening_liability'] + (float)$position['movement_liability'], 2);
            $desired = $key === 'unattributed'
                ? 0.0
                : round(min(max(0.0, $asset), max(0.0, $liability)), 2);
            $netLiability = round($liability - $asset, 2);
            $position += [
                'gross_asset' => $asset,
                'gross_liability' => $liability,
                'desired_reclassification' => $desired,
                'net_closing_position' => $netLiability,
                'net_position_label' => $this->balanceDirectionLabel($netLiability),
                'potential_s455_exposure' => round(max(0.0, $asset - $liability), 2),
                'pending_reclassification' => round($desired - (float)$position['posted_reclassification'], 2),
            ];
            $perDirector[] = $position;
        }

        usort($perDirector, static fn(array $a, array $b): int =>
            [(int)($a['director_id'] === null), strtolower((string)$a['director_name'])]
            <=> [(int)($b['director_id'] === null), strtolower((string)$b['director_name'])]
        );

        $running = [];
        foreach ($perDirector as $position) {
            $key = (string)($position['director_id'] ?? 'unattributed');
            $running[$key] = round((float)$position['opening_liability'] - (float)$position['opening_asset'], 2);
        }
        usort($statementRows, static fn(array $a, array $b): int =>
            [$a['journal_date'], $a['journal_id'], $a['journal_line_id']]
            <=> [$b['journal_date'], $b['journal_id'], $b['journal_line_id']]
        );
        foreach ($statementRows as &$row) {
            $key = (string)($row['director_id'] ?? 'unattributed');
            $running[$key] = round((float)($running[$key] ?? 0) + (float)$row['signed_amount'], 2);
            $row['running_balance'] = $running[$key];
        }
        unset($row);

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $assetReceivable = round(array_sum(array_column($perDirector, 'gross_asset')), 2);
        $liabilityPayable = round(array_sum(array_column($perDirector, 'gross_liability')), 2);
        $desiredReclassification = round(array_sum(array_column($perDirector, 'desired_reclassification')), 2);
        $postedAmount = round(array_sum(array_column($perDirector, 'posted_reclassification')), 2);
        $pendingMagnitude = round(array_sum(array_map(
            static fn(array $position): float => abs((float)($position['pending_reclassification'] ?? 0)),
            $perDirector
        )), 2);
        $periodMovementCount = count($statementRows);
        $hasActivity = $periodMovementCount > 0
            || abs($assetReceivable) >= 0.005
            || abs($liabilityPayable) >= 0.005;

        $result = [
            'success' => true,
            'available' => true,
            'accounting_period' => $period,
            'asset_nominal' => $assetNominal,
            'liability_nominal' => $liabilityNominal,
            'directors' => $directors,
            'per_director' => $perDirector,
            'statement_rows' => $statementRows,
            'attribution_entries' => $attributionEntries,
            'unattributed_entries' => $unattributed,
            'invalid_director_entries' => $invalid,
            'unattributed_count' => count($unattributed),
            'invalid_director_count' => count($invalid),
            'has_movements_in_period' => $periodMovementCount > 0,
            'has_activity' => $hasActivity,
            'asset_receivable' => $assetReceivable,
            'liability_payable' => $liabilityPayable,
            'desired_reclassification' => $desiredReclassification,
            'posted_reclassification' => $postedAmount,
            'pending_reclassification' => round($desiredReclassification - $postedAmount, 2),
            'pending_reclassification_magnitude' => $pendingMagnitude,
            'potential_s455_exposure' => round(array_sum(array_column($perDirector, 'potential_s455_exposure')), 2),
            'net_position' => round($liabilityPayable - $assetReceivable, 2),
            'closing_balance' => round($liabilityPayable - $assetReceivable, 2),
            'net_position_label' => $this->balanceDirectionLabel(round($liabilityPayable - $assetReceivable, 2)),
            'opening_balance' => round(
                array_sum(array_column($perDirector, 'opening_liability'))
                - array_sum(array_column($perDirector, 'opening_asset')),
                2
            ),
            'movement_in_period' => round(array_sum(array_column($statementRows, 'signed_amount')), 2),
            'default_currency' => (string)($settings['default_currency'] ?? 'GBP'),
            'default_currency_symbol' => (new CompanySettingsService())->defaultCurrencySymbol($settings),
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
        ];

        return (array)\eel_accounts\Support\RequestCache::put(
            'director-loan.statement',
            $requestCacheKey,
            $result
        );
    }

    public function fetchPositionSummary(int $companyId, int $accountingPeriodId): array
    {
        $statement = $this->fetchStatement($companyId, $accountingPeriodId);
        unset($statement['statement_rows']);
        return $statement + ['summary_only' => true];
    }

    /**
     * Return the note-only director-loan disclosure derived from the signed
     * running account. This deliberately does not change ledger or balance
     * sheet values.
     */
    public function fetchDisclosureSummary(int $companyId, int $accountingPeriodId): array
    {
        $statement = $this->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return $statement + ['available' => false];
        }

        $rowsByDirector = [];
        foreach ((array)($statement['statement_rows'] ?? []) as $row) {
            $key = (string)($row['director_id'] ?? 'unattributed');
            $rowsByDirector[$key][] = $row;
        }

        $disclosures = [];
        foreach ((array)($statement['per_director'] ?? []) as $position) {
            $key = (string)($position['director_id'] ?? 'unattributed');
            $running = round(
                (float)($position['opening_liability'] ?? 0)
                - (float)($position['opening_asset'] ?? 0),
                2
            );
            $minimum = $running;
            $advances = 0.0;
            $repayments = 0.0;
            $directorFunding = 0.0;

            foreach ((array)($rowsByDirector[$key] ?? []) as $row) {
                $signed = round((float)($row['signed_amount'] ?? 0), 2);
                $before = $running;
                $running = round($running + $signed, 2);
                $minimum = min($minimum, $running);

                if ($signed < 0) {
                    $advances += $before < 0
                        ? abs($signed)
                        : max(0.0, -$running);
                } elseif ($signed > 0) {
                    $settlement = $before < 0
                        ? min($signed, abs($before))
                        : 0.0;
                    $repayments += $settlement;
                    $directorFunding += $signed - $settlement;
                }
            }

            $exposure = round(max(0.0, -$minimum), 2);
            if ($exposure < 0.005) {
                continue;
            }

            $disclosures[] = [
                'director_id' => $position['director_id'] ?? null,
                'director_name' => (string)($position['director_name'] ?? 'Unattributed'),
                'opening_balance' => round(
                    (float)($position['opening_liability'] ?? 0)
                    - (float)($position['opening_asset'] ?? 0),
                    2
                ),
                'maximum_company_to_director_exposure' => $exposure,
                'advances' => round($advances, 2),
                'repayments' => round($repayments, 2),
                'director_funding' => round($directorFunding, 2),
                'closing_company_to_director_balance' => round(max(0.0, -$running), 2),
                'closing_company_liability' => round(max(0.0, $running), 2),
                'interest_rate' => '0%',
                'main_conditions' => 'Interest-free and repayable on demand.',
            ];
        }

        return [
            'success' => true,
            'available' => true,
            'has_company_to_director_exposure' => $disclosures !== [],
            'disclosures' => $disclosures,
            'total_advances' => round(array_sum(array_column($disclosures, 'advances')), 2),
            'total_repayments' => round(array_sum(array_column($disclosures, 'repayments')), 2),
            'total_director_funding' => round(array_sum(array_column($disclosures, 'director_funding')), 2),
        ];
    }

    public function fetchTaxReview(int $companyId, int $accountingPeriodId): array
    {
        return $this->taxReview($this->fetchStatement($companyId, $accountingPeriodId), true);
    }

    public function fetchTaxReviewSummary(int $companyId, int $accountingPeriodId): array
    {
        return $this->taxReview($this->fetchPositionSummary($companyId, $accountingPeriodId), false);
    }

    private function taxReview(array $statement, bool $includeStatement): array
    {
        if (empty($statement['success'])) {
            return [
                'success' => false,
                'available' => false,
                'errors' => (array)($statement['errors'] ?? ['Director loan statement unavailable.']),
            ];
        }

        $directorFlags = [];
        foreach ((array)$statement['per_director'] as $position) {
            if (($position['director_id'] ?? null) === null) {
                continue;
            }
            $exposure = round((float)$position['potential_s455_exposure'], 2);
            $directorFlags[] = [
                'director_id' => (int)$position['director_id'],
                'director_name' => (string)$position['director_name'],
                'potential_s455_exposure' => $exposure,
                'review_required' => $exposure >= 0.005,
            ];
        }

        $exposure = round((float)$statement['potential_s455_exposure'], 2);
        $result = [
            'success' => true,
            'available' => true,
            'status' => $exposure >= 0.005 ? 'review_required' : 'no_director_receivable',
            'status_label' => $exposure >= 0.005 ? 'Review required' : 'No director receivable',
            'review_required' => $exposure >= 0.005,
            'director_owes_company' => $exposure >= 0.005,
            'exposure_amount' => $exposure,
            'gross_director_receivable' => round((float)$statement['asset_receivable'], 2),
            'gross_director_payable' => round((float)$statement['liability_payable'], 2),
            'director_flags' => $directorFlags,
            'review_items' => $exposure >= 0.005 ? [
                ['key' => 's455', 'label' => 's455 corporation tax review', 'severity' => 'warning'],
                ['key' => 'repayment_timing', 'label' => 'Repayment timing', 'severity' => 'warning'],
                ['key' => 'beneficial_loan_interest', 'label' => 'Beneficial loan interest / BIK review', 'severity' => 'warning'],
                ['key' => 'write_off', 'label' => 'Write-off or waiver review', 'severity' => 'warning'],
                ['key' => 'ct600_supplementary', 'label' => 'CT600 supplementary review', 'severity' => 'warning'],
            ] : [],
        ];
        if ($includeStatement) {
            $result['statement'] = $statement;
        }

        return $result;
    }

    private function rawLines(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        int $assetNominalId,
        int $liabilityNominalId
    ): array {
        $transactionIdExpression = \InterfaceDB::driverName() === 'sqlite'
            ? 'CAST(SUBSTR(j.source_ref, 13) AS INTEGER)'
            : 'CAST(SUBSTRING(j.source_ref, 13) AS UNSIGNED)';
        $correctionJoins = '';
        $correctionWhere = '';
        if (\InterfaceDB::tableExists('journal_reversals')) {
            $correctionJoins = '
             LEFT JOIN journal_reversals jr_source ON jr_source.source_journal_id = j.id
             LEFT JOIN journal_reversals jr_reversal ON jr_reversal.reversal_journal_id = j.id';
            $correctionWhere = '
               AND jr_source.source_journal_id IS NULL
               AND jr_reversal.reversal_journal_id IS NULL';
        }

        return \InterfaceDB::fetchAll(
            'SELECT jl.id AS journal_line_id,
                    jl.journal_id,
                    jl.nominal_account_id,
                    jl.party_id AS director_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    j.journal_date,
                    j.description AS journal_description,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    COALESCE(jem.journal_tag, \'\') AS journal_tag,
                    cp.company_id AS director_company_id,
                    COALESCE(cp.legal_name, \'Unattributed\') AS director_name,
                    t.id AS transaction_id,
                    COALESCE(t.counterparty_name, \'\') AS counterparty_name,
                    ec.id AS expense_claim_id,
                    ec.claim_reference_code,
                    CASE
                      WHEN j.journal_date < :period_start_before THEN 1
                      WHEN j.journal_date = :period_start_on AND COALESCE(jem.journal_tag, \'\') = \'opening_balance\' THEN 1
                      ELSE 0
                    END AS is_opening
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN journal_entry_metadata jem ON jem.journal_id = j.id
             LEFT JOIN company_parties cp ON cp.id = jl.party_id AND cp.company_id = j.company_id
             LEFT JOIN transactions t
               ON j.source_type = \'bank_csv\'
              AND j.source_ref LIKE \'transaction:%\'
              AND t.id = ' . $transactionIdExpression . '
             LEFT JOIN expense_claims ec ON ec.posted_journal_id = j.id
             ' . $correctionJoins . '
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND j.journal_date <= :period_end
               AND COALESCE(jem.journal_tag, \'\') <> :reclassification_tag'
             . $correctionWhere . '
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC',
            [
                'period_start_before' => $periodStart,
                'period_start_on' => $periodStart,
                'company_id' => $companyId,
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'period_end' => $periodEnd,
                'reclassification_tag' => DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
            ]
        );
    }

    private function postedReclassification(
        int $companyId,
        string $periodEnd,
        int $assetNominalId,
        int $liabilityNominalId
    ): array {
        $rows = \InterfaceDB::fetchAll(
            'SELECT COALESCE(jl.party_id, 0) AS director_id,
                    SUM(CASE
                      WHEN jl.nominal_account_id = :asset_nominal_id THEN jl.credit - jl.debit
                      WHEN jl.nominal_account_id = :liability_nominal_id THEN jl.debit - jl.credit
                      ELSE 0
                    END) / 2 AS posted_amount
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date <= :period_end
               AND jem.journal_tag = :journal_tag
               AND jl.nominal_account_id IN (:asset_nominal_id_match, :liability_nominal_id_match)
             GROUP BY COALESCE(jl.party_id, 0)',
            [
                'asset_nominal_id' => $assetNominalId,
                'liability_nominal_id' => $liabilityNominalId,
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'journal_tag' => DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'asset_nominal_id_match' => $assetNominalId,
                'liability_nominal_id_match' => $liabilityNominalId,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['director_id']] = round((float)$row['posted_amount'], 2);
        }
        return $result;
    }

    private function entryRow(array $line, ?array $director): array
    {
        $sourceType = (string)$line['source_type'];
        $sourceUrl = '';
        $sourceLabel = \HelperFramework::labelFromKey($sourceType, '_');
        if ((int)($line['transaction_id'] ?? 0) > 0) {
            $sourceUrl = '?page=transactions&show_card=transactions_imported&transaction_id=' . (int)$line['transaction_id'];
            $sourceLabel = 'Transaction #' . (int)$line['transaction_id'];
        } elseif ((int)($line['expense_claim_id'] ?? 0) > 0) {
            $sourceUrl = '?page=expense_claims&show_card=expense_claim_editor&claim_id=' . (int)$line['expense_claim_id'];
            $sourceLabel = 'Expense claim ' . (string)($line['claim_reference_code'] ?? '');
        } elseif ($sourceType === 'manual') {
            $sourceUrl = '?page=year_end&show_card=journal_cut_offs';
            $sourceLabel = 'Manual journal #' . (int)$line['journal_id'];
        }

        $journalDescription = trim((string)$line['journal_description']);
        $lineDescription = trim((string)$line['line_description']);
        $description = $lineDescription !== '' && strcasecmp($lineDescription, $journalDescription) !== 0
            ? trim($journalDescription . ' - ' . $lineDescription, ' -')
            : $journalDescription;

        return [
            'row_type' => !empty($line['is_opening']) ? 'opening_balance' : 'movement',
            'journal_id' => (int)$line['journal_id'],
            'journal_line_id' => (int)$line['journal_line_id'],
            'journal_date' => (string)$line['journal_date'],
            'description' => $description,
            'source_type' => $sourceType,
            'source_ref' => (string)$line['source_ref'],
            'source_label' => $sourceLabel,
            'source_url' => $sourceUrl,
            'counterparty_name' => trim((string)($line['counterparty_name'] ?? '')),
            'director_id' => $director !== null ? (int)$director['id'] : null,
            'director_name' => $director !== null ? (string)$director['full_name'] : 'Unattributed',
            'nominal_account_id' => (int)$line['nominal_account_id'],
            'debit' => round((float)$line['debit'], 2),
            'credit' => round((float)$line['credit'], 2),
            'is_opening' => !empty($line['is_opening']),
        ];
    }

    private function emptyDirectorPosition(?array $director): array
    {
        return [
            'director_id' => $director !== null ? (int)$director['id'] : null,
            'director_name' => $director !== null ? (string)$director['full_name'] : 'Unattributed',
            'is_active' => $director !== null ? (int)$director['is_active'] : null,
            'appointed_on' => $director !== null ? (string)($director['appointed_on'] ?? '') : '',
            'resigned_on' => $director !== null ? (string)($director['resigned_on'] ?? '') : '',
            'opening_asset' => 0.0,
            'opening_liability' => 0.0,
            'movement_asset' => 0.0,
            'movement_liability' => 0.0,
            'posted_reclassification' => 0.0,
        ];
    }

    private function accountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    private function nominal(int $nominalId): ?array
    {
        if ($nominalId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id, na.code, na.name, na.account_type, COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id AND na.is_active = 1
             LIMIT 1',
            ['id' => $nominalId]
        );
        return is_array($row) ? $row : null;
    }

    private function balanceDirectionLabel(float $balance): string
    {
        if ($balance > 0.004) {
            return 'Company owes director';
        }
        if ($balance < -0.004) {
            return 'Director owes company';
        }
        return 'Settled';
    }

    private function error(string $message): array
    {
        return ['success' => false, 'available' => false, 'errors' => [$message]];
    }
}
