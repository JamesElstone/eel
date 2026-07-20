<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Repository;

final class AccountingPeriodRepository
{
    public function fetchAccountingPeriods(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        return (array)\eel_accounts\Support\RequestCache::remember(
            'accounting-period.list',
            (string)$companyId,
            static fn(): array => \InterfaceDB::fetchAll(
                'SELECT id, label, period_start, period_end
                 FROM accounting_periods
                 WHERE company_id = :company_id
                 ORDER BY period_start DESC, id DESC',
                ['company_id' => $companyId]
            )
        );
    }

    public function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \eel_accounts\Support\RequestCache::remember(
            'accounting-period.row',
            $companyId . ':' . $accountingPeriodId,
            static fn(): array|false => \InterfaceDB::fetchOne(
                'SELECT id, company_id, label, period_start, period_end
                 FROM accounting_periods
                 WHERE company_id = :company_id
                   AND id = :id
                 LIMIT 1',
                [
                    'company_id' => $companyId,
                    'id' => $accountingPeriodId,
                ]
            )
        );

        return is_array($row) ? $row : null;
    }
    public function updatePeriod(int $companyId, int $accountingPeriodId, string $label, string $periodStart, string $periodEnd): void
    {
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            $accountingPeriodId,
            'change the accounting period details'
        );
        $label = trim($label) !== ''
            ? trim($label)
            : \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($periodStart, $periodEnd);

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            \InterfaceDB::prepareExecute('UPDATE accounting_periods SET label = ?, period_start = ?, period_end = ? WHERE id = ? AND company_id = ?', [
                $label,
                $periodStart,
                $periodEnd,
                $accountingPeriodId,
                $companyId,
            ]);
            $this->forgetRuntimeCache($companyId, $accountingPeriodId);

            (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
            $this->syncPrepaymentSchedules($companyId, $accountingPeriodId);
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $exception;
        }
    }

    public function validateOverlap(int $companyId, int $periodId, string $periodStart, string $periodEnd): array
    {
        $stmt = \InterfaceDB::prepareExecute('SELECT id, label, period_start, period_end FROM accounting_periods WHERE company_id = ? ORDER BY period_start, id', [$companyId]);
        $errors = [];

        foreach ($stmt->fetchAll() as $row) {
            $rowId = (int)$row['id'];

            if ($rowId === $periodId) {
                continue;
            }

            if ($this->periodsOverlap($periodStart, $periodEnd, (string)$row['period_start'], (string)$row['period_end'])) {
                $errors[] = 'The selected accounting period overlaps with existing accounting period "' . (string)$row['label'] . '".';
            }
        }

        return $errors;
    }

    public function validateSequence(int $companyId, int $periodId, string $periodStart, string $periodEnd): array
    {
        $stmt = \InterfaceDB::prepareExecute('SELECT id, label, period_start, period_end FROM accounting_periods WHERE company_id = ? ORDER BY period_start, id', [$companyId]);
        $periods = [];

        foreach ($stmt->fetchAll() as $row) {
            $rowId = (int)$row['id'];

            if ($rowId === $periodId) {
                continue;
            }

            $periods[] = [
                'id' => $rowId,
                'label' => (string)$row['label'],
                'period_start' => (string)$row['period_start'],
                'period_end' => (string)$row['period_end'],
            ];
        }

        $periods[] = [
            'id' => $periodId,
            'label' => \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($periodStart, $periodEnd),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];

        usort($periods, static function (array $a, array $b): int {
            return [$a['period_start'], $a['period_end'], $a['id']] <=> [$b['period_start'], $b['period_end'], $b['id']];
        });

        for ($index = 1, $count = count($periods); $index < $count; $index++) {
            $previousEnd = new \DateTimeImmutable($periods[$index - 1]['period_end']);
            $currentStart = new \DateTimeImmutable($periods[$index]['period_start']);
            $expectedStart = $previousEnd->modify('+1 day')->format('Y-m-d');

            if ($currentStart->format('Y-m-d') !== $expectedStart) {
                return [
                    'Accounting periods must be sequential with no gaps. "' . $periods[$index]['label'] . '" should start on ' . $expectedStart . '.',
                ];
            }
        }

        return [];
    }

    public function createPeriod(int $companyId, string $periodStart, string $periodEnd, ?string $label = null): int
    {
        $label = $label !== null && trim($label) !== ''
            ? trim($label)
            : \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel($periodStart, $periodEnd);
        if (\InterfaceDB::countWhere('accounting_periods', [
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) > 0) {
            $find = \InterfaceDB::prepareExecute('SELECT id FROM accounting_periods WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1', [$companyId, $periodStart, $periodEnd]);

            $id = (int)$find->fetchColumn();
            if ($id > 0) {
                $this->forgetRuntimeCache($companyId, $id);
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $id);
                $this->syncPrepaymentSchedules($companyId, $id);
            }

            return $id;
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            \InterfaceDB::prepareExecute('INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (?, ?, ?, ?)', [$companyId, $label, $periodStart, $periodEnd]);

            $find = \InterfaceDB::prepareExecute('SELECT id FROM accounting_periods WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1', [$companyId, $periodStart, $periodEnd]);

            $id = (int)$find->fetchColumn();
            if ($id > 0) {
                $this->forgetRuntimeCache($companyId, $id);
                (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $id);
                $this->syncPrepaymentSchedules($companyId, $id);
            }
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
            return $id;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $exception;
        }
    }

    private function periodsOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return !($endA < $startB || $startA > $endB);
    }

    private function syncPrepaymentSchedules(int $companyId, int $accountingPeriodId): void
    {
        $service = new \eel_accounts\Service\PrepaymentScheduleService();
        if (!$service->hasSchema()
            || (new \eel_accounts\Service\VatSupportScopeService())->isTaxAndYearEndReadOnly($companyId)) {
            return;
        }

        $result = $service->syncForAccountingPeriod($companyId, $accountingPeriodId, 'accounting_period_change');
        if (empty($result['success'])) {
            throw new \RuntimeException((string)(($result['errors'] ?? [])[0] ?? 'Prepayment schedules could not be synchronised for the accounting period.'));
        }
    }

    private function forgetRuntimeCache(int $companyId, int $accountingPeriodId): void
    {
        \eel_accounts\Support\RequestCache::forget('accounting-period.list', (string)$companyId);
        \eel_accounts\Support\RequestCache::forget('accounting-period.row', $companyId . ':' . $accountingPeriodId);
    }
}
