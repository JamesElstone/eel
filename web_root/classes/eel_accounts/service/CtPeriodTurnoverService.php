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
 * Derives actual trading turnover by CT period and a reconciled whole-pound
 * presentation for CT600 box 145. It does not participate in tax arithmetic.
 */
final class CtPeriodTurnoverService
{
    public const BASIS_VERSION = 'ct-period-turnover-v1';

    public function __construct(
        private readonly ?PeriodLedgerReadService $ledgerService = null,
        private readonly ?IncomeStatementClassificationService $classificationService = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $ctPeriods
     * @return array<string,mixed>
     */
    public function fetch(int $companyId, int $accountingPeriodId, array $ctPeriods): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriods === []) {
            return $this->failure('A company, accounting period and at least one CT period are required.');
        }

        try {
            $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())
                ->fetchAccountingPeriod($companyId, $accountingPeriodId);
            if (!is_array($accountingPeriod)) {
                return $this->failure('The accounting period could not be found for turnover reconciliation.');
            }
            $accountingStart = (string)($accountingPeriod['period_start'] ?? '');
            $accountingEnd = (string)($accountingPeriod['period_end'] ?? '');
            $periods = $this->normalisePeriods($ctPeriods);
            $coverageError = $this->coverageError($periods, $accountingStart, $accountingEnd);
            if ($coverageError !== null) {
                return $this->failure($coverageError);
            }

            $ledger = $this->ledgerService ?? new PeriodLedgerReadService();
            $accountingPence = $this->turnoverPence(
                $ledger->fetch($ledger->scope(
                    $companyId,
                    $accountingPeriodId,
                    $accountingEnd,
                    $accountingStart
                ))->rows
            );
            $periodFacts = [];
            foreach ($periods as $period) {
                $actualPence = $this->turnoverPence(
                    $ledger->fetch($ledger->scope(
                        $companyId,
                        $accountingPeriodId,
                        (string)$period['period_end'],
                        (string)$period['period_start']
                    ))->rows
                );
                $periodFacts[] = array_replace($period, [
                    'actual_turnover_pence' => $actualPence,
                    'actual_turnover' => $this->pounds($actualPence),
                    'ct600_box_145_whole_pounds' => $this->roundPenceToWholePounds($actualPence),
                    'ct600_rounding_adjustment_whole_pounds' => 0,
                ]);
            }

            $periodPence = array_sum(array_column($periodFacts, 'actual_turnover_pence'));
            if ($periodPence !== $accountingPence) {
                return $this->failure(sprintf(
                    'CT-period trading turnover totals £%.2f but accounting-period turnover is £%.2f.',
                    $periodPence / 100,
                    $accountingPence / 100
                ));
            }
            foreach ($periodFacts as $periodFact) {
                if ((int)$periodFact['actual_turnover_pence'] < 0) {
                    return $this->failure(
                        'CT period ' . (int)$periodFact['sequence_no']
                        . ' has negative net trading turnover, which is not valid for CT600 box 145.'
                    );
                }
            }

            $rounding = $this->applyBox145Rounding($periodFacts, $accountingPence);
            $periodFacts = (array)$rounding['periods'];
            $accountingWholePounds = (int)$rounding['accounting_whole_pounds'];
            $residual = (int)$rounding['residual_whole_pounds'];
            $residualIndex = (int)$rounding['residual_index'];
            $finalWholePounds = array_sum(array_column($periodFacts, 'ct600_box_145_whole_pounds'));
            if ($finalWholePounds !== $accountingWholePounds) {
                return $this->failure('The reconciled CT600 box 145 values do not equal accounting-period turnover.');
            }

            return [
                'available' => true,
                'errors' => [],
                'basis_version' => self::BASIS_VERSION,
                'accounting_period_turnover' => $this->pounds($accountingPence),
                'ct_period_turnover_total' => $this->pounds($periodPence),
                'reconciliation_difference' => $this->pounds($periodPence - $accountingPence),
                'accounting_period_box_145_whole_pounds' => $accountingWholePounds,
                'ct_period_box_145_total_whole_pounds' => $finalWholePounds,
                'box_145_reconciliation_difference_whole_pounds' => $finalWholePounds - $accountingWholePounds,
                'rounding_residual_whole_pounds' => $residual,
                'rounding_residual_ct_period_id' => (int)$periodFacts[$residualIndex]['ct_period_id'],
                'periods' => $periodFacts,
            ];
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function turnoverPence(array $rows): int
    {
        $classifier = $this->classificationService ?? new IncomeStatementClassificationService();
        $pence = 0;
        foreach ($rows as $row) {
            if ((string)($row['account_type'] ?? '') !== 'income'
                || $classifier->incomeBucket($row) !== IncomeStatementClassificationService::INCOME_TURNOVER) {
                continue;
            }
            $pence += (int)round(
                ((float)($row['total_credit'] ?? 0) - (float)($row['total_debit'] ?? 0)) * 100,
                0,
                PHP_ROUND_HALF_UP
            );
        }
        return $pence;
    }

    /** @param list<array<string,mixed>> $periods */
    private function normalisePeriods(array $periods): array
    {
        $normalised = [];
        foreach ($periods as $period) {
            $id = (int)($period['id'] ?? $period['ct_period_id'] ?? 0);
            $start = (string)($period['period_start'] ?? '');
            $end = (string)($period['period_end'] ?? '');
            if ($id <= 0 || !$this->isDate($start) || !$this->isDate($end) || $start > $end) {
                throw new \RuntimeException('A valid dated CT period is required for turnover reconciliation.');
            }
            $normalised[] = [
                'ct_period_id' => $id,
                'sequence_no' => (int)($period['sequence_no'] ?? $period['ct_period_sequence_no'] ?? 0),
                'period_start' => $start,
                'period_end' => $end,
                'inclusive_days' => $this->inclusiveDays($start, $end),
            ];
        }
        usort($normalised, static fn(array $left, array $right): int => [
            $left['period_start'], $left['sequence_no'], $left['ct_period_id'],
        ] <=> [
            $right['period_start'], $right['sequence_no'], $right['ct_period_id'],
        ]);
        return $normalised;
    }

    /** @param list<array<string,mixed>> $periods */
    private function coverageError(array $periods, string $accountingStart, string $accountingEnd): ?string
    {
        if (!$this->isDate($accountingStart) || !$this->isDate($accountingEnd) || $accountingStart > $accountingEnd) {
            return 'The accounting-period dates are invalid for turnover reconciliation.';
        }
        $expectedStart = $accountingStart;
        foreach ($periods as $period) {
            if ((string)$period['period_start'] !== $expectedStart) {
                return 'The CT periods contain a gap or overlap and cannot reconcile turnover.';
            }
            $expectedStart = (new \DateTimeImmutable((string)$period['period_end']))
                ->modify('+1 day')
                ->format('Y-m-d');
        }
        return (string)end($periods)['period_end'] === $accountingEnd
            ? null
            : 'The CT periods do not cover the complete accounting period for turnover reconciliation.';
    }

    /** @param list<array<string,mixed>> $periodFacts */
    private function shortestPeriodIndex(array $periodFacts): int
    {
        $indexes = array_keys($periodFacts);
        usort($indexes, static fn(int $left, int $right): int => [
            (int)$periodFacts[$left]['inclusive_days'],
            0 - (int)str_replace('-', '', (string)$periodFacts[$left]['period_end']),
            0 - (int)$periodFacts[$left]['sequence_no'],
            0 - (int)$periodFacts[$left]['ct_period_id'],
        ] <=> [
            (int)$periodFacts[$right]['inclusive_days'],
            0 - (int)str_replace('-', '', (string)$periodFacts[$right]['period_end']),
            0 - (int)$periodFacts[$right]['sequence_no'],
            0 - (int)$periodFacts[$right]['ct_period_id'],
        ]);
        return (int)$indexes[0];
    }

    /**
     * @param list<array<string,mixed>> $periodFacts
     * @return array{periods:list<array<string,mixed>>,accounting_whole_pounds:int,residual_whole_pounds:int,residual_index:int}
     */
    private function applyBox145Rounding(array $periodFacts, int $accountingPence): array
    {
        $accountingWholePounds = $this->roundPenceToWholePounds($accountingPence);
        $periodWholePounds = array_sum(array_column($periodFacts, 'ct600_box_145_whole_pounds'));
        $residual = $accountingWholePounds - $periodWholePounds;
        $residualIndex = $this->shortestPeriodIndex($periodFacts);
        $periodFacts[$residualIndex]['ct600_box_145_whole_pounds'] += $residual;
        $periodFacts[$residualIndex]['ct600_rounding_adjustment_whole_pounds'] = $residual;
        $periodFacts[$residualIndex]['handles_ct600_rounding_residual'] = true;
        foreach ($periodFacts as &$periodFact) {
            $periodFact['handles_ct600_rounding_residual'] ??= false;
            $periodFact['ct600_box_145'] = number_format(
                (int)$periodFact['ct600_box_145_whole_pounds'],
                2,
                '.',
                ''
            );
            unset($periodFact['actual_turnover_pence']);
        }
        unset($periodFact);

        return [
            'periods' => $periodFacts,
            'accounting_whole_pounds' => $accountingWholePounds,
            'residual_whole_pounds' => $residual,
            'residual_index' => $residualIndex,
        ];
    }

    private function roundPenceToWholePounds(int $pence): int
    {
        if ($pence < 0) {
            throw new \RuntimeException('CT600 box 145 turnover cannot be negative.');
        }
        return intdiv($pence + 50, 100);
    }

    private function inclusiveDays(string $start, string $end): int
    {
        return (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
    }

    private function pounds(int $pence): float
    {
        return round($pence / 100, 2);
    }

    private function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function failure(string $message): array
    {
        return ['available' => false, 'errors' => [$message], 'periods' => []];
    }
}
