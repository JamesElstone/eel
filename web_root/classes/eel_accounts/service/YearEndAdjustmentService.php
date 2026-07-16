<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndAdjustmentService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\ManualJournalService $journalService = null,
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $company = $metrics->fetchCompanySummary($companyId);

        if ($accountingPeriod === null || $company === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        return [
            'available' => true,
            'company' => $company,
            'accounting_period' => $accountingPeriod,
            'next_accounting_period' => $this->fetchNextAccountingPeriod($companyId, (string)$accountingPeriod['period_end']),
            'nominals' => $this->fetchNominals(),
            'adjustments' => ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())
                ->listJournalsByTags($companyId, $accountingPeriodId, ['year_end_adjustment', 'year_end_adjustment_reversal']),
        ];
    }

    public function createAdjustment(int $companyId, int $accountingPeriodId, array $payload, string $changedBy = 'web_app'): array {
        $scopeBlock = (new VatSupportScopeService())->mutationBlockResult($companyId, 'create a Year End adjustment');
        if ($scopeBlock !== null) {
            return $scopeBlock;
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context;
        }

        $template = trim((string)($payload['template_type'] ?? 'custom'));
        $description = trim((string)($payload['description'] ?? ''));
        $journalDate = trim((string)($payload['journal_date'] ?? (string)$context['accounting_period']['period_end']));
        $notes = trim((string)($payload['notes'] ?? ''));
        $key = trim((string)($payload['journal_key'] ?? ''));
        if ($key === '') {
            $key = 'adj-' . strtolower(bin2hex(random_bytes(4)));
        }

        $lines = $this->buildLinesFromPayload($payload, $template);
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $result = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                $companyId,
                $accountingPeriodId,
                'year_end_adjustment',
                $key,
                $journalDate,
                $description !== '' ? $description : ucfirst(str_replace('_', ' ', $template)) . ' adjustment',
                $lines,
                'manual',
                null,
                null,
                $notes,
                $changedBy
            );

            if (empty($result['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }

                return $result;
            }

            $reversalJournal = null;
            if (!empty($payload['auto_reverse'])) {
                $nextAccountingPeriod = $context['next_accounting_period'] ?? null;
                if ($nextAccountingPeriod === null) {
                    if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                        \InterfaceDB::rollBack();
                    }

                    return [
                        'success' => false,
                        'errors' => ['A next accounting period is required before an automatic reversal can be created.'],
                    ];
                }

                $reversalLines = array_map(static function (array $line): array {
                    return [
                        'nominal_account_id' => (int)$line['nominal_account_id'],
                        'director_id' => (int)($line['director_id'] ?? 0) ?: null,
                        'debit' => number_format((float)($line['credit'] ?? 0), 2, '.', ''),
                        'credit' => number_format((float)($line['debit'] ?? 0), 2, '.', ''),
                        'line_description' => (string)($line['line_description'] ?? ''),
                    ];
                }, (array)($result['journal']['lines'] ?? []));

                $reversal = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                    $companyId,
                    (int)$nextAccountingPeriod['id'],
                    'year_end_adjustment_reversal',
                    'reversal-of-' . (int)$result['journal']['id'],
                    (string)$nextAccountingPeriod['period_start'],
                    'Reversal of ' . (string)($result['journal']['description'] ?? 'year end adjustment'),
                    $reversalLines,
                    'system_generated',
                    (int)$result['journal']['id'],
                    null,
                    'Automatic reversal generated from the prior accounting period adjustment.',
                    $changedBy
                );

                if (empty($reversal['success'])) {
                    if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                        \InterfaceDB::rollBack();
                    }

                    return $reversal;
                }

                $reversalJournal = $reversal['journal'] ?? null;
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'journal' => $result['journal'],
                'reversal_journal' => $reversalJournal,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    private function buildLinesFromPayload(array $payload, string $template): array {
        if ($template === 'custom') {
            return is_array($payload['lines'] ?? null) ? (array)$payload['lines'] : [];
        }

        $primaryNominalId = (int)($payload['primary_nominal_id'] ?? 0);
        $offsetNominalId = (int)($payload['offset_nominal_id'] ?? 0);
        $amount = round((float)($payload['amount'] ?? 0), 2);
        $lineDescription = trim((string)($payload['line_description'] ?? $payload['description'] ?? ''));

        if ($primaryNominalId <= 0 || $offsetNominalId <= 0 || $amount <= 0) {
            return [];
        }

        return match ($template) {
            'accrual' => [
                ['nominal_account_id' => $primaryNominalId, 'debit' => number_format($amount, 2, '.', ''), 'credit' => '0.00', 'line_description' => $lineDescription],
                ['nominal_account_id' => $offsetNominalId, 'debit' => '0.00', 'credit' => number_format($amount, 2, '.', ''), 'line_description' => $lineDescription],
            ],
            'prepayment' => [
                ['nominal_account_id' => $offsetNominalId, 'debit' => number_format($amount, 2, '.', ''), 'credit' => '0.00', 'line_description' => $lineDescription],
                ['nominal_account_id' => $primaryNominalId, 'debit' => '0.00', 'credit' => number_format($amount, 2, '.', ''), 'line_description' => $lineDescription],
            ],
            'deferred_income' => [
                ['nominal_account_id' => $primaryNominalId, 'debit' => number_format($amount, 2, '.', ''), 'credit' => '0.00', 'line_description' => $lineDescription],
                ['nominal_account_id' => $offsetNominalId, 'debit' => '0.00', 'credit' => number_format($amount, 2, '.', ''), 'line_description' => $lineDescription],
            ],
            default => [],
        };
    }

    private function fetchNominals(): array {
        $stmt = \InterfaceDB::query(
            'SELECT na.id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type
             FROM nominal_accounts na
             WHERE COALESCE(na.is_active, 0) = 1
             ORDER BY COALESCE(na.sort_order, 100), COALESCE(na.code, \'\'), COALESCE(na.name, \'\'), na.id'
        );

        return $stmt->fetchAll() ?: [];
    }

    private function fetchNextAccountingPeriod(int $companyId, string $periodEnd): ?array {
        $stmt = \InterfaceDB::prepare(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_start > :period_end
             ORDER BY period_start ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'period_end' => $periodEnd,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
