<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CorporationTaxProvisionService
{
    public const JOURNAL_TAG = 'corporation_tax_provision';

    public function __construct(private readonly ?CorporationTaxComputationService $computationService = null)
    {
    }

    public function fetchPosition(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?CorporationTaxComputationService $computationService = null
    ): array
    {
        $computation = $computationService ?? $this->computationService ?? new CorporationTaxComputationService();
        $ctPeriod = $this->validCtPeriod(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computation
        );
        if ($ctPeriod === null) {
            return ['available' => false, 'errors' => ['Select a valid CT period.']];
        }
        $resolvedCtPeriodId = (int)($ctPeriod['id'] ?? 0);
        if ($resolvedCtPeriodId === 0) {
            return ['available' => false, 'errors' => ['Select a valid CT period.']];
        }

        $summary = $computation->fetchSummaryForCtPeriodId($companyId, $resolvedCtPeriodId);
        if (empty($summary['available'])) {
            return ['available' => false, 'errors' => (array)($summary['errors'] ?? ['The CT estimate is not available.'])];
        }

        return $this->positionFromSummary(
            $companyId,
            $accountingPeriodId,
            $resolvedCtPeriodId,
            $summary
        );
    }

    private function positionFromSummary(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $summary): array
    {
        if (empty($summary['available'])) {
            return ['available' => false, 'errors' => (array)($summary['errors'] ?? ['The CT estimate is not available.'])];
        }

        $estimate = round((float)($summary['estimated_corporation_tax'] ?? 0), 2);
        $journals = $this->fetchProvisionJournals($companyId, $accountingPeriodId, $ctPeriodId);
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $expenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        $posted = 0.0;
        foreach ($journals as $journal) {
            $posted += $this->journalChargeAmount($journal, $expenseNominalId);
        }
        $posted = round($posted, 2);
        $unposted = round($estimate - $posted, 2);
        $journal = $journals !== [] ? $journals[count($journals) - 1] : null;

        return [
            'available' => true,
            'ct_period_id' => $ctPeriodId,
            'estimated_corporation_tax' => $estimate,
            'posted_corporation_tax_charge' => $posted,
            'unposted_corporation_tax_adjustment' => $unposted,
            'journal' => $journal,
            'journals' => $journals,
            'status' => $this->positionStatus($estimate, $posted, $unposted),
        ];
    }

    public function fetchAccountingPeriodPosition(
        int $companyId,
        int $accountingPeriodId,
        ?array $precomputedPeriodSummaries = null
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period before reviewing Corporation Tax provisions.'], 'periods' => []];
        }

        $computation = $this->computationService ?? new CorporationTaxComputationService();
        $activePeriods = $computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $ctPeriods = (array)($activePeriods['periods'] ?? []);
        if ($ctPeriods === []) {
            return ['available' => false, 'errors' => (array)($activePeriods['errors'] ?? ['No CT periods are available for this accounting period.']), 'periods' => []];
        }

        if ($precomputedPeriodSummaries === null) {
            $computation->preloadCtPeriodLossPositionsForAccountingPeriod($companyId, $accountingPeriodId);
        }

        $precomputedByCtPeriodId = [];
        foreach ($precomputedPeriodSummaries ?? [] as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $ctPeriodId = (int)($summary['ct_period_id'] ?? 0);
            if ($ctPeriodId !== 0) {
                $precomputedByCtPeriodId[$ctPeriodId] = $summary;
            }
        }

        $periodPositions = [];
        $errors = (array)($activePeriods['errors'] ?? []);
        foreach ($ctPeriods as $ctPeriod) {
            $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
            if ($ctPeriodId === 0) {
                continue;
            }

            if ($precomputedPeriodSummaries !== null) {
                $summary = $precomputedByCtPeriodId[$ctPeriodId] ?? null;
                $position = is_array($summary)
                    ? $this->positionFromSummary($companyId, $accountingPeriodId, $ctPeriodId, $summary)
                    : ['available' => false, 'errors' => ['The precomputed CT summary is missing for this CT period.']];
            } else {
                $position = $this->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId, $computation);
            }
            if (empty($position['available'])) {
                foreach ((array)($position['errors'] ?? ['The CT provision position could not be calculated.']) as $error) {
                    $errors[] = (string)($ctPeriod['display_label'] ?? ('CT period ' . (int)($ctPeriod['sequence_no'] ?? 0))) . ': ' . (string)$error;
                }
                continue;
            }

            $periodPositions[] = array_merge($position, [
                'period_start' => (string)($ctPeriod['period_start'] ?? ''),
                'period_end' => (string)($ctPeriod['period_end'] ?? ''),
                'period_label' => $this->periodLabel($ctPeriod),
            ]);
        }

        if ($periodPositions === []) {
            return ['available' => false, 'errors' => $errors !== [] ? $errors : ['No CT provision positions could be calculated.'], 'periods' => []];
        }

        $estimated = round(array_sum(array_map(static fn(array $period): float => (float)($period['estimated_corporation_tax'] ?? 0), $periodPositions)), 2);
        $posted = round(array_sum(array_map(static fn(array $period): float => (float)($period['posted_corporation_tax_charge'] ?? 0), $periodPositions)), 2);
        $unposted = round($estimated - $posted, 2);

        return [
            'available' => $errors === [],
            'errors' => $errors,
            'periods' => $periodPositions,
            'estimated_corporation_tax' => $estimated,
            'posted_corporation_tax_charge' => $posted,
            'unposted_corporation_tax_adjustment' => $unposted,
            'status' => $this->positionStatus($estimated, $posted, $unposted),
        ];
    }

    public function postProvision(int $companyId, int $accountingPeriodId, int $ctPeriodId, string $changedBy = 'web_app'): array
    {
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'post a Corporation Tax Year End provision');

        $computation = $this->computationService ?? new CorporationTaxComputationService();
        $resolved = $this->synchroniseProvisionPeriod(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computation
        );
        if (empty($resolved['success'])) {
            return [
                'success' => false,
                'errors' => (array)($resolved['errors'] ?? ['Select a valid CT period.']),
            ];
        }
        $ctPeriodId = (int)($resolved['ct_period_id'] ?? 0);
        $ctPeriod = (array)($resolved['period'] ?? []);

        $position = $this->fetchPosition(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computation
        );
        if (empty($position['available'])) {
            return ['success' => false, 'errors' => (array)($position['errors'] ?? ['The CT provision could not be calculated.'])];
        }

        $delta = round((float)$position['unposted_corporation_tax_adjustment'], 2);
        if (abs($delta) < 0.005) {
            return ['success' => true, 'errors' => [], 'skipped' => true, 'position' => $position];
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $expenseNominalId = (int)($settings['corporation_tax_expense_nominal_id'] ?? 0);
        $liabilityNominalId = (int)($settings['corporation_tax_liability_nominal_id'] ?? 0);
        if ($expenseNominalId <= 0 || $liabilityNominalId <= 0) {
            return ['success' => false, 'errors' => ['Corporation Tax expense and liability nominal accounts are required before posting the provision.']];
        }

        $label = (string)($ctPeriod['display_label'] ?? ('CT Period ' . (int)($ctPeriod['sequence_no'] ?? 0))) . ' ' . (string)$ctPeriod['period_start'] . ' to ' . (string)$ctPeriod['period_end'];
        $amount = abs($delta);
        $description = $delta > 0
            ? 'Corporation Tax provision - ' . $label
            : 'Corporation Tax provision reversal - ' . $label;
        $lines = $delta > 0
            ? [
                ['nominal_account_id' => $expenseNominalId, 'debit' => $amount, 'credit' => 0.0, 'line_description' => $label],
                ['nominal_account_id' => $liabilityNominalId, 'debit' => 0.0, 'credit' => $amount, 'line_description' => $label],
            ]
            : [
                ['nominal_account_id' => $liabilityNominalId, 'debit' => $amount, 'credit' => 0.0, 'line_description' => $label],
                ['nominal_account_id' => $expenseNominalId, 'debit' => 0.0, 'credit' => $amount, 'line_description' => $label],
            ];

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $result = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                $companyId,
                $accountingPeriodId,
                self::JOURNAL_TAG,
                $this->journalKey($ctPeriodId),
                (string)$ctPeriod['period_end'],
                $description,
                $lines,
                'system_generated',
                null,
                null,
                'Posted as a delta so the configured Corporation Tax expense and liability nominals match the latest CT estimate at CT period end.',
                $changedBy
            );
            if (empty($result['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return $result;
            }

            $accountingPeriodPosition = $this->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
            if (!empty($accountingPeriodPosition['available'])) {
                $sync = (new \eel_accounts\Service\HmrcObligationService())->syncCtPaymentAmountForAccountingPeriod(
                    $companyId,
                    $accountingPeriodId,
                    (float)$accountingPeriodPosition['estimated_corporation_tax']
                );
                if (empty($sync['success'])) {
                    throw new \RuntimeException(implode(' ', (array)($sync['errors'] ?? ['The HMRC CT payment obligation could not be updated.'])));
                }
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $result + [
            'ct_period_id' => $ctPeriodId,
            'position' => $this->fetchPosition(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $computation
            ),
        ];
    }

    public function postProvisionsForAccountingPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'post Corporation Tax Year End provisions');

        $periodSync = (new \eel_accounts\Service\CorporationTaxPeriodService())
            ->syncForAccountingPeriod($companyId, $accountingPeriodId);
        if (empty($periodSync['success'])) {
            return [
                'success' => false,
                'errors' => (array)($periodSync['errors'] ?? ['Corporation Tax periods could not be synchronised before posting provisions.']),
                'results' => [],
                'position' => [],
            ];
        }
        if ($this->computationService !== null) {
            $this->computationService->clearRuntimeCaches();
        }

        $position = $this->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
        if (empty($position['available'])) {
            return ['success' => false, 'errors' => (array)($position['errors'] ?? ['Corporation Tax provision position could not be calculated.'])];
        }

        $results = [];
        $errors = [];
        foreach ((array)$position['periods'] as $period) {
            $ctPeriodId = (int)($period['ct_period_id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }

            $result = $this->postProvision($companyId, $accountingPeriodId, $ctPeriodId, $changedBy);
            $results[] = $result + ['ct_period_id' => $ctPeriodId];
            if (empty($result['success'])) {
                foreach ((array)($result['errors'] ?? ['A Corporation Tax provision could not be posted.']) as $error) {
                    $errors[] = (string)$error;
                }
            }
        }

        $finalPosition = $this->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
        if (!empty($finalPosition['available'])) {
            $sync = (new \eel_accounts\Service\HmrcObligationService())->syncCtPaymentAmountForAccountingPeriod(
                $companyId,
                $accountingPeriodId,
                (float)$finalPosition['estimated_corporation_tax']
            );
            if (empty($sync['success'])) {
                foreach ((array)($sync['errors'] ?? ['The HMRC CT payment obligation could not be updated.']) as $error) {
                    $errors[] = (string)$error;
                }
            }
        }

        return [
            'success' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'results' => $results,
            'position' => $finalPosition,
        ];
    }

    private function validCtPeriod(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?CorporationTaxComputationService $computationService = null
    ): ?array
    {
        if ($ctPeriodId > 0) {
            $ctPeriod = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
            if ($ctPeriod === null || (int)($ctPeriod['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
                return null;
            }

            return $ctPeriod;
        }

        if ($ctPeriodId >= 0) {
            return null;
        }

        $activePeriods = ($computationService ?? $this->computationService ?? new CorporationTaxComputationService())
            ->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        foreach ((array)($activePeriods['periods'] ?? []) as $ctPeriod) {
            if (
                (int)($ctPeriod['id'] ?? 0) === $ctPeriodId
                || \eel_accounts\Service\CorporationTaxPeriodService::transientReferenceId(
                    $accountingPeriodId,
                    (int)($ctPeriod['sequence_no'] ?? 0)
                ) === $ctPeriodId
            ) {
                return $ctPeriod;
            }
        }

        return null;
    }

    private function synchroniseProvisionPeriod(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        CorporationTaxComputationService $computation
    ): array
    {
        $previewPeriod = $this->validCtPeriod(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computation
        );
        if ($previewPeriod === null) {
            return ['success' => false, 'errors' => ['Select a valid CT period.']];
        }

        $periodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $sync = $periodService->syncForAccountingPeriod($companyId, $accountingPeriodId);
        if (empty($sync['success'])) {
            return [
                'success' => false,
                'errors' => (array)($sync['errors'] ?? ['Corporation Tax periods could not be synchronised before posting the provision.']),
            ];
        }

        $resolvedPeriod = null;
        foreach ((array)($sync['periods'] ?? []) as $period) {
            if (
                ($ctPeriodId > 0 && (int)($period['id'] ?? 0) === $ctPeriodId)
                || (
                    $ctPeriodId < 0
                    && (int)($period['sequence_no'] ?? 0) === (int)($previewPeriod['sequence_no'] ?? 0)
                    && (string)($period['period_start'] ?? '') === (string)($previewPeriod['period_start'] ?? '')
                    && (string)($period['period_end'] ?? '') === (string)($previewPeriod['period_end'] ?? '')
                )
            ) {
                $resolvedPeriod = $period;
                break;
            }
        }

        if ($resolvedPeriod === null) {
            return [
                'success' => false,
                'errors' => ['The selected CT period could not be resolved after synchronisation.'],
            ];
        }

        $computation->clearRuntimeCaches();

        return [
            'success' => true,
            'errors' => [],
            'ct_period_id' => (int)($resolvedPeriod['id'] ?? 0),
            'period' => $resolvedPeriod,
        ];
    }

    private function journalKey(int $ctPeriodId): string
    {
        return 'ct_period_' . $ctPeriodId;
    }

    private function fetchProvisionJournals(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $key = $this->journalKey($ctPeriodId);
        $rows = \InterfaceDB::fetchAll(
            'SELECT j.id,
                    j.company_id,
                    j.accounting_period_id,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.journal_date,
                    j.description,
                    j.is_posted,
                    jem.journal_tag,
                    jem.journal_key,
                    jem.entry_mode,
                    jem.related_journal_id,
                    jem.replacement_of_journal_id,
                    COALESCE(jem.notes, \'\') AS notes
             FROM journal_entry_metadata jem
             INNER JOIN journals j ON j.id = jem.journal_id
             WHERE jem.company_id = :company_id
               AND jem.accounting_period_id = :accounting_period_id
               AND jem.journal_tag = :journal_tag
               AND (jem.journal_key = :journal_key OR jem.journal_key LIKE :journal_key_prefix)
               AND j.is_posted = 1
             ORDER BY j.id ASC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_tag' => self::JOURNAL_TAG,
                'journal_key' => $key,
                'journal_key_prefix' => $key . ':%',
            ]
        );

        foreach ($rows as &$row) {
            $row['lines'] = \InterfaceDB::fetchAll(
                'SELECT jl.id,
                        jl.nominal_account_id,
                        jl.company_account_id,
                        jl.debit,
                        jl.credit,
                        COALESCE(jl.line_description, \'\') AS line_description,
                        COALESCE(na.code, \'\') AS nominal_code,
                        COALESCE(na.name, \'\') AS nominal_name,
                        COALESCE(na.account_type, \'\') AS nominal_account_type
                 FROM journal_lines jl
                 LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
                 WHERE jl.journal_id = :journal_id
                 ORDER BY jl.id ASC',
                ['journal_id' => (int)$row['id']]
            );
        }
        unset($row);

        return $rows;
    }

    private function journalChargeAmount(array $journal, int $expenseNominalId): float
    {
        $amount = 0.0;
        foreach ((array)($journal['lines'] ?? []) as $line) {
            if ($expenseNominalId > 0 && (int)($line['nominal_account_id'] ?? 0) === $expenseNominalId) {
                $amount += (float)($line['debit'] ?? 0) - (float)($line['credit'] ?? 0);
            }
        }

        return round($amount, 2);
    }

    private function positionStatus(float $estimate, float $posted, float $unposted): string
    {
        if (abs($unposted) < 0.005) {
            return $estimate > 0.004 || abs($posted) > 0.004 ? 'posted' : 'not_required';
        }

        return abs($posted) > 0.004 ? 'out_of_date' : 'not_posted';
    }

    private function periodLabel(array $ctPeriod): string
    {
        $label = trim((string)($ctPeriod['display_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $start = trim((string)($ctPeriod['period_start'] ?? ''));
        $end = trim((string)($ctPeriod['period_end'] ?? ''));
        return trim($start . ' to ' . $end) !== 'to' ? trim($start . ' to ' . $end) : 'CT period';
    }

}
