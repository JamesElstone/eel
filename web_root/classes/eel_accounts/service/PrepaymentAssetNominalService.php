<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentAssetNominalService
{
    /** @return list<array<string, mixed>> */
    public function fetchEligibleNominals(): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT na.id, na.code, na.name, na.account_type, na.account_subtype_id,
                    na.is_active, nas.code AS subtype_code
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.is_active = 1
               AND na.account_type = \'asset\'
               AND nas.code = \'prepayments\'
               AND nas.is_active = 1
             ORDER BY na.sort_order, na.code, na.name, na.id'
        );
    }

    /** @return array<string, mixed>|null */
    public function resolveForCompany(int $companyId): ?array
    {
        if ($companyId <= 0) {
            return null;
        }
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $configuredId = (int)($settings['prepayment_asset_nominal_id'] ?? 0);

        return $configuredId > 0
            ? $this->fetchEligibleById($configuredId)
            : $this->fetchDefault();
    }

    /** @return array<string, mixed> */
    public function requireSelection(int $nominalId): array
    {
        $nominal = $nominalId > 0 ? $this->fetchEligibleById($nominalId) : $this->fetchDefault();
        if (!is_array($nominal)) {
            throw new \RuntimeException(
                $nominalId > 0
                    ? 'The Prepayments nominal must be an active asset assigned to the Prepayments subtype.'
                    : 'Assign an active Prepayments current-asset nominal before saving the nominal defaults.'
            );
        }

        return $nominal;
    }

    /**
     * @return array{changed: bool, nominal: array<string, mixed>, review_ids: list<int>, accounting_period_ids: list<int>}
     */
    public function prepareChange(int $companyId, int $requestedNominalId): array
    {
        if ($companyId <= 0) {
            throw new \RuntimeException('Select a company before changing the Prepayments nominal.');
        }
        $nominal = $this->requireSelection($requestedNominalId);
        $newNominalId = (int)$nominal['id'];
        $current = $this->resolveForCompany($companyId);
        $currentNominalId = (int)($current['id'] ?? 0);

        if ($currentNominalId === $newNominalId || !\InterfaceDB::tableExists('prepayment_schedules')) {
            return [
                'changed' => $currentNominalId !== $newNominalId,
                'nominal' => $nominal,
                'review_ids' => [],
                'accounting_period_ids' => [],
            ];
        }

        $affected = \InterfaceDB::fetchAll(
            'SELECT pr.id AS review_id, ps.id AS schedule_id
             FROM prepayment_reviews pr
             INNER JOIN prepayment_schedules ps ON ps.id = pr.current_schedule_id
             WHERE pr.company_id = :company_id
               AND pr.status = \'prepaid\'
               AND ps.asset_nominal_id <> :asset_nominal_id
               AND ps.status IN (\'active\', \'complete\')
             ORDER BY pr.id',
            ['company_id' => $companyId, 'asset_nominal_id' => $newNominalId]
        );
        $reviewIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['review_id'],
            $affected
        )));
        if ($reviewIds === []) {
            return ['changed' => true, 'nominal' => $nominal, 'review_ids' => [], 'accounting_period_ids' => []];
        }

        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $postingRows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT ps.review_id, posting.accounting_period_id
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings posting ON posting.schedule_id = ps.id
             WHERE ps.review_id IN (' . $placeholders . ')
             ORDER BY ps.review_id, posting.accounting_period_id',
            $reviewIds
        );
        if ($postingRows !== []) {
            $details = implode(', ', array_map(
                static fn(array $row): string => 'review #' . (int)$row['review_id'] . '/AP #' . (int)$row['accounting_period_id'],
                $postingRows
            ));
            throw new \RuntimeException(
                'The Prepayments nominal cannot change while affected schedules have posted journals (' . $details . '). Reopen and compensate those schedules first.'
            );
        }

        $periodRows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT ps.review_id, psp.accounting_period_id,
                    COALESCE(yer.is_locked, 0) AS is_locked
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_periods psp ON psp.schedule_id = ps.id
             LEFT JOIN year_end_reviews yer
                    ON yer.company_id = ps.company_id
                   AND yer.accounting_period_id = psp.accounting_period_id
             WHERE ps.id IN (' . implode(',', array_fill(0, count($affected), '?')) . ')
             ORDER BY psp.accounting_period_id, ps.review_id',
            array_map(static fn(array $row): int => (int)$row['schedule_id'], $affected)
        );
        $locked = array_values(array_filter($periodRows, static fn(array $row): bool => !empty($row['is_locked'])));
        if ($locked !== []) {
            $details = implode(', ', array_map(
                static fn(array $row): string => 'review #' . (int)$row['review_id'] . '/AP #' . (int)$row['accounting_period_id'],
                $locked
            ));
            throw new \RuntimeException(
                'The Prepayments nominal cannot change while an affected accounting period is locked (' . $details . ').'
            );
        }

        return [
            'changed' => true,
            'nominal' => $nominal,
            'review_ids' => $reviewIds,
            'accounting_period_ids' => array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['accounting_period_id'],
                $periodRows
            ))),
        ];
    }

    /**
     * @param list<int> $reviewIds
     * @param list<int> $accountingPeriodIds
     */
    public function synchroniseChange(
        int $companyId,
        array $reviewIds,
        array $accountingPeriodIds,
        string $changedBy = 'web_app'
    ): void {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('A Prepayments nominal change must be synchronised inside a transaction.');
        }
        $schedules = new PrepaymentScheduleService();
        foreach ($reviewIds as $reviewId) {
            $result = $schedules->syncReviewSchedule((int)$reviewId, $changedBy);
            if (empty($result['success'])) {
                throw new \RuntimeException((string)(($result['errors'] ?? [])[0] ?? 'The affected prepayment schedule could not be recalculated.'));
            }
        }
        $acknowledgements = new YearEndAcknowledgementService();
        foreach ($accountingPeriodIds as $accountingPeriodId) {
            $acknowledgements->revoke($companyId, (int)$accountingPeriodId, 'prepayment_approvals');
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchEligibleById(int $nominalId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id, na.code, na.name, na.account_type, na.account_subtype_id,
                    na.is_active, nas.code AS subtype_code
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
               AND na.is_active = 1
               AND na.account_type = \'asset\'
               AND nas.code = \'prepayments\'
               AND nas.is_active = 1
             LIMIT 1',
            ['id' => $nominalId]
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchDefault(): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT na.id, na.code, na.name, na.account_type, na.account_subtype_id,
                    na.is_active, nas.code AS subtype_code
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.code = \'1150\'
               AND na.is_active = 1
               AND na.account_type = \'asset\'
               AND nas.code = \'prepayments\'
               AND nas.is_active = 1
             ORDER BY na.id
             LIMIT 1'
        );

        return is_array($row) ? $row : null;
    }
}
