<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Stores the editable associated-company count used by each CT-period calculation. */
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
            'SELECT ctp.id AS ct_period_id, ctp.company_id, ctp.accounting_period_id,
                    ctp.sequence_no, ctp.period_start, ctp.period_end, ctp.status,
                    COALESCE(fact.associated_company_count, 0) AS associated_company_count
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_period_facts fact ON fact.ct_period_id = ctp.id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
               AND ctp.status <> \'superseded\'
             ORDER BY ctp.sequence_no, ctp.id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return ['available' => true, 'errors' => [], 'periods' => $periods];
    }

    public function fetchForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_period_facts')) {
            return ['available' => false, 'errors' => ['CT-period facts are not available.']];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT ctp.id AS ct_period_id, ctp.company_id, ctp.accounting_period_id,
                    ctp.sequence_no, ctp.period_start, ctp.period_end, ctp.status,
                    COALESCE(fact.associated_company_count, 0) AS associated_company_count
             FROM corporation_tax_periods ctp
             LEFT JOIN corporation_tax_period_facts fact ON fact.ct_period_id = ctp.id
             WHERE ctp.id = :ct_period_id AND ctp.company_id = :company_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['available' => false, 'errors' => ['The selected CT period could not be found.']];
        }

        return $period + ['available' => true, 'errors' => []];
    }

    public function requireAssociatedCompanyCount(int $companyId, int $ctPeriodId): int
    {
        $fact = $this->fetchForCtPeriod($companyId, $ctPeriodId);
        if (empty($fact['available'])) {
            throw new \RuntimeException((string)(($fact['errors'] ?? [])[0] ?? 'CT-period facts are not available.'));
        }
        return max(0, (int)$fact['associated_company_count']);
    }

    public function save(int $companyId, int $accountingPeriodId, int $ctPeriodId, int $associatedCompanyCount): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0 || $associatedCompanyCount < 0) {
            return ['success' => false, 'errors' => ['Enter a non-negative associated-company count for a valid CT period.']];
        }
        (new YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change CT-period associated-company facts');
        $period = \InterfaceDB::fetchOne(
            'SELECT id FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected CT period does not belong to this accounting period.']];
        }

        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'associated_company_count' => $associatedCompanyCount,
        ];
        $sql = 'INSERT INTO corporation_tax_period_facts (
                    company_id, accounting_period_id, ct_period_id, associated_company_count,
                    confirmed_at, confirmed_by, confirmation_note, basis_hash
                ) VALUES (
                    :company_id, :accounting_period_id, :ct_period_id, :associated_company_count,
                    NULL, NULL, NULL, NULL
                )';
        if (\InterfaceDB::driverName() === 'sqlite') {
            $sql .= ' ON CONFLICT(ct_period_id) DO UPDATE SET
                associated_company_count = excluded.associated_company_count,
                confirmed_at = NULL, confirmed_by = NULL, confirmation_note = NULL, basis_hash = NULL,
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE
                associated_company_count = VALUES(associated_company_count),
                confirmed_at = NULL, confirmed_by = NULL, confirmation_note = NULL, basis_hash = NULL,
                updated_at = CURRENT_TIMESTAMP';
        }
        \InterfaceDB::prepareExecute($sql, $params);

        return ['success' => true, 'errors' => [], 'fact' => $this->fetchForCtPeriod($companyId, $ctPeriodId)];
    }
}
