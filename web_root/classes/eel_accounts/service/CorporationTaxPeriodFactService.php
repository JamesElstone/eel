<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CorporationTaxPeriodFactService
{
    public function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period.'], 'periods' => []];
        }
        if (!\InterfaceDB::tableExists('corporation_tax_period_facts')) {
            return ['available' => false, 'errors' => ['Run the CT-period controls migration.'], 'periods' => []];
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT ctp.id AS ct_period_id, ctp.sequence_no, ctp.period_start, ctp.period_end, ctp.status,
                    fact.associated_company_count, fact.confirmed_at, fact.confirmed_by,
                    fact.confirmation_note, fact.basis_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_period_facts fact ON fact.ct_period_id = ctp.id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
               AND ctp.status <> \'superseded\'
             ORDER BY ctp.sequence_no, ctp.id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        foreach ($periods as &$period) {
            $expectedHash = $this->basisHash($period, (int)($period['associated_company_count'] ?? 0));
            $period['confirmed'] = trim((string)($period['confirmed_at'] ?? '')) !== ''
                && hash_equals($expectedHash, (string)($period['basis_hash'] ?? ''));
            $period['basis_current'] = $period['confirmed'];
        }
        unset($period);

        return [
            'available' => true,
            'errors' => [],
            'periods' => $periods,
            'all_confirmed' => $periods !== [] && !in_array(false, array_column($periods, 'confirmed'), true),
        ];
    }

    public function fetchForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_period_facts')) {
            return ['available' => false, 'confirmed' => false, 'errors' => ['Confirmed CT-period facts are not available.']];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT ctp.id AS ct_period_id, ctp.company_id, ctp.accounting_period_id,
                    ctp.sequence_no, ctp.period_start, ctp.period_end, ctp.status,
                    fact.associated_company_count, fact.confirmed_at, fact.confirmed_by,
                    fact.confirmation_note, fact.basis_hash
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_period_facts fact ON fact.ct_period_id = ctp.id
             WHERE ctp.id = :ct_period_id AND ctp.company_id = :company_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['available' => false, 'confirmed' => false, 'errors' => ['The selected CT period could not be found.']];
        }
        $expectedHash = $this->basisHash($period, (int)($period['associated_company_count'] ?? 0));
        $confirmed = trim((string)($period['confirmed_at'] ?? '')) !== ''
            && hash_equals($expectedHash, (string)($period['basis_hash'] ?? ''));
        return $period + [
            'available' => true,
            'confirmed' => $confirmed,
            'basis_current' => $confirmed,
            'errors' => $confirmed ? [] : ['Confirm the associated-company count for this CT period.'],
        ];
    }

    public function requireAssociatedCompanyCount(int $companyId, int $ctPeriodId): int
    {
        $fact = $this->fetchForCtPeriod($companyId, $ctPeriodId);
        if (empty($fact['available']) || empty($fact['confirmed'])) {
            throw new \RuntimeException((string)(($fact['errors'] ?? [])[0] ?? 'Confirm the associated-company count for this CT period.'));
        }
        return max(0, (int)$fact['associated_company_count']);
    }

    public function save(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $associatedCompanyCount,
        bool $confirmed,
        string $confirmedBy,
        string $note = ''
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0 || $associatedCompanyCount < 0) {
            return ['success' => false, 'errors' => ['Enter a non-negative associated-company count for a valid CT period.']];
        }
        (new YearEndLockService())->assertUnlocked(
            $companyId,
            $accountingPeriodId,
            'change CT-period associated-company facts'
        );
        $period = \InterfaceDB::fetchOne(
            'SELECT id AS ct_period_id, company_id, accounting_period_id, sequence_no, period_start, period_end
             FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected CT period does not belong to this accounting period.']];
        }
        $basisHash = $this->basisHash($period, $associatedCompanyCount);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'associated_company_count' => $associatedCompanyCount,
            'confirmed_at' => $confirmed ? $now : null,
            'confirmed_by' => $confirmed ? $this->actor($confirmedBy) : null,
            'confirmation_note' => trim($note) !== '' ? trim($note) : null,
            'basis_hash' => $confirmed ? $basisHash : null,
        ];
        $sql = 'INSERT INTO corporation_tax_period_facts (
                    company_id, accounting_period_id, ct_period_id, associated_company_count,
                    confirmed_at, confirmed_by, confirmation_note, basis_hash
                ) VALUES (
                    :company_id, :accounting_period_id, :ct_period_id, :associated_company_count,
                    :confirmed_at, :confirmed_by, :confirmation_note, :basis_hash
                )';
        if (\InterfaceDB::driverName() === 'sqlite') {
            $sql .= ' ON CONFLICT(ct_period_id) DO UPDATE SET
                associated_company_count = excluded.associated_company_count,
                confirmed_at = excluded.confirmed_at,
                confirmed_by = excluded.confirmed_by,
                confirmation_note = excluded.confirmation_note,
                basis_hash = excluded.basis_hash,
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE
                associated_company_count = VALUES(associated_company_count),
                confirmed_at = VALUES(confirmed_at),
                confirmed_by = VALUES(confirmed_by),
                confirmation_note = VALUES(confirmation_note),
                basis_hash = VALUES(basis_hash),
                updated_at = CURRENT_TIMESTAMP';
        }
        \InterfaceDB::prepareExecute($sql, $params);
        return ['success' => true, 'errors' => [], 'fact' => $this->fetchForCtPeriod($companyId, $ctPeriodId)];
    }

    private function basisHash(array $period, int $associatedCompanyCount): string
    {
        return hash('sha256', json_encode([
            'version' => 'ct-period-facts-v1',
            'company_id' => (int)($period['company_id'] ?? 0),
            'accounting_period_id' => (int)($period['accounting_period_id'] ?? 0),
            'ct_period_id' => (int)($period['ct_period_id'] ?? $period['id'] ?? 0),
            'sequence_no' => (int)($period['sequence_no'] ?? 0),
            'period_start' => (string)($period['period_start'] ?? ''),
            'period_end' => (string)($period['period_end'] ?? ''),
            'associated_company_count' => max(0, $associatedCompanyCount),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        return $actor !== '' ? substr($actor, 0, 100) : 'web_app';
    }
}
