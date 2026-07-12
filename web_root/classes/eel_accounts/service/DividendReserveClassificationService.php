<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class DividendReserveClassificationService
{
    private const DIVIDENDS_PAID_CODE = '3100';
    public const TREATMENT_REALISED_PROFIT = 'realised_profit';
    public const TREATMENT_REALISED_LOSS = 'realised_loss';
    public const TREATMENT_UNREALISED_GAIN = 'unrealised_gain';
    public const TREATMENT_UNREALISED_LOSS = 'unrealised_loss';
    public const TREATMENT_NON_DISTRIBUTABLE = 'non_distributable';
    public const TREATMENT_CAPITAL = 'capital';
    public const TREATMENT_TAX_CHARGE = 'tax_charge';
    public const TREATMENT_DIVIDEND_DISTRIBUTION = 'dividend_distribution';
    public const TREATMENT_UNKNOWN = 'unknown';

    /** @return list<string> */
    public function treatments(): array
    {
        return [
            self::TREATMENT_REALISED_PROFIT,
            self::TREATMENT_REALISED_LOSS,
            self::TREATMENT_UNREALISED_GAIN,
            self::TREATMENT_UNREALISED_LOSS,
            self::TREATMENT_NON_DISTRIBUTABLE,
            self::TREATMENT_CAPITAL,
            self::TREATMENT_TAX_CHARGE,
            self::TREATMENT_DIVIDEND_DISTRIBUTION,
            self::TREATMENT_UNKNOWN,
        ];
    }

    public function fetchReviewContext(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing dividend reserve classifications.'],
            ];
        }
        if (!$this->hasSchema()) {
            return [
                'available' => false,
                'errors' => ['Run the Dividend reserve classification migration before reviewing dividend reserve classifications.'],
            ];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $effectiveDate = $this->effectiveAsAtDate($asAtDate, $periodStart, $periodEnd);
        $broughtForward = $this->distributableReservesBroughtForward($companyId, $periodStart);
        $dividendsDeclared = $this->dividendsDeclaredForPeriod($companyId, $accountingPeriodId, $periodStart, $effectiveDate);
        $rows = $this->classifiedRows($companyId, $accountingPeriodId, $accountingPeriod, $effectiveDate);
        $summary = $this->summaryFromRows($rows);
        $summary['brought_forward_distributable_reserves'] = round((float)($broughtForward['amount'] ?? 0), 2);
        $summary['dividends_declared'] = round($dividendsDeclared, 2);
        $summary['closing_distributable_reserves'] = round(
            (float)$summary['brought_forward_distributable_reserves']
            + (float)$summary['distributable_current_profit']
            - (float)$summary['dividends_declared'],
            2
        );
        $sourceHash = $this->sourceHash($companyId, $accountingPeriodId, $effectiveDate, $summary, $rows);
        $snapshot = $this->latestSnapshot($companyId, $accountingPeriodId, $effectiveDate);
        $snapshotCurrent = is_array($snapshot)
            && trim((string)($snapshot['as_at_date'] ?? '')) === $effectiveDate
            && hash_equals((string)($snapshot['source_hash'] ?? ''), $sourceHash);

        return [
            'available' => true,
            'errors' => [],
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'accounting_period' => $accountingPeriod,
            'as_at_date' => $effectiveDate,
            'brought_forward' => $broughtForward,
            'rows' => $rows,
            'summary' => $summary,
            'source_hash' => $sourceHash,
            'snapshot' => $snapshot,
            'snapshot_current' => $snapshotCurrent,
            'treatments' => $this->treatments(),
            'status' => $snapshotCurrent ? 'current' : ($snapshot === null ? 'missing' : 'stale'),
            'status_label' => $snapshotCurrent ? 'Reserve review current' : ($snapshot === null ? 'Reserve review missing' : 'Reserve review stale'),
        ];
    }

    public function saveReview(int $companyId, int $accountingPeriodId, array $treatments, string $reviewedBy = 'web_app', ?string $asAtDate = null): array
    {
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change the dividend reserve review for this period');
        $context = $this->fetchReviewContext($companyId, $accountingPeriodId, $asAtDate);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }

        $validTreatments = array_fill_keys($this->treatments(), true);
        foreach ((array)($context['rows'] ?? []) as $row) {
            $nominalAccountId = (int)($row['nominal_account_id'] ?? 0);
            if ($nominalAccountId <= 0 || !array_key_exists((string)$nominalAccountId, $treatments)) {
                continue;
            }

            $treatment = trim((string)$treatments[(string)$nominalAccountId]);
            if (!isset($validTreatments[$treatment])) {
                return [
                    'success' => false,
                    'errors' => ['Choose a valid reserve treatment for each reviewed nominal.'],
                    'context' => $context,
                ];
            }

            $this->upsertRule($companyId, $nominalAccountId, $treatment, $reviewedBy);
        }

        $context = $this->fetchReviewContext($companyId, $accountingPeriodId, $asAtDate);
        $summary = (array)($context['summary'] ?? []);
        if ((float)($summary['unknown_amount'] ?? 0) > 0.0) {
            return [
                'success' => false,
                'errors' => ['Classify all unknown reserve movements before saving the dividend reserve review.'],
                'context' => $context,
            ];
        }
        $broughtForward = (array)($context['brought_forward'] ?? []);
        if (empty($broughtForward['reliable'])) {
            return [
                'success' => false,
                'errors' => [(string)($broughtForward['detail'] ?? 'Distributable reserves brought forward are not reliable.')],
                'context' => $context,
            ];
        }

        $summaryJson = json_encode($summary, \JSON_UNESCAPED_SLASHES);
        if (!is_string($summaryJson)) {
            $summaryJson = '{}';
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO dividend_reserve_review_snapshots (
                company_id,
                accounting_period_id,
                as_at_date,
                source_hash,
                brought_forward_distributable_reserves,
                ledger_profit_loss,
                realised_profit_amount,
                realised_loss_amount,
                unrealised_gain_amount,
                unrealised_loss_amount,
                non_distributable_amount,
                capital_amount,
                tax_charge_amount,
                dividend_distribution_amount,
                unknown_amount,
                distributable_current_profit,
                dividends_declared,
                closing_distributable_reserves,
                reviewed_at,
                reviewed_by,
                summary_json,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :as_at_date,
                :source_hash,
                :brought_forward_distributable_reserves,
                :ledger_profit_loss,
                :realised_profit_amount,
                :realised_loss_amount,
                :unrealised_gain_amount,
                :unrealised_loss_amount,
                :non_distributable_amount,
                :capital_amount,
                :tax_charge_amount,
                :dividend_distribution_amount,
                :unknown_amount,
                :distributable_current_profit,
                :dividends_declared,
                :closing_distributable_reserves,
                CURRENT_TIMESTAMP,
                :reviewed_by,
                :summary_json,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'as_at_date' => (string)($context['as_at_date'] ?? ''),
                'source_hash' => (string)($context['source_hash'] ?? ''),
                'brought_forward_distributable_reserves' => number_format((float)($summary['brought_forward_distributable_reserves'] ?? 0), 2, '.', ''),
                'ledger_profit_loss' => number_format((float)($summary['ledger_profit_loss'] ?? 0), 2, '.', ''),
                'realised_profit_amount' => number_format((float)($summary['realised_profit_amount'] ?? 0), 2, '.', ''),
                'realised_loss_amount' => number_format((float)($summary['realised_loss_amount'] ?? 0), 2, '.', ''),
                'unrealised_gain_amount' => number_format((float)($summary['unrealised_gain_amount'] ?? 0), 2, '.', ''),
                'unrealised_loss_amount' => number_format((float)($summary['unrealised_loss_amount'] ?? 0), 2, '.', ''),
                'non_distributable_amount' => number_format((float)($summary['non_distributable_amount'] ?? 0), 2, '.', ''),
                'capital_amount' => number_format((float)($summary['capital_amount'] ?? 0), 2, '.', ''),
                'tax_charge_amount' => number_format((float)($summary['tax_charge_amount'] ?? 0), 2, '.', ''),
                'dividend_distribution_amount' => number_format((float)($summary['dividend_distribution_amount'] ?? 0), 2, '.', ''),
                'unknown_amount' => number_format((float)($summary['unknown_amount'] ?? 0), 2, '.', ''),
                'distributable_current_profit' => number_format((float)($summary['distributable_current_profit'] ?? 0), 2, '.', ''),
                'dividends_declared' => number_format((float)($summary['dividends_declared'] ?? 0), 2, '.', ''),
                'closing_distributable_reserves' => number_format((float)($summary['closing_distributable_reserves'] ?? 0), 2, '.', ''),
                'reviewed_by' => trim($reviewedBy) !== '' ? trim($reviewedBy) : 'web_app',
                'summary_json' => $summaryJson,
            ]
        );

        return [
            'success' => true,
            'context' => $this->fetchReviewContext($companyId, $accountingPeriodId, $asAtDate),
        ];
    }

    public function currentSnapshot(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): ?array
    {
        $context = $this->fetchReviewContext($companyId, $accountingPeriodId, $asAtDate);
        if (empty($context['available']) || empty($context['snapshot_current'])) {
            return null;
        }

        return is_array($context['snapshot'] ?? null) ? (array)$context['snapshot'] : null;
    }

    public function latestSnapshot(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): ?array
    {
        if (!$this->hasSchema()) {
            return null;
        }

        $dateFilter = '';
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];
        if ($asAtDate !== null && trim($asAtDate) !== '') {
            $dateFilter = ' AND as_at_date = :as_at_date';
            $params['as_at_date'] = trim($asAtDate);
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM dividend_reserve_review_snapshots
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               ' . $dateFilter . '
             ORDER BY reviewed_at DESC, id DESC
             LIMIT 1',
            $params
        );

        return is_array($row) ? $row : null;
    }

    private function upsertRule(int $companyId, int $nominalAccountId, string $treatment, string $reviewedBy): void
    {
        $existingId = (int)(\InterfaceDB::fetchColumn(
            'SELECT id
             FROM dividend_reserve_classification_rules
             WHERE company_id = :company_id
               AND nominal_account_id = :nominal_account_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'nominal_account_id' => $nominalAccountId,
            ]
        ) ?: 0);

        if ($existingId > 0) {
            \InterfaceDB::prepareExecute(
                'UPDATE dividend_reserve_classification_rules
                 SET treatment = :treatment,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'treatment' => $treatment,
                    'id' => $existingId,
                ]
            );
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO dividend_reserve_classification_rules (
                company_id,
                nominal_account_id,
                treatment,
                note,
                is_active,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :nominal_account_id,
                :treatment,
                :note,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )',
            [
                'company_id' => $companyId,
                'nominal_account_id' => $nominalAccountId,
                'treatment' => $treatment,
                'note' => 'Reviewed by ' . (trim($reviewedBy) !== '' ? trim($reviewedBy) : 'web_app'),
            ]
        );
    }

    private function classifiedRows(int $companyId, int $accountingPeriodId, array $accountingPeriod, string $asAtDate): array
    {
        $rules = $this->rulesByNominal($companyId);
        $rows = $this->ledgerRows(
            $companyId,
            $accountingPeriodId,
            (string)($accountingPeriod['period_start'] ?? ''),
            $asAtDate
        );

        foreach ($rows as &$row) {
            $nominalAccountId = (int)($row['nominal_account_id'] ?? 0);
            $defaultTreatment = $this->defaultTreatment($row);
            $row['default_treatment'] = $defaultTreatment;
            $row['treatment'] = (string)($rules[$nominalAccountId] ?? $defaultTreatment);
        }
        unset($row);

        return $rows;
    }

    private function ledgerRows(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name,
                    na.account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.id, na.code, na.name, na.account_type, nas.code
             ORDER BY na.code ASC, na.name ASC',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );

        $normalised = [];
        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);
            $profitEffect = $accountType === 'income'
                ? round($credit - $debit, 2)
                : round($credit - $debit, 2);

            $normalised[] = [
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'nominal_code' => (string)($row['nominal_code'] ?? ''),
                'nominal_name' => (string)($row['nominal_name'] ?? ''),
                'account_type' => $accountType,
                'subtype_code' => (string)($row['subtype_code'] ?? ''),
                'total_debit' => round($debit, 2),
                'total_credit' => round($credit, 2),
                'profit_effect' => $profitEffect,
            ];
        }

        return $normalised;
    }

    private function defaultTreatment(array $row): string
    {
        $name = strtolower((string)($row['nominal_name'] ?? ''));
        $code = (string)($row['nominal_code'] ?? '');
        $subtypeCode = (string)($row['subtype_code'] ?? '');
        $accountType = (string)($row['account_type'] ?? '');

        if ($subtypeCode === 'corp_tax' || $code === '2200' || str_contains($name, 'corporation tax')) {
            return self::TREATMENT_TAX_CHARGE;
        }
        if ($accountType === 'income') {
            return self::TREATMENT_REALISED_PROFIT;
        }
        if ($accountType === 'expense' || $accountType === 'cost_of_sales') {
            return self::TREATMENT_REALISED_LOSS;
        }

        return self::TREATMENT_UNKNOWN;
    }

    private function rulesByNominal(int $companyId): array
    {
        if (!$this->hasSchema()) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT nominal_account_id, treatment
             FROM dividend_reserve_classification_rules
             WHERE company_id = :company_id
               AND is_active = 1',
            ['company_id' => $companyId]
        );

        $rules = [];
        foreach ($rows as $row) {
            $nominalAccountId = (int)($row['nominal_account_id'] ?? 0);
            $treatment = (string)($row['treatment'] ?? '');
            if ($nominalAccountId > 0 && in_array($treatment, $this->treatments(), true)) {
                $rules[$nominalAccountId] = $treatment;
            }
        }

        return $rules;
    }

    private function summaryFromRows(array $rows): array
    {
        $summary = [
            'ledger_profit_loss' => 0.0,
            'realised_profit_amount' => 0.0,
            'realised_loss_amount' => 0.0,
            'unrealised_gain_amount' => 0.0,
            'unrealised_loss_amount' => 0.0,
            'non_distributable_amount' => 0.0,
            'capital_amount' => 0.0,
            'tax_charge_amount' => 0.0,
            'dividend_distribution_amount' => 0.0,
            'unknown_amount' => 0.0,
            'distributable_current_profit' => 0.0,
        ];

        foreach ($rows as $row) {
            $profitEffect = round((float)($row['profit_effect'] ?? 0), 2);
            $amount = abs($profitEffect);
            $summary['ledger_profit_loss'] = round($summary['ledger_profit_loss'] + $profitEffect, 2);
            $treatment = (string)($row['treatment'] ?? self::TREATMENT_UNKNOWN);

            if ($treatment === self::TREATMENT_REALISED_PROFIT && $profitEffect > 0) {
                $summary['realised_profit_amount'] = round($summary['realised_profit_amount'] + $amount, 2);
                $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] + $amount, 2);
                continue;
            }
            if ($treatment === self::TREATMENT_REALISED_PROFIT && $profitEffect < 0) {
                $treatment = self::TREATMENT_REALISED_LOSS;
            }

            if ($treatment === self::TREATMENT_REALISED_LOSS) {
                $summary['realised_loss_amount'] = round($summary['realised_loss_amount'] + $amount, 2);
                $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
            } elseif ($treatment === self::TREATMENT_UNREALISED_GAIN) {
                if ($profitEffect >= 0) {
                    $summary['unrealised_gain_amount'] = round($summary['unrealised_gain_amount'] + $amount, 2);
                } else {
                    $summary['unrealised_loss_amount'] = round($summary['unrealised_loss_amount'] + $amount, 2);
                    $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
                }
            } elseif ($treatment === self::TREATMENT_UNREALISED_LOSS) {
                $summary['unrealised_loss_amount'] = round($summary['unrealised_loss_amount'] + $amount, 2);
                $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
            } elseif ($treatment === self::TREATMENT_TAX_CHARGE) {
                $summary['tax_charge_amount'] = round($summary['tax_charge_amount'] + $amount, 2);
                $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
            } elseif ($treatment === self::TREATMENT_DIVIDEND_DISTRIBUTION) {
                $summary['dividend_distribution_amount'] = round($summary['dividend_distribution_amount'] + $amount, 2);
                $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
            } elseif ($treatment === self::TREATMENT_NON_DISTRIBUTABLE) {
                $summary['non_distributable_amount'] = round($summary['non_distributable_amount'] + $amount, 2);
                if ($profitEffect < 0) {
                    $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
                }
            } elseif ($treatment === self::TREATMENT_CAPITAL) {
                $summary['capital_amount'] = round($summary['capital_amount'] + $amount, 2);
                if ($profitEffect < 0) {
                    $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
                }
            } else {
                $summary['unknown_amount'] = round($summary['unknown_amount'] + $amount, 2);
                if ($profitEffect < 0) {
                    $summary['distributable_current_profit'] = round($summary['distributable_current_profit'] - $amount, 2);
                }
            }
        }

        foreach ($summary as $key => $value) {
            $summary[$key] = round((float)$value, 2);
        }

        return $summary;
    }

    private function sourceHash(int $companyId, int $accountingPeriodId, string $asAtDate, array $summary, array $rows): string
    {
        $payload = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'as_at_date' => $asAtDate,
            'brought_forward_distributable_reserves' => number_format((float)($summary['brought_forward_distributable_reserves'] ?? 0), 2, '.', ''),
            'rows' => array_map(
                static fn(array $row): array => [
                    'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                    'profit_effect' => number_format((float)($row['profit_effect'] ?? 0), 2, '.', ''),
                    'treatment' => (string)($row['treatment'] ?? self::TREATMENT_UNKNOWN),
                ],
                $rows
            ),
        ];

        return hash('sha256', json_encode($payload, \JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function distributableReservesBroughtForward(int $companyId, string $periodStart): array
    {
        $previousPeriod = $this->fetchPreviousAccountingPeriod($companyId, $periodStart);
        if ($previousPeriod === null) {
            return [
                'reliable' => true,
                'amount' => 0.0,
                'status' => 'first_period_zero',
                'detail' => 'This is the first recorded accounting period, so distributable reserves brought forward are treated as zero.',
            ];
        }

        $previousPeriodId = (int)($previousPeriod['id'] ?? 0);
        $previousPeriodEnd = (string)($previousPeriod['period_end'] ?? '');
        $previousContext = $this->fetchReviewContext($companyId, $previousPeriodId, $previousPeriodEnd);
        if (empty($previousContext['available']) || empty($previousContext['snapshot_current'])) {
            return [
                'reliable' => false,
                'amount' => 0.0,
                'status' => 'prior_distributable_snapshot_missing',
                'detail' => 'Dividend declaration is blocked until the prior period distributable reserve snapshot is current.',
            ];
        }

        $snapshot = (array)($previousContext['snapshot'] ?? []);
        return [
            'reliable' => true,
            'amount' => round((float)($snapshot['closing_distributable_reserves'] ?? 0), 2),
            'status' => 'prior_distributable_snapshot',
            'detail' => 'Distributable reserves brought forward are based on the prior period reviewed distributable reserve snapshot.',
        ];
    }

    private function fetchPreviousAccountingPeriod(int $companyId, string $periodStart): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'period_start' => $periodStart,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function dividendsDeclaredForPeriod(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): float
    {
        $nominal = $this->findNominalByCode(self::DIVIDENDS_PAID_CODE);
        $nominalId = (int)($nominal['id'] ?? 0);
        if ($nominalId <= 0) {
            return 0.0;
        }

        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND (j.is_posted = 1 OR (j.source_type = :draft_source_type AND j.source_ref LIKE :draft_source_ref))
               AND j.journal_date BETWEEN :period_start AND :as_at_date
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'as_at_date' => $asAtDate,
                'nominal_account_id' => $nominalId,
                'draft_source_type' => 'manual',
                'draft_source_ref' => 'dividend:%',
            ]
        ), 2);
    }

    private function findNominalByCode(string $code): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, is_active
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => $code]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            [
                'id' => $accountingPeriodId,
                'company_id' => $companyId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function hasSchema(): bool
    {
        return $this->tableExists('dividend_reserve_classification_rules')
            && $this->tableExists('dividend_reserve_review_snapshots')
            && \InterfaceDB::columnExists('dividend_reserve_review_snapshots', 'as_at_date')
            && \InterfaceDB::columnExists('dividend_reserve_review_snapshots', 'brought_forward_distributable_reserves')
            && \InterfaceDB::columnExists('dividend_reserve_review_snapshots', 'dividends_declared')
            && \InterfaceDB::columnExists('dividend_reserve_review_snapshots', 'closing_distributable_reserves');
    }

    private function effectiveAsAtDate(?string $asAtDate, string $periodStart, string $periodEnd): string
    {
        $value = trim((string)($asAtDate ?? ''));
        if (!$this->isValidDate($value)) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            return $today > $periodEnd ? $periodEnd : $today;
        }
        if ($value < $periodStart) {
            return $periodStart;
        }
        if ($value > $periodEnd) {
            return $periodEnd;
        }

        return $value;
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}
