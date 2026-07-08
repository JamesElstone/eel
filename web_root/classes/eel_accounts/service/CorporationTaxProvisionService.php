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

    public function fetchPosition(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $ctPeriod = $this->validCtPeriod($companyId, $accountingPeriodId, $ctPeriodId);
        if ($ctPeriod === null) {
            return ['available' => false, 'errors' => ['Select a valid CT period.']];
        }

        $summary = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, $ctPeriodId);
        if (empty($summary['available'])) {
            return ['available' => false, 'errors' => (array)($summary['errors'] ?? ['The CT estimate is not available.'])];
        }

        $estimate = round((float)($summary['estimated_corporation_tax'] ?? 0), 2);
        $journals = $this->fetchProvisionJournals($companyId, $accountingPeriodId, $ctPeriodId);
        $posted = 0.0;
        foreach ($journals as $journal) {
            $posted += $this->journalChargeAmount($journal);
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

    public function fetchAccountingPeriodPosition(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period before reviewing Corporation Tax provisions.'], 'periods' => []];
        }

        $computation = new \eel_accounts\Service\CorporationTaxComputationService();
        $activePeriods = $computation->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId);
        $ctPeriods = (array)($activePeriods['periods'] ?? []);
        if ($ctPeriods === []) {
            return ['available' => false, 'errors' => (array)($activePeriods['errors'] ?? ['No CT periods are available for this accounting period.']), 'periods' => []];
        }

        $computation->preloadCtPeriodLossPositionsForAccountingPeriod($companyId, $accountingPeriodId);

        $periodPositions = [];
        $errors = (array)($activePeriods['errors'] ?? []);
        foreach ($ctPeriods as $ctPeriod) {
            $ctPeriodId = (int)($ctPeriod['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }

            $position = $this->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
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
        $ctPeriod = $this->validCtPeriod($companyId, $accountingPeriodId, $ctPeriodId);
        if ($ctPeriod === null) {
            return ['success' => false, 'errors' => ['Select a valid CT period.']];
        }

        $position = $this->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($position['available'])) {
            return ['success' => false, 'errors' => (array)($position['errors'] ?? ['The CT provision could not be calculated.'])];
        }

        $delta = round((float)$position['unposted_corporation_tax_adjustment'], 2);
        if (abs($delta) < 0.005) {
            return ['success' => true, 'errors' => [], 'skipped' => true, 'position' => $position];
        }

        $expenseNominalId = $this->nominalId(['8500'], 'expense', 'corporation tax');
        $liabilityNominalId = $this->nominalId(['2200'], 'liability', 'corporation tax');
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
                'Posted as a delta so nominal 8500/2200 matches the latest CT estimate at CT period end.',
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
            'position' => $this->fetchPosition($companyId, $accountingPeriodId, $ctPeriodId),
        ];
    }

    public function postProvisionsForAccountingPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
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

    private function validCtPeriod(int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        $ctPeriod = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetch($companyId, $ctPeriodId);
        if ($ctPeriod === null || (int)($ctPeriod['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
            return null;
        }

        return $ctPeriod;
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

    private function journalChargeAmount(array $journal): float
    {
        $amount = 0.0;
        foreach ((array)($journal['lines'] ?? []) as $line) {
            $type = (string)($line['nominal_account_type'] ?? $line['account_type'] ?? '');
            $code = (string)($line['nominal_code'] ?? '');
            $name = strtolower((string)($line['nominal_name'] ?? ''));
            if ($code === '8500' || ($type === 'expense' && str_contains($name, 'corporation tax'))) {
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

    private function nominalId(array $codes, string $accountType, string $nameContains): int
    {
        foreach ($codes as $code) {
            $id = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM nominal_accounts WHERE code = :code AND account_type = :account_type AND is_active = 1 LIMIT 1',
                ['code' => $code, 'account_type' => $accountType]
            );
            if ($id > 0) {
                return $id;
            }
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT id
             FROM nominal_accounts
             WHERE account_type = :account_type
               AND is_active = 1
               AND LOWER(name) LIKE :name
             ORDER BY code ASC
             LIMIT 1',
            ['account_type' => $accountType, 'name' => '%' . strtolower($nameContains) . '%']
        );
    }
}
