<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Builds the read-only evidence model behind Corporation Tax computations.
 * It deliberately exposes no mutation or tax-only override API.
 */
final class TaxAuditBasisService
{
    public const BASIS_VERSION = 'ct-audit-v1';

    private const AREAS = [
        'accounting_profit' => 'Accounting Profit or Loss',
        'expense_treatments' => 'Expense Treatments and Add-Backs',
        'depreciation_capital' => 'Depreciation and Capital Adjustments',
        'capital_allowances' => 'Capital Allowances',
        'losses' => 'Losses',
        'tax_liability' => 'Rate Bands and Corporation Tax Liability',
    ];

    /** @return array<string, string> */
    public static function areaCatalogue(): array
    {
        return self::AREAS;
    }

    public static function isSupportedArea(string $areaCode): bool
    {
        return array_key_exists(strtolower(trim($areaCode)), self::AREAS);
    }

    /** @return array<string, mixed> */
    public function fetchAreaIndex(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $scope = $this->periodScope($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($scope['available'])) {
            return $scope;
        }

        $snapshot = $this->snapshotHeader($companyId, $accountingPeriodId, $ctPeriodId);
        if ($snapshot !== null) {
            $rows = \InterfaceDB::fetchAll(
                'SELECT area_code, area_label, amount, expected_amount,
                        reconciliation_difference, reconciliation_status,
                        source_count, area_hash
                 FROM corporation_tax_audit_areas
                 WHERE snapshot_id = :snapshot_id
                 ORDER BY id ASC',
                ['snapshot_id' => (int)$snapshot['id']]
            ) ?: [];

            return [
                'available' => true,
                'mode' => 'frozen',
                'mode_label' => 'Frozen audit snapshot',
                'period' => $scope['period'],
                'snapshot' => $snapshot,
                'areas' => array_map([$this, 'normaliseStoredArea'], $rows),
                'errors' => [],
            ];
        }

        $summary = (new CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        if (empty($summary['available'])) {
            return [
                'available' => false,
                'errors' => (array)($summary['errors'] ?? ['The CT computation is unavailable.']),
                'areas' => [],
            ];
        }

        $mode = (int)($scope['period']['latest_computation_run_id'] ?? 0) > 0 ? 'reconstructed' : 'live';
        $areas = [];
        foreach (self::AREAS as $code => $label) {
            $amount = $this->summaryAmount($summary, $code);
            $areas[] = [
                'area_code' => $code,
                'area_label' => $label,
                'amount' => $amount,
                'expected_amount' => $amount,
                'reconciliation_difference' => 0.0,
                'reconciliation_status' => 'reconciled',
                'source_count' => null,
                'area_hash' => '',
            ];
        }

        return [
            'available' => true,
            'mode' => $mode,
            'mode_label' => $mode === 'reconstructed' ? 'Reconstructed from current stored sources' : 'Live audit preview',
            'period' => $scope['period'],
            'snapshot' => null,
            'areas' => $areas,
            'errors' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function fetchAreaDetail(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $areaCode,
        int $page = 1
    ): array {
        $areaCode = strtolower(trim($areaCode));
        if ($areaCode === '') {
            return ['available' => false, 'empty_selection' => true, 'errors' => []];
        }
        if (!self::isSupportedArea($areaCode)) {
            return ['available' => false, 'errors' => ['The selected tax audit area is not supported.']];
        }

        $scope = $this->periodScope($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($scope['available'])) {
            return $scope;
        }

        $snapshot = $this->snapshotHeader($companyId, $accountingPeriodId, $ctPeriodId);
        if ($snapshot !== null) {
            $row = \InterfaceDB::fetchOne(
                'SELECT * FROM corporation_tax_audit_areas
                 WHERE snapshot_id = :snapshot_id AND area_code = :area_code
                 LIMIT 1',
                ['snapshot_id' => (int)$snapshot['id'], 'area_code' => $areaCode]
            );
            if (is_array($row)) {
                $detail = json_decode((string)($row['detail_json'] ?? ''), true);
                if (is_array($detail)) {
                    $detail['mode'] = 'frozen';
                    $detail['mode_label'] = 'Frozen audit snapshot';
                    $detail['snapshot'] = $snapshot;
                    return $this->paginate($detail, $page);
                }
            }
        }

        $detail = $this->buildLiveArea($companyId, $accountingPeriodId, $ctPeriodId, $areaCode);
        $detail['mode'] = (int)($scope['period']['latest_computation_run_id'] ?? 0) > 0 ? 'reconstructed' : 'live';
        $detail['mode_label'] = $detail['mode'] === 'reconstructed'
            ? 'Reconstructed from current stored sources'
            : 'Live audit preview';

        return $this->paginate($detail, $page);
    }

    /**
     * Persist all area evidence beside a computation run. The caller owns the
     * surrounding Year End transaction.
     */
    public function persistSnapshot(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $computationRunId,
        array $summary
    ): array {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Tax audit snapshots can only be persisted inside the Year End lock transaction.');
        }
        foreach (['corporation_tax_audit_snapshots', 'corporation_tax_audit_areas'] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                throw new \RuntimeException('Apply the Tax Audit database migration before locking Year End.');
            }
        }

        $details = [];
        foreach (array_keys(self::AREAS) as $areaCode) {
            $details[$areaCode] = $this->buildLiveArea($companyId, $accountingPeriodId, $ctPeriodId, $areaCode, $summary);
        }
        $basisHash = hash('sha256', $this->canonicalJson(array_map(
            static fn(array $detail): string => (string)$detail['area_hash'],
            $details
        )));

        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_audit_snapshots
                (computation_run_id, company_id, accounting_period_id, ct_period_id,
                 basis_version, basis_hash, snapshot_origin)
             VALUES
                (:run_id, :company_id, :accounting_period_id, :ct_period_id,
                 :basis_version, :basis_hash, :snapshot_origin)',
            [
                'run_id' => $computationRunId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'basis_version' => self::BASIS_VERSION,
                'basis_hash' => $basisHash,
                'snapshot_origin' => 'year_end_lock',
            ]
        );
        $snapshotId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM corporation_tax_audit_snapshots WHERE computation_run_id = :run_id LIMIT 1',
            ['run_id' => $computationRunId]
        );
        if ($snapshotId <= 0) {
            throw new \RuntimeException('The Tax Audit snapshot header could not be persisted.');
        }

        foreach ($details as $detail) {
            $json = $this->canonicalJson($detail);
            \InterfaceDB::prepareExecute(
                'INSERT INTO corporation_tax_audit_areas
                    (snapshot_id, area_code, area_label, amount, expected_amount,
                     reconciliation_difference, reconciliation_status, source_count,
                     area_hash, detail_json)
                 VALUES
                    (:snapshot_id, :area_code, :area_label, :amount, :expected_amount,
                     :difference, :status, :source_count, :area_hash, :detail_json)',
                [
                    'snapshot_id' => $snapshotId,
                    'area_code' => (string)$detail['area_code'],
                    'area_label' => (string)$detail['area_label'],
                    'amount' => (float)$detail['amount'],
                    'expected_amount' => (float)$detail['expected_amount'],
                    'difference' => (float)$detail['reconciliation_difference'],
                    'status' => (string)$detail['reconciliation_status'],
                    'source_count' => count((array)$detail['rows']),
                    'area_hash' => (string)$detail['area_hash'],
                    'detail_json' => $json,
                ]
            );
        }

        return ['snapshot_id' => $snapshotId, 'basis_hash' => $basisHash, 'areas' => $details];
    }

    /** @return array<string, mixed> */
    private function buildLiveArea(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $areaCode,
        ?array $knownSummary = null
    ): array {
        $summary = $knownSummary ?? (new CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        if (empty($summary['available'])) {
            return ['available' => false, 'errors' => (array)($summary['errors'] ?? ['The CT computation is unavailable.'])];
        }
        $workings = (new TaxWorkingsService())->fetchWorkings($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($workings['available'])) {
            return ['available' => false, 'errors' => (array)($workings['errors'] ?? ['Tax workings are unavailable.'])];
        }

        $rows = match ($areaCode) {
            'accounting_profit' => $this->ledgerRows($companyId, $accountingPeriodId, $ctPeriodId, $summary, false),
            'expense_treatments' => $this->ledgerRows($companyId, $accountingPeriodId, $ctPeriodId, $summary, true),
            'depreciation_capital' => $this->depreciationCapitalRows($workings),
            'capital_allowances' => $this->capitalAllowanceRows($workings),
            'losses' => $this->lossRows($workings, $ctPeriodId),
            'tax_liability' => $this->rateRows($workings, $summary),
            default => [],
        };
        $expected = $this->summaryAmount($summary, $areaCode);
        $amount = $this->rowTotal($rows, $areaCode, $expected);
        $residual = round($expected - $amount, 2);
        if (abs($residual) >= 0.005) {
            $rows[] = $this->auditRow(
                'calculation_reconciliation',
                0,
                '',
                'Remaining amount produced by the canonical CT computation schedule',
                $areaCode === 'accounting_profit' ? $residual : 0,
                $areaCode === 'accounting_profit' ? 0 : $residual,
                ['source_label' => 'Canonical computation component']
            );
            $amount = round($amount + $residual, 2);
        }
        $difference = round($amount - $expected, 2);
        $detail = [
            'available' => true,
            'area_code' => $areaCode,
            'area_label' => self::AREAS[$areaCode],
            'amount' => $amount,
            'expected_amount' => $expected,
            'reconciliation_difference' => $difference,
            'reconciliation_status' => abs($difference) < 0.005 ? 'reconciled' : 'discrepancy',
            'rows' => array_values($rows),
            'basis_version' => self::BASIS_VERSION,
            'errors' => [],
        ];
        $hashBasis = $detail;
        unset($hashBasis['area_hash'], $hashBasis['pagination']);
        $detail['area_hash'] = hash('sha256', $this->canonicalJson($hashBasis));

        return $detail;
    }

    /**
     * Read individual posted P&L journal lines. Expense mode deliberately
     * retains allowable rows with a zero adjustment so a zero add-back result
     * remains inspectable rather than appearing as an empty assertion.
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerRows(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $summary,
        bool $expenseMode
    ): array
    {
        if (!\InterfaceDB::tableExists('journals') || !\InterfaceDB::tableExists('journal_lines')) {
            return [];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT ap.period_start AS accounting_start, ap.period_end AS accounting_end,
                    cp.period_start AS ct_start, cp.period_end AS ct_end
             FROM corporation_tax_periods cp
             INNER JOIN accounting_periods ap ON ap.id = cp.accounting_period_id
             WHERE cp.id = :ct_period_id AND cp.company_id = :company_id
               AND cp.accounting_period_id = :accounting_period_id LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($period)) {
            return [];
        }
        $allocation = (array)($summary['accounting_allocation_basis'] ?? []);
        $timeApportioned = !empty($allocation['time_apportioned']);
        $start = $timeApportioned ? (string)$period['accounting_start'] : (string)$period['ct_start'];
        $end = $timeApportioned ? (string)$period['accounting_end'] : (string)$period['ct_end'];
        $expenseFilter = $expenseMode ? " AND na.account_type IN ('cost_of_sales','expense')" : '';
        $expenseClaimJoin = \InterfaceDB::tableExists('expense_claims')
            ? ' LEFT JOIN expense_claims ec ON ec.posted_journal_id = j.id'
            : '';
        $expenseClaimSelect = \InterfaceDB::tableExists('expense_claims')
            ? ', COALESCE(ec.id, 0) AS expense_claim_id'
            : ', 0 AS expense_claim_id';
        $rowsFromDb = \InterfaceDB::fetchAll(
            'SELECT j.id AS journal_id, j.source_type AS journal_source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref, j.journal_date,
                    j.description AS journal_description,
                    jl.id AS journal_line_id, COALESCE(jl.line_description, \'\') AS line_description,
                    jl.debit, jl.credit, na.id AS nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code, COALESCE(na.name, \'\') AS nominal_name,
                    na.account_type, COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    COALESCE(nas.code, \'\') AS account_subtype_code'
                    . $expenseClaimSelect . '
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_tag'
             . $expenseClaimJoin . '
             WHERE j.company_id = :company_id AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1 AND j.journal_date BETWEEN :period_start AND :period_end
               AND COALESCE(j.source_type, \'\') <> :depreciation_source
               AND jem_close.id IS NULL
               AND na.account_type IN (\'income\',\'cost_of_sales\',\'expense\')'
             . $expenseFilter . '
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC',
            [
                'close_tag' => RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $start,
                'period_end' => $end,
                'depreciation_source' => YearEndClosePreviewService::ASSET_DEPRECIATION_SOURCE_TYPE,
            ]
        ) ?: [];
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $ctExpenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        $rules = new CorporationTaxTreatmentRuleService();
        $ratio = $timeApportioned
            ? ((int)($allocation['ct_period_days'] ?? 0) / max(1, (int)($allocation['accounting_period_days'] ?? 0)))
            : 1.0;
        $rows = [];
        foreach ($rowsFromDb as $row) {
            if ($ctExpenseNominalId > 0 && (int)($row['nominal_account_id'] ?? 0) === $ctExpenseNominalId) {
                continue;
            }
            $resolved = $rules->resolveTaxTreatment([
                'id' => (int)($row['nominal_account_id'] ?? 0),
                'code' => (string)($row['nominal_code'] ?? ''),
                'name' => (string)($row['nominal_name'] ?? ''),
                'account_type' => (string)($row['account_type'] ?? ''),
                'tax_treatment' => (string)($row['tax_treatment'] ?? 'allowable'),
                'account_subtype_code' => (string)($row['account_subtype_code'] ?? ''),
                'journal_source_type' => (string)($row['journal_source_type'] ?? ''),
            ], (string)$row['journal_date'], (string)$row['journal_date']);
            $treatment = (string)($resolved['tax_treatment'] ?? 'allowable');
            $rule = (array)($resolved['rule'] ?? []);
            $debit = (float)($row['debit'] ?? 0);
            $credit = (float)($row['credit'] ?? 0);
            $accountingAmount = $expenseMode ? ($debit - $credit) : ($credit - $debit);
            $accountingAmount = round($accountingAmount * $ratio, 2);
            $adjustment = $expenseMode && $treatment === 'disallowable' ? $accountingAmount : 0.0;
            $journalId = (int)($row['journal_id'] ?? 0);
            $sourceType = 'journal';
            $sourceId = $journalId;
            $sourceLabel = 'Journal #' . $journalId;
            if ((string)($row['journal_source_type'] ?? '') === 'bank_csv'
                && preg_match('/transaction:(\d+)/', (string)($row['source_ref'] ?? ''), $matches) === 1) {
                $sourceType = 'transaction';
                $sourceId = (int)$matches[1];
                $sourceLabel = 'Transaction #' . $sourceId . ' / journal #' . $journalId;
            } elseif ((int)($row['expense_claim_id'] ?? 0) > 0) {
                $sourceType = 'expense_claim';
                $sourceId = (int)$row['expense_claim_id'];
                $sourceLabel = 'Expense claim #' . $sourceId . ' / journal #' . $journalId;
            }
            $rows[] = $this->auditRow(
                $sourceType,
                $sourceId,
                (string)($row['journal_date'] ?? ''),
                trim((string)($row['line_description'] ?? '')) !== ''
                    ? (string)$row['line_description']
                    : (string)($row['journal_description'] ?? ''),
                $accountingAmount,
                $adjustment,
                [
                    'journal_id' => $journalId,
                    'journal_line_id' => (int)($row['journal_line_id'] ?? 0),
                    'nominal_code' => (string)($row['nominal_code'] ?? ''),
                    'nominal_name' => (string)($row['nominal_name'] ?? ''),
                    'tax_treatment' => $treatment,
                    'allocation_basis' => $timeApportioned ? 'whole_accounting_period_inclusive_days' : 'actual_date',
                    'rule_code' => (string)($rule['rule_code'] ?? ''),
                    'rule_version' => (string)($rule['rule_version'] ?? ''),
                    'source_url' => (string)($rule['source_url'] ?? ''),
                    'source_label' => $sourceLabel,
                    'rule_source' => (string)($resolved['source'] ?? ''),
                ]
            );
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function depreciationCapitalRows(array $workings): array
    {
        $rows = [];
        foreach ((array)($workings['capital_add_backs'] ?? []) as $index => $row) {
            $rows[] = $this->auditRow('capital_add_back', $index + 1, (string)($row['journal_date'] ?? ''),
                trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? '')),
                $row['amount'] ?? 0, $row['amount'] ?? 0, $row);
        }
        foreach ((array)($workings['depreciation_add_back'] ?? []) as $index => $row) {
            $rows[] = $this->auditRow('depreciation', (int)($row['asset_id'] ?? $index + 1), '',
                trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? 'Depreciation')),
                $row['amount'] ?? 0, $row['amount'] ?? 0, $row);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function capitalAllowanceRows(array $workings): array
    {
        $source = array_merge(
            (array)($workings['aia_allocation'] ?? []),
            (array)($workings['disposals_balancing'] ?? [])
        );
        $rows = [];
        $seen = [];
        foreach ($source as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = implode(':', [(int)($row['asset_id'] ?? 0), (string)($row['pool_type'] ?? ''), (string)($row['allowance_type'] ?? ''), (float)($row['allowance_amount'] ?? 0)]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $this->auditRow('asset', (int)($row['asset_id'] ?? $index + 1), (string)($row['purchase_date'] ?? ''),
                trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? 'Capital allowance')),
                $row['addition_amount'] ?? $row['cost'] ?? 0, $row['allowance_amount'] ?? 0, $row);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function lossRows(array $workings, int $ctPeriodId): array
    {
        $rows = [];
        foreach ((array)($workings['losses'] ?? []) as $index => $row) {
            if ((int)($row['ct_period_id'] ?? $ctPeriodId) !== $ctPeriodId) {
                continue;
            }
            $rows[] = $this->auditRow('loss_schedule', (int)($row['accounting_period_id'] ?? $index + 1), '',
                (string)($row['label'] ?? 'Loss schedule'), $row['loss_brought_forward'] ?? 0,
                $row['loss_utilised'] ?? 0, $row);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function rateRows(array $workings, array $summary): array
    {
        $rows = [];
        foreach ((array)($workings['rate_bands'] ?? []) as $index => $row) {
            $rows[] = $this->auditRow('rate_band', $index + 1, (string)($row['period_start'] ?? ''),
                (string)($row['financial_year'] ?? $row['label'] ?? 'Corporation Tax rate band'),
                $row['taxable_profit'] ?? $row['profit'] ?? 0,
                $row['liability'] ?? $row['corporation_tax'] ?? $row['tax'] ?? 0, $row);
        }
        if ($rows === []) {
            $rows[] = $this->auditRow('calculation_result', 0, '', 'Corporation Tax liability',
                $summary['taxable_profit'] ?? 0, $summary['estimated_corporation_tax'] ?? 0, [
                    'rate' => $summary['estimated_rate'] ?? 0,
                    'associated_company_count' => $summary['associated_company_count'] ?? 0,
                ]);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function auditRow(string $sourceType, int $sourceId, string $date, string $label, mixed $accountingAmount, mixed $taxAmount, array $meta): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_date' => $date,
            'label' => $label,
            'nominal_code' => (string)($meta['nominal_code'] ?? ''),
            'nominal_name' => (string)($meta['nominal_name'] ?? ''),
            'accounting_amount' => round((float)$accountingAmount, 2),
            'tax_adjustment_amount' => round((float)$taxAmount, 2),
            'tax_treatment' => (string)($meta['tax_treatment'] ?? ''),
            'allocation_method' => (string)($meta['allocation_basis'] ?? 'actual_date'),
            'rule_code' => (string)($meta['rule_code'] ?? ''),
            'rule_version' => (string)($meta['rule_version'] ?? ''),
            'rule_effective_from' => (string)($meta['effective_from'] ?? $meta['rule_effective_from'] ?? ''),
            'rule_effective_to' => (string)($meta['effective_to'] ?? $meta['rule_effective_to'] ?? ''),
            'rule_source_url' => (string)($meta['source_url'] ?? ''),
            'rule_source_checked_at' => (string)($meta['source_checked_at'] ?? ''),
            'source_label' => (string)($meta['source_label'] ?? $sourceType),
            'metadata' => $meta,
        ];
    }

    private function summaryAmount(array $summary, string $areaCode): float
    {
        return round(match ($areaCode) {
            'accounting_profit' => (float)($summary['accounting_profit'] ?? 0),
            'expense_treatments' => (float)($summary['disallowable_add_backs'] ?? 0),
            'depreciation_capital' => (float)($summary['capital_add_backs'] ?? 0) + (float)($summary['depreciation_add_back'] ?? 0),
            'capital_allowances' => (float)($summary['capital_allowances'] ?? 0),
            'losses' => (float)($summary['losses_used'] ?? 0),
            'tax_liability' => (float)($summary['estimated_corporation_tax'] ?? 0),
            default => 0.0,
        }, 2);
    }

    private function rowTotal(array $rows, string $areaCode, float $expected): float
    {
        $field = $areaCode === 'accounting_profit' ? 'accounting_amount' : 'tax_adjustment_amount';
        $total = round(array_sum(array_map(static fn(array $row): float => (float)($row[$field] ?? 0), $rows)), 2);
        return $rows === [] ? $expected : $total;
    }

    /** @return array<string, mixed> */
    private function periodScope(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company, accounting period and CT period.']];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return is_array($period)
            ? ['available' => true, 'period' => $period, 'errors' => []]
            : ['available' => false, 'errors' => ['The selected CT period does not belong to this company and accounting period.']];
    }

    private function snapshotHeader(int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        if (!\InterfaceDB::tableExists('corporation_tax_audit_snapshots') || !\InterfaceDB::tableExists('corporation_tax_audit_areas')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT s.* FROM corporation_tax_audit_snapshots s
             INNER JOIN corporation_tax_periods p ON p.latest_computation_run_id = s.computation_run_id
             WHERE s.company_id = :company_id AND s.accounting_period_id = :accounting_period_id
               AND s.ct_period_id = :ct_period_id AND p.id = s.ct_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'ct_period_id' => $ctPeriodId]
        );
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function normaliseStoredArea(array $row): array
    {
        foreach (['amount', 'expected_amount', 'reconciliation_difference'] as $field) {
            $row[$field] = round((float)($row[$field] ?? 0), 2);
        }
        $row['source_count'] = (int)($row['source_count'] ?? 0);
        return $row;
    }

    /** @return array<string, mixed> */
    private function paginate(array $detail, int $page): array
    {
        if (empty($detail['available'])) {
            return $detail;
        }
        $allRows = array_values((array)($detail['rows'] ?? []));
        $perPage = 50;
        $pageCount = max(1, (int)ceil(count($allRows) / $perPage));
        $page = max(1, min($page, $pageCount));
        $detail['rows'] = array_slice($allRows, ($page - 1) * $perPage, $perPage);
        $detail['pagination'] = ['page' => $page, 'per_page' => $perPage, 'page_count' => $pageCount, 'total_rows' => count($allRows)];
        return $detail;
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        $json = json_encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('The Tax Audit basis could not be encoded.');
        }
        return $json;
    }
}
