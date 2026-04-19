<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TaxYearRepository
{
    public function fetchTaxYears(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC',
            ['company_id' => $companyId]
        );
    }

    public function fetchTaxYear(int $companyId, int $taxYearId): ?array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'id' => $taxYearId,
            ]
        );

        return is_array($row) ? $row : null;
    }
    public function updatePeriod(int $companyId, int $taxYearId, string $label, string $periodStart, string $periodEnd): void
    {
        InterfaceDB::prepareExecute('UPDATE tax_years SET label = ?, period_start = ?, period_end = ? WHERE id = ? AND company_id = ?', [
            $label,
            $periodStart,
            $periodEnd,
            $taxYearId,
            $companyId,
        ]);
    }

    public function validateOverlap(int $companyId, int $periodId, string $periodStart, string $periodEnd): array
    {
        $stmt = InterfaceDB::prepareExecute('SELECT id, label, period_start, period_end FROM tax_years WHERE company_id = ? ORDER BY period_start, id', [$companyId]);
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
        $stmt = InterfaceDB::prepareExecute('SELECT id, label, period_start, period_end FROM tax_years WHERE company_id = ? ORDER BY period_start, id', [$companyId]);
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
            'label' => HelperFramework::accountingPeriodLabel($periodStart, $periodEnd, $companyId),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];

        usort($periods, static function (array $a, array $b): int {
            return [$a['period_start'], $a['period_end'], $a['id']] <=> [$b['period_start'], $b['period_end'], $b['id']];
        });

        for ($index = 1, $count = count($periods); $index < $count; $index++) {
            $previousEnd = new DateTimeImmutable($periods[$index - 1]['period_end']);
            $currentStart = new DateTimeImmutable($periods[$index]['period_start']);
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
        $label = $label !== null && $label !== '' ? $label : HelperFramework::accountingPeriodLabel($periodStart, $periodEnd, $companyId);
        if (InterfaceDB::countWhere('tax_years', [
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]) > 0) {
            $find = InterfaceDB::prepareExecute('SELECT id FROM tax_years WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1', [$companyId, $periodStart, $periodEnd]);

            return (int)$find->fetchColumn();
        }

        InterfaceDB::prepareExecute('INSERT INTO tax_years (company_id, label, period_start, period_end) VALUES (?, ?, ?, ?)', [$companyId, $label, $periodStart, $periodEnd]);

        $find = InterfaceDB::prepareExecute('SELECT id FROM tax_years WHERE company_id = ? AND period_start = ? AND period_end = ? ORDER BY id DESC LIMIT 1', [$companyId, $periodStart, $periodEnd]);

        return (int)$find->fetchColumn();
    }

    private function periodsOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return !($endA < $startB || $startA > $endB);
    }
}
