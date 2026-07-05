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
        $journal = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $accountingPeriodId,
            self::JOURNAL_TAG,
            $this->journalKey($ctPeriodId)
        );
        $posted = $journal !== null ? $this->journalChargeAmount($journal) : 0.0;
        $unposted = round($estimate - $posted, 2);

        return [
            'available' => true,
            'ct_period_id' => $ctPeriodId,
            'estimated_corporation_tax' => $estimate,
            'posted_corporation_tax_charge' => $posted,
            'unposted_corporation_tax_adjustment' => $unposted,
            'journal' => $journal,
            'status' => abs($unposted) < 0.005 ? 'posted' : ($posted > 0 ? 'out_of_date' : 'not_posted'),
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

        $estimate = round((float)$position['estimated_corporation_tax'], 2);
        if ($estimate <= 0) {
            return ['success' => false, 'errors' => ['No Corporation Tax provision is needed for this CT period.']];
        }

        $expenseNominalId = $this->nominalId(['8500'], 'expense', 'corporation tax');
        $liabilityNominalId = $this->nominalId(['2200'], 'liability', 'corporation tax');
        if ($expenseNominalId <= 0 || $liabilityNominalId <= 0) {
            return ['success' => false, 'errors' => ['Corporation Tax expense and liability nominal accounts are required before posting the provision.']];
        }

        $label = (string)($ctPeriod['display_label'] ?? ('CT Period ' . (int)($ctPeriod['sequence_no'] ?? 0))) . ' ' . (string)$ctPeriod['period_start'] . ' to ' . (string)$ctPeriod['period_end'];
        return (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::JOURNAL_TAG,
            $this->journalKey($ctPeriodId),
            (string)$ctPeriod['period_end'],
            'Corporation Tax provision - ' . $label,
            [
                ['nominal_account_id' => $expenseNominalId, 'debit' => $estimate, 'credit' => 0.0, 'line_description' => $label],
                ['nominal_account_id' => $liabilityNominalId, 'debit' => 0.0, 'credit' => $estimate, 'line_description' => $label],
            ],
            'system_generated',
            null,
            null,
            'Posted from the Tax page selected CT period.',
            $changedBy
        );
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

    private function journalChargeAmount(array $journal): float
    {
        $amount = 0.0;
        foreach ((array)($journal['lines'] ?? []) as $line) {
            $type = (string)($line['nominal_account_type'] ?? $line['account_type'] ?? '');
            $name = strtolower((string)($line['nominal_name'] ?? ''));
            if ($type === 'expense' || str_contains($name, 'corporation tax')) {
                $amount += (float)($line['debit'] ?? 0) - (float)($line['credit'] ?? 0);
            }
        }

        return round(max(0.0, $amount), 2);
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
