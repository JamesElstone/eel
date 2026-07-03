<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompanyMinutesService
{
    public function listMinutes(int $companyId, int $accountingPeriodId, int $limit = 500): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->minutesTablesAvailable()) {
            return [];
        }

        $limit = max(1, min($limit, 1000));
        $stmt = \InterfaceDB::prepare(
            "SELECT dv.id,
                    dv.company_name,
                    dv.director_name,
                    dv.declaration_date,
                    dv.amount,
                    dv.minutes_text,
                    dv.voided_at,
                    dv.void_reason,
                    dv.reversal_journal_id
             FROM dividend_vouchers dv
             INNER JOIN accounting_periods ap
                ON ap.id = dv.accounting_period_id
               AND ap.company_id = dv.company_id
             WHERE dv.company_id = :company_id
               AND dv.accounting_period_id = :accounting_period_id
               AND dv.declaration_date BETWEEN ap.period_start AND ap.period_end
               AND TRIM(COALESCE(dv.minutes_text, '')) <> ''
             ORDER BY dv.declaration_date DESC, dv.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $rows = [];
        foreach (($stmt->fetchAll() ?: []) as $voucher) {
            if (!is_array($voucher)) {
                continue;
            }

            $sourceId = (int)($voucher['id'] ?? 0);
            $declarationDate = (string)($voucher['declaration_date'] ?? '');
            $rows[] = [
                'date' => $declarationDate,
                'minutes' => $this->originalMinutesText((string)($voucher['minutes_text'] ?? '')),
                'source_type' => 'dividend_voucher',
                'source_id' => $sourceId,
            ];

            $voidedAt = trim((string)($voucher['voided_at'] ?? ''));
            if ($voidedAt !== '') {
                $rows[] = [
                    'date' => substr($voidedAt, 0, 10),
                    'minutes' => $this->voidMinutesText($voucher),
                    'source_type' => 'dividend_voucher_void',
                    'source_id' => $sourceId,
                ];
            }
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $dateCompare = strcmp((string)($right['date'] ?? ''), (string)($left['date'] ?? ''));
                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return ((int)($right['source_id'] ?? 0)) <=> ((int)($left['source_id'] ?? 0));
            }
        );

        return array_slice($rows, 0, $limit);
    }

    private function minutesTablesAvailable(): bool
    {
        return \InterfaceDB::tableExists('dividend_vouchers')
            && \InterfaceDB::tableExists('accounting_periods');
    }

    private function originalMinutesText(string $minutesText): string
    {
        $marker = "\n\n" . 'Subsequent record: This dividend voucher was voided';
        $position = strpos($minutesText, $marker);
        if ($position === false) {
            return trim($minutesText);
        }

        return trim(substr($minutesText, 0, $position));
    }

    private function voidMinutesText(array $voucher): string
    {
        $companyName = trim((string)($voucher['company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = 'the company';
        }

        $directorName = trim((string)($voucher['director_name'] ?? ''));
        if ($directorName === '') {
            $directorName = 'The director';
        }

        $voidedAt = trim((string)($voucher['voided_at'] ?? ''));
        $voidDate = $voidedAt !== '' ? substr($voidedAt, 0, 10) : '';
        $declarationDate = trim((string)($voucher['declaration_date'] ?? ''));
        $amount = number_format((float)($voucher['amount'] ?? 0), 2, '.', '');
        $reason = trim((string)($voucher['void_reason'] ?? ''));
        $reversalJournalId = (int)($voucher['reversal_journal_id'] ?? 0);

        return trim('Minutes of a meeting of the sole director of ' . $companyName . "\n"
            . 'Date: ' . $voidDate . "\n\n"
            . $directorName . ' reviewed the dividend declaration minutes dated ' . $declarationDate
            . ' and the related dividend voucher for ' . $amount . '. '
            . 'It was resolved that the dividend declaration and voucher recorded on ' . $declarationDate
            . ' be voided.'
            . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
            . ($reversalJournalId > 0 ? ' Reversal journal: ' . $reversalJournalId . '.' : ''));
    }
}
