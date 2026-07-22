<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class JournalSourceEvidenceService
{
    /**
     * @param list<array<string, mixed>> $journals
     * @return array<int, array{verified: bool, reason: string}>
     */
    public function verify(array $journals, int $companyId, int $accountingPeriodId): array
    {
        $results = [];
        $transactionIds = [];
        $claimReferences = [];
        $depreciationJournals = [];
        $disposalAssets = [];
        $assetRegisterJournals = [];
        $dividendJournals = [];
        $manualTaggedJournals = [];
        $journalsById = [];

        foreach ($journals as $journal) {
            $journalId = (int)($journal['id'] ?? 0);
            if ($journalId <= 0) {
                continue;
            }
            $journalsById[$journalId] = $journal;

            $sourceType = trim((string)($journal['source_type'] ?? ''));
            $sourceRef = trim((string)($journal['source_ref'] ?? ''));
            $results[$journalId] = ['verified' => false, 'reason' => 'Source evidence has not been verified.'];

            if ($sourceType === 'bank_csv' && preg_match('/^transaction:(\d+)$/', $sourceRef, $matches) === 1) {
                $transactionIds[$journalId] = (int)$matches[1];
                continue;
            }

            if ($sourceType === 'expense_register' && $sourceRef !== '') {
                $claimReferences[$journalId] = $sourceRef;
                continue;
            }

            if ($sourceType === 'asset_depreciation' && preg_match('/^asset:(\d+):depreciation:/', $sourceRef, $matches) === 1) {
                $depreciationJournals[$journalId] = (int)$matches[1];
                continue;
            }

            if ($sourceType === 'asset_disposal' && preg_match('/^asset:(\d+):disposal$/', $sourceRef, $matches) === 1) {
                $disposalAssets[$journalId] = (int)$matches[1];
                continue;
            }

            if ($sourceType === 'asset_register') {
                $assetRegisterJournals[$journalId] = $journalId;
                continue;
            }

            if (
                ($sourceType === 'dividend' || ($sourceType === 'manual' && str_starts_with($sourceRef, 'dividend:')))
                && $sourceRef !== ''
            ) {
                $dividendJournals[$journalId] = $journalId;
                continue;
            }

            if (in_array($sourceType, ['manual', 'system_generated'], true)) {
                if (preg_match('/^meta:([^:]+):(.*)$/', $sourceRef) === 1) {
                    $manualTaggedJournals[$journalId] = $sourceRef;
                } else {
                    $results[$journalId] = [
                        'verified' => false,
                        'reason' => $sourceRef === ''
                            ? 'Manual journal has no source reference.'
                            : 'Manual journal references are not independently verified unless they match journal metadata.',
                    ];
                }
                continue;
            }

            $results[$journalId] = [
                'verified' => false,
                'reason' => $sourceType === ''
                    ? 'Journal has no source type.'
                    : 'Source type "' . $sourceType . '" has no supported evidence check.',
            ];
        }

        $this->verifyTransactions($results, $transactionIds, $journalsById, $companyId, $accountingPeriodId);
        $this->verifyExpenseClaims($results, $claimReferences, $journalsById, $companyId, $accountingPeriodId);
        $this->verifyDepreciation($results, $depreciationJournals, $journalsById, $companyId);
        $this->verifyDisposals($results, $disposalAssets, $journalsById, $companyId);
        $this->verifyAssetRegisterJournals($results, $assetRegisterJournals, $journalsById, $companyId);
        $this->verifyDividends($results, $dividendJournals, $companyId, $accountingPeriodId);
        $this->verifyManualTaggedJournals(
            $results,
            $manualTaggedJournals,
            $journalsById,
            $companyId,
            $accountingPeriodId
        );

        return $results;
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyDividends(
        array &$results,
        array $journalIds,
        int $companyId,
        int $accountingPeriodId
    ): void {
        if ($journalIds === []) {
            return;
        }

        foreach ($journalIds as $journalId) {
            $results[(int)$journalId] = [
                'verified' => false,
                'reason' => 'Dividend journal is not linked to a dividend voucher.',
            ];
        }
        if (!\InterfaceDB::tableExists('dividend_vouchers')) {
            foreach ($journalIds as $journalId) {
                $results[(int)$journalId]['reason'] = 'Dividend voucher records are unavailable. Apply the dividend voucher migration.';
            }
            return;
        }

        $candidateIds = array_values(array_unique(array_filter(array_map('intval', array_keys($journalIds)))));
        $voucherRows = \InterfaceDB::fetchAll(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    journal_id,
                    transaction_id,
                    reversal_journal_id,
                    declaration_date,
                    payment_date,
                    amount,
                    voided_at,
                    void_reason
             FROM dividend_vouchers
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND (
                    journal_id IN (' . $this->placeholders($candidateIds) . ')
                    OR reversal_journal_id IN (' . $this->placeholders($candidateIds) . ')
               )',
            array_merge([$companyId, $accountingPeriodId], $candidateIds, $candidateIds)
        );
        $vouchersByJournal = [];
        $evidenceJournalIds = [];
        $transactionIds = [];
        foreach ($voucherRows as $voucher) {
            $declarationJournalId = (int)($voucher['journal_id'] ?? 0);
            $reversalJournalId = (int)($voucher['reversal_journal_id'] ?? 0);
            if ($declarationJournalId > 0) {
                $vouchersByJournal[$declarationJournalId][] = $voucher;
                $evidenceJournalIds[$declarationJournalId] = true;
            }
            if ($reversalJournalId > 0) {
                $vouchersByJournal[$reversalJournalId][] = $voucher;
                $evidenceJournalIds[$reversalJournalId] = true;
            }
            $transactionId = (int)($voucher['transaction_id'] ?? 0);
            if ($transactionId > 0) {
                $transactionIds[$transactionId] = true;
            }
        }
        if ($voucherRows === []) {
            return;
        }

        $evidenceIds = array_values(array_map('intval', array_keys($evidenceJournalIds)));
        $headerRows = \InterfaceDB::fetchAll(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    source_type,
                    COALESCE(source_ref, \'\') AS source_ref,
                    journal_date,
                    is_posted
             FROM journals
             WHERE id IN (' . $this->placeholders($evidenceIds) . ')',
            $evidenceIds
        );
        $headers = [];
        foreach ($headerRows as $header) {
            $headers[(int)($header['id'] ?? 0)] = $header;
        }
        $lineMaps = $this->journalLineMaps($evidenceIds);

        $transactions = [];
        $bankJournalCounts = [];
        $transactionIdList = array_values(array_map('intval', array_keys($transactionIds)));
        if ($transactionIdList !== []) {
            $transactionRows = \InterfaceDB::fetchAll(
                'SELECT id, txn_date, amount
                 FROM transactions
                 WHERE company_id = ?
                   AND accounting_period_id = ?
                   AND id IN (' . $this->placeholders($transactionIdList) . ')',
                array_merge([$companyId, $accountingPeriodId], $transactionIdList)
            );
            foreach ($transactionRows as $transaction) {
                $transactions[(int)($transaction['id'] ?? 0)] = $transaction;
            }
            $transactionReferences = array_map(
                static fn(int $transactionId): string => 'transaction:' . $transactionId,
                $transactionIdList
            );
            $bankRows = \InterfaceDB::fetchAll(
                'SELECT source_ref, COUNT(*) AS journal_count
                 FROM journals
                 WHERE company_id = ?
                   AND accounting_period_id = ?
                   AND is_posted = 1
                   AND source_type = ?
                   AND source_ref IN (' . $this->placeholders($transactionReferences) . ')
                 GROUP BY source_ref',
                array_merge([$companyId, $accountingPeriodId, 'bank_csv'], $transactionReferences)
            );
            foreach ($bankRows as $bankRow) {
                $bankJournalCounts[(string)($bankRow['source_ref'] ?? '')] = (int)($bankRow['journal_count'] ?? 0);
            }
        }

        foreach ($journalIds as $journalId) {
            $journalId = (int)$journalId;
            $linkedVouchers = (array)($vouchersByJournal[$journalId] ?? []);
            if (count($linkedVouchers) !== 1) {
                $results[$journalId] = [
                    'verified' => false,
                    'reason' => $linkedVouchers === []
                        ? 'Dividend journal is not linked to a dividend voucher.'
                        : 'Dividend journal is linked to more than one dividend voucher.',
                ];
                continue;
            }

            $voucher = (array)$linkedVouchers[0];
            $declarationJournalId = (int)($voucher['journal_id'] ?? 0);
            $reversalJournalId = (int)($voucher['reversal_journal_id'] ?? 0);
            $declaration = (array)($headers[$declarationJournalId] ?? []);
            $declarationEvidence = $this->verifyDividendDeclaration(
                $voucher,
                $declaration,
                (array)($lineMaps[$declarationJournalId] ?? []),
                (array)($transactions[(int)($voucher['transaction_id'] ?? 0)] ?? []),
                (int)($bankJournalCounts['transaction:' . (int)($voucher['transaction_id'] ?? 0)] ?? 0),
                $companyId,
                $accountingPeriodId
            );
            if (empty($declarationEvidence['verified'])) {
                $results[$journalId] = $declarationEvidence;
                continue;
            }

            if (trim((string)($voucher['voided_at'] ?? '')) === '') {
                $results[$journalId] = $journalId === $declarationJournalId
                    ? $declarationEvidence
                    : ['verified' => false, 'reason' => 'Dividend voucher links a reversal journal but has no void record.'];
                continue;
            }

            $voidEvidence = $this->verifyDividendVoid(
                $voucher,
                $declaration,
                (array)($headers[$reversalJournalId] ?? []),
                (array)($lineMaps[$declarationJournalId] ?? []),
                (array)($lineMaps[$reversalJournalId] ?? []),
                $companyId,
                $accountingPeriodId
            );
            $results[$journalId] = $voidEvidence;
        }
    }

    /** @return array{verified: bool, reason: string} */
    private function verifyDividendDeclaration(
        array $voucher,
        array $journal,
        array $lineMap,
        array $transaction,
        int $bankJournalCount,
        int $companyId,
        int $accountingPeriodId
    ): array {
        $journalId = (int)($journal['id'] ?? 0);
        $voucherJournalId = (int)($voucher['journal_id'] ?? 0);
        $sourceRef = trim((string)($journal['source_ref'] ?? ''));
        $voucherAmount = abs(round((float)($voucher['amount'] ?? 0), 2));
        $totals = $this->journalLineTotals($lineMap);
        $baseValid = $journalId > 0
            && $journalId === $voucherJournalId
            && (int)($journal['company_id'] ?? 0) === $companyId
            && (int)($journal['accounting_period_id'] ?? 0) === $accountingPeriodId
            && (int)($journal['is_posted'] ?? 0) === 1
            && in_array((string)($journal['source_type'] ?? ''), ['dividend', 'manual'], true)
            && str_starts_with($sourceRef, 'dividend:')
            && !str_starts_with($sourceRef, 'dividend:void:')
            && trim((string)($journal['journal_date'] ?? '')) === trim((string)($voucher['declaration_date'] ?? ''))
            && $voucherAmount > 0
            && $this->sameMoney((float)$totals['debit'], $voucherAmount)
            && $this->sameMoney((float)$totals['credit'], $voucherAmount);
        if (!$baseValid) {
            return [
                'verified' => false,
                'reason' => match (true) {
                    $journalId <= 0 || $journalId !== $voucherJournalId => 'Dividend voucher does not link to the declaration journal.',
                    (int)($journal['company_id'] ?? 0) !== $companyId || (int)($journal['accounting_period_id'] ?? 0) !== $accountingPeriodId => 'Dividend voucher company or accounting period does not match the declaration journal.',
                    (int)($journal['is_posted'] ?? 0) !== 1 => 'Dividend declaration journal is not posted.',
                    !in_array((string)($journal['source_type'] ?? ''), ['dividend', 'manual'], true) || !str_starts_with($sourceRef, 'dividend:') || str_starts_with($sourceRef, 'dividend:void:') => 'Dividend declaration source reference is invalid.',
                    trim((string)($journal['journal_date'] ?? '')) !== trim((string)($voucher['declaration_date'] ?? '')) => 'Dividend voucher declaration date does not match the journal date.',
                    default => 'Dividend voucher amount does not reconcile to the declaration journal.',
                },
            ];
        }

        if (preg_match('/^dividend:transaction:(\d+)$/', $sourceRef, $matches) !== 1) {
            return [
                'verified' => true,
                'reason' => 'Dividend voucher, declaration date and journal amount reconcile.',
            ];
        }

        $transactionId = (int)$matches[1];
        $voucherTransactionId = (int)($voucher['transaction_id'] ?? 0);
        $transactionAmount = abs(round((float)($transaction['amount'] ?? 0), 2));
        $transactionValid = $transactionId > 0
            && $voucherTransactionId === $transactionId
            && $transaction !== []
            && trim((string)($transaction['txn_date'] ?? '')) === trim((string)($journal['journal_date'] ?? ''))
            && $this->sameMoney($transactionAmount, $voucherAmount)
            && $bankJournalCount === 1;

        return [
            'verified' => $transactionValid,
            'reason' => match (true) {
                $transactionId <= 0 || $voucherTransactionId !== $transactionId => 'Dividend source reference does not match the voucher payment transaction.',
                $transaction === [] => 'Dividend payment transaction does not exist in the selected company and period.',
                trim((string)($transaction['txn_date'] ?? '')) !== trim((string)($journal['journal_date'] ?? '')) => 'Dividend payment transaction date does not match the declaration journal date.',
                !$this->sameMoney($transactionAmount, $voucherAmount) => 'Dividend payment transaction amount does not match the voucher amount.',
                default => $bankJournalCount === 1
                    ? 'Dividend voucher and linked payment transaction reconcile to the declaration journal.'
                    : 'Dividend payment transaction does not have exactly one posted bank journal.',
            },
        ];
    }

    /** @return array{verified: bool, reason: string} */
    private function verifyDividendVoid(
        array $voucher,
        array $declaration,
        array $reversal,
        array $declarationLines,
        array $reversalLines,
        int $companyId,
        int $accountingPeriodId
    ): array {
        $declarationJournalId = (int)($voucher['journal_id'] ?? 0);
        $reversalJournalId = (int)($voucher['reversal_journal_id'] ?? 0);
        $voucherAmount = abs(round((float)($voucher['amount'] ?? 0), 2));
        $reversalTotals = $this->journalLineTotals($reversalLines);
        $valid = $reversalJournalId > 0
            && trim((string)($voucher['voided_at'] ?? '')) !== ''
            && trim((string)($voucher['void_reason'] ?? '')) !== ''
            && (int)($reversal['id'] ?? 0) === $reversalJournalId
            && (int)($reversal['company_id'] ?? 0) === $companyId
            && (int)($reversal['accounting_period_id'] ?? 0) === $accountingPeriodId
            && (int)($reversal['is_posted'] ?? 0) === 1
            && in_array((string)($reversal['source_type'] ?? ''), ['dividend', 'manual'], true)
            && (string)($reversal['source_ref'] ?? '') === 'dividend:void:' . $declarationJournalId
            && (string)($reversal['journal_date'] ?? '') === (string)($declaration['journal_date'] ?? '')
            && $this->sameMoney((float)$reversalTotals['debit'], $voucherAmount)
            && $this->sameMoney((float)$reversalTotals['credit'], $voucherAmount)
            && $this->lineMapsAreExactReversals($declarationLines, $reversalLines);

        return [
            'verified' => $valid,
            'reason' => match (true) {
                $reversalJournalId <= 0 => 'Voided dividend voucher has no reversal journal.',
                trim((string)($voucher['voided_at'] ?? '')) === '' || trim((string)($voucher['void_reason'] ?? '')) === '' => 'Dividend reversal is missing a recorded void timestamp or reason.',
                (int)($reversal['id'] ?? 0) !== $reversalJournalId => 'Dividend voucher reversal journal could not be found.',
                (int)($reversal['company_id'] ?? 0) !== $companyId || (int)($reversal['accounting_period_id'] ?? 0) !== $accountingPeriodId => 'Dividend reversal journal company or accounting period does not match the voucher.',
                (int)($reversal['is_posted'] ?? 0) !== 1 => 'Dividend reversal journal is not posted.',
                !in_array((string)($reversal['source_type'] ?? ''), ['dividend', 'manual'], true) || (string)($reversal['source_ref'] ?? '') !== 'dividend:void:' . $declarationJournalId => 'Dividend reversal source reference is invalid.',
                (string)($reversal['journal_date'] ?? '') !== (string)($declaration['journal_date'] ?? '') => 'Dividend reversal date does not match the declaration journal date.',
                !$this->sameMoney((float)$reversalTotals['debit'], $voucherAmount) || !$this->sameMoney((float)$reversalTotals['credit'], $voucherAmount) => 'Dividend reversal amount does not reconcile to the voucher.',
                default => 'Dividend reversal lines are not an exact debit and credit inversion of the declaration journal.',
            },
        ];
    }

    /** @return array<int, array<string, array{debit: float, credit: float}>> */
    private function journalLineMaps(array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }

        $maps = [];
        $rows = \InterfaceDB::fetchAll(
            'SELECT journal_id,
                    nominal_account_id,
                    COALESCE(director_id, 0) AS director_id,
                    COALESCE(company_account_id, 0) AS company_account_id,
                    debit,
                    credit
             FROM journal_lines
             WHERE journal_id IN (' . $this->placeholders($journalIds) . ')',
            $journalIds
        );
        foreach ($rows as $row) {
            $journalId = (int)($row['journal_id'] ?? 0);
            $key = implode(':', [
                (int)($row['nominal_account_id'] ?? 0),
                (int)($row['director_id'] ?? 0),
                (int)($row['company_account_id'] ?? 0),
            ]);
            $maps[$journalId][$key] = [
                'debit' => round((float)(($maps[$journalId][$key]['debit'] ?? 0.0) + (float)($row['debit'] ?? 0)), 2),
                'credit' => round((float)(($maps[$journalId][$key]['credit'] ?? 0.0) + (float)($row['credit'] ?? 0)), 2),
            ];
        }

        return $maps;
    }

    /** @return array{debit: float, credit: float} */
    private function journalLineTotals(array $lineMap): array
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($lineMap as $line) {
            $debit += (float)($line['debit'] ?? 0.0);
            $credit += (float)($line['credit'] ?? 0.0);
        }

        return ['debit' => round($debit, 2), 'credit' => round($credit, 2)];
    }

    private function lineMapsAreExactReversals(array $declarationLines, array $reversalLines): bool
    {
        if ($declarationLines === [] || count($declarationLines) !== count($reversalLines)) {
            return false;
        }

        foreach ($declarationLines as $key => $line) {
            if (!array_key_exists($key, $reversalLines)) {
                return false;
            }
            $reversalLine = (array)($reversalLines[$key] ?? []);
            if (
                !$this->sameMoney((float)($line['debit'] ?? 0.0), (float)($reversalLine['credit'] ?? 0.0))
                || !$this->sameMoney((float)($line['credit'] ?? 0.0), (float)($reversalLine['debit'] ?? 0.0))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyTransactions(
        array &$results,
        array $journalTransactionIds,
        array $journalsById,
        int $companyId,
        int $accountingPeriodId
    ): void {
        if ($journalTransactionIds === []) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $journalTransactionIds))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT id, txn_date, amount
             FROM transactions
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND id IN (' . $this->placeholders($ids) . ')',
            array_merge([$companyId, $accountingPeriodId], $ids)
        );
        $transactions = [];
        foreach ($rows as $row) {
            $transactions[(int)($row['id'] ?? 0)] = $row;
        }
        $sourceReferences = array_values(array_unique(array_map(
            static fn(int $transactionId): string => 'transaction:' . $transactionId,
            $ids
        )));
        $journalCounts = [];
        if ($sourceReferences !== []) {
            $countRows = \InterfaceDB::fetchAll(
                'SELECT source_ref, COUNT(*) AS journal_count
                 FROM journals
                 WHERE company_id = ?
                   AND accounting_period_id = ?
                   AND is_posted = 1
                   AND source_type = ?
                   AND source_ref IN (' . $this->placeholders($sourceReferences) . ')
                 GROUP BY source_ref',
                array_merge([$companyId, $accountingPeriodId, 'bank_csv'], $sourceReferences)
            );
            foreach ($countRows as $row) {
                $journalCounts[(string)($row['source_ref'] ?? '')] = (int)($row['journal_count'] ?? 0);
            }
        }

        foreach ($journalTransactionIds as $journalId => $transactionId) {
            $transaction = (array)($transactions[(int)$transactionId] ?? []);
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $expected = abs(round((float)($transaction['amount'] ?? 0), 2));
            $exists = $transaction !== [];
            $sourceReference = 'transaction:' . (int)$transactionId;
            $uniquePosting = (int)($journalCounts[$sourceReference] ?? 0) === 1;
            $totalsMatch = $exists
                && $this->sameMoney((float)($journal['debit_total'] ?? 0), $expected)
                && $this->sameMoney((float)($journal['credit_total'] ?? 0), $expected);
            $dateMatch = $exists
                && trim((string)($journal['journal_date'] ?? '')) !== ''
                && (string)$journal['journal_date'] === (string)($transaction['txn_date'] ?? '');
            $verified = $exists && $uniquePosting && $totalsMatch && $dateMatch;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    $verified => 'Linked transaction date and amount reconcile to the journal.',
                    !$exists => 'Linked transaction ' . (int)$transactionId . ' does not exist in the selected company and period.',
                    !$uniquePosting => 'The linked transaction has more than one posted bank journal and cannot be independently reconciled.',
                    !$dateMatch => 'Linked transaction date does not match the journal date.',
                    default => 'Linked transaction amount does not reconcile to the journal totals.',
                },
            ];
        }
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyExpenseClaims(
        array &$results,
        array $journalClaimReferences,
        array $journalsById,
        int $companyId,
        int $accountingPeriodId
    ): void {
        if ($journalClaimReferences === []) {
            return;
        }

        $references = array_values(array_unique(array_filter(array_map('strval', $journalClaimReferences))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT claim_reference_code, posted_journal_id, period_end, claimed_amount
             FROM expense_claims
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND claim_reference_code IN (' . $this->placeholders($references) . ')',
            array_merge([$companyId, $accountingPeriodId], $references)
        );
        $claims = [];
        foreach ($rows as $row) {
            $claims[(string)($row['claim_reference_code'] ?? '')] = $row;
        }

        foreach ($journalClaimReferences as $journalId => $reference) {
            $claim = (array)($claims[(string)$reference] ?? []);
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $expected = abs(round((float)($claim['claimed_amount'] ?? 0), 2));
            $exists = $claim !== [];
            $journalLinkMatches = $exists && (int)($claim['posted_journal_id'] ?? 0) === (int)$journalId;
            $totalsMatch = $exists
                && $this->sameMoney((float)($journal['debit_total'] ?? 0), $expected)
                && $this->sameMoney((float)($journal['credit_total'] ?? 0), $expected);
            $dateMatch = $exists
                && trim((string)($journal['journal_date'] ?? '')) !== ''
                && (string)$journal['journal_date'] === (string)($claim['period_end'] ?? '');
            $verified = $exists && $journalLinkMatches && $totalsMatch && $dateMatch;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    $verified => 'Linked expense claim, posting date and claimed amount reconcile to the journal.',
                    !$exists => 'Expense claim reference "' . (string)$reference . '" was not found in the selected company and period.',
                    !$journalLinkMatches => 'Expense claim reference "' . (string)$reference . '" does not link to this posted journal.',
                    !$dateMatch => 'Expense claim period end does not match the journal date.',
                    default => 'Expense claim amount does not reconcile to the journal totals.',
                },
            ];
        }
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyManualTaggedJournals(
        array &$results,
        array $journalSourceRefs,
        array $journalsById,
        int $companyId,
        int $accountingPeriodId
    ): void {
        if ($journalSourceRefs === [] || !\InterfaceDB::tableExists('journal_entry_metadata')) {
            return;
        }

        $journalIds = array_values(array_unique(array_map('intval', array_keys($journalSourceRefs))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT journal_id, accounting_period_id, journal_tag, journal_key, entry_mode
             FROM journal_entry_metadata
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND journal_id IN (' . $this->placeholders($journalIds) . ')',
            array_merge([$companyId, $accountingPeriodId], $journalIds)
        );
        $metadataRefs = [];
        $metadataModes = [];
        foreach ($rows as $row) {
            $tag = (string)($row['journal_tag'] ?? '');
            $key = (string)($row['journal_key'] ?? '');
            $periodId = (int)($row['accounting_period_id'] ?? 0);
            $metadataRefs[(int)($row['journal_id'] ?? 0)] = [
                'meta:' . $tag . ':' . $key,
                'meta:' . $tag . ':' . $periodId . ':' . $key,
            ];
            $metadataModes[(int)($row['journal_id'] ?? 0)] = trim((string)($row['entry_mode'] ?? ''));
        }

        foreach ($journalSourceRefs as $journalId => $sourceRef) {
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $balanced = $this->sameMoney(
                (float)($journal['debit_total'] ?? 0),
                (float)($journal['credit_total'] ?? 0)
            );
            $metadataMatches = in_array(
                (string)$sourceRef,
                (array)($metadataRefs[(int)$journalId] ?? []),
                true
            );
            $entryMode = (string)($metadataModes[(int)$journalId] ?? '');
            $tag = '';
            if (preg_match('/^meta:([^:]+):/', (string)$sourceRef, $matches) === 1) {
                $tag = (string)$matches[1];
            }
            $systemGeneratedVerification = $metadataMatches && $entryMode === 'system_generated'
                ? $this->verifySystemGeneratedJournal($tag, $journal, $companyId, $accountingPeriodId)
                : null;
            if (is_array($systemGeneratedVerification)) {
                $results[(int)$journalId] = [
                    'verified' => !empty($systemGeneratedVerification['success']),
                    'reason' => !empty($systemGeneratedVerification['success'])
                        ? (string)$systemGeneratedVerification['reason']
                        : (string)($systemGeneratedVerification['errors'][0] ?? 'System-generated journal evidence could not be verified.'),
                ];
                continue;
            }
            $verified = false;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    !$metadataMatches => 'Manual journal source reference does not match journal metadata.',
                    !$balanced => 'Manual journal source is not balanced.',
                    default => 'Journal metadata matches, but the tagged journal accounting has no independent content verifier.',
                },
            ];
        }
    }

    /** @return array{success: bool, reason?: string, errors?: list<string>}|null */
    private function verifySystemGeneratedJournal(
        string $tag,
        array $journal,
        int $companyId,
        int $accountingPeriodId
    ): ?array {
        if ($tag === 'director_loan_offset') {
            $result = (new DirectorLoanReconciliationService())->verifyJournalEvidence(
                $companyId,
                $accountingPeriodId,
                (int)($journal['id'] ?? 0)
            );

            return [
                'success' => !empty($result['success']),
                'reason' => 'Verified by the Director Loan year-end calculation and current period-end review.',
                'errors' => (array)($result['errors'] ?? []),
            ];
        }

        if (in_array($tag, ['prepayment_deferral', 'prepayment_release', 'prepayment_correction'], true)) {
            $result = (new PrepaymentPostingService())->verifyJournalEvidence(
                $companyId,
                $accountingPeriodId,
                (int)($journal['id'] ?? 0)
            );

            return [
                'success' => !empty($result['success']),
                'reason' => 'Verified by the automated prepayment schedule and posting integrity checks.',
                'errors' => (array)($result['errors'] ?? []),
            ];
        }

        return null;
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyDepreciation(
        array &$results,
        array $journalAssetIds,
        array $journalsById,
        int $companyId
    ): void
    {
        if ($journalAssetIds === []) {
            return;
        }

        $journalIds = array_values(array_unique(array_map('intval', array_keys($journalAssetIds))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT ade.journal_id,
                    ade.asset_id,
                    MAX(ade.period_end) AS journal_date,
                    SUM(ade.amount) AS expected_amount
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = ?
               AND ade.journal_id IN (' . $this->placeholders($journalIds) . ')
             GROUP BY ade.journal_id, ade.asset_id',
            array_merge([$companyId], $journalIds)
        );
        $linked = [];
        foreach ($rows as $row) {
            $linked[(int)($row['journal_id'] ?? 0)] = $row;
        }

        foreach ($journalAssetIds as $journalId => $assetId) {
            $entry = (array)($linked[(int)$journalId] ?? []);
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $expectedAmount = round((float)($entry['expected_amount'] ?? 0), 2);
            $linkMatches = (int)($entry['asset_id'] ?? 0) === (int)$assetId;
            $dateMatches = $linkMatches
                && (string)($journal['journal_date'] ?? '') === (string)($entry['journal_date'] ?? '');
            $totalsMatch = $linkMatches
                && $expectedAmount > 0
                && $this->sameMoney((float)($journal['debit_total'] ?? 0), $expectedAmount)
                && $this->sameMoney((float)($journal['credit_total'] ?? 0), $expectedAmount);
            $verified = $linkMatches && $dateMatches && $totalsMatch;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    $verified => 'Linked depreciation entries, posting date and amount reconcile to the journal.',
                    !$linkMatches => 'No matching asset depreciation entry exists for this journal and asset.',
                    !$dateMatches => 'Asset depreciation period end does not match the journal date.',
                    default => 'Asset depreciation amount does not reconcile to the journal totals.',
                },
            ];
        }
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyDisposals(
        array &$results,
        array $journalAssetIds,
        array $journalsById,
        int $companyId
    ): void
    {
        if ($journalAssetIds === []) {
            return;
        }

        $assetIds = array_values(array_unique(array_filter(array_map('intval', $journalAssetIds))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT ar.id,
                    COALESCE(ar.status, \'\') AS status,
                    COALESCE(ar.disposal_date, \'\') AS disposal_date,
                    ar.cost,
                    COALESCE(ar.disposal_proceeds, 0) AS disposal_proceeds,
                    COALESCE(SUM(ade.amount), 0) AS accumulated_depreciation
             FROM asset_register ar
             LEFT JOIN asset_depreciation_entries ade
               ON ade.asset_id = ar.id
              AND ade.period_end <= ar.disposal_date
             WHERE ar.company_id = ?
               AND ar.id IN (' . $this->placeholders($assetIds) . ')
             GROUP BY ar.id, ar.status, ar.disposal_date, ar.cost, ar.disposal_proceeds',
            array_merge([$companyId], $assetIds)
        );
        $disposed = [];
        foreach ($rows as $row) {
            $assetId = (int)($row['id'] ?? 0);
            $disposed[$assetId] = $row;
        }

        foreach ($journalAssetIds as $journalId => $assetId) {
            $asset = (array)($disposed[(int)$assetId] ?? []);
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $stateMatches = $asset !== []
                && (string)($asset['status'] ?? '') === 'disposed'
                && trim((string)($asset['disposal_date'] ?? '')) !== '';
            $dateMatches = $stateMatches
                && (string)($journal['journal_date'] ?? '') === (string)($asset['disposal_date'] ?? '');
            $cost = round((float)($asset['cost'] ?? 0), 2);
            $proceeds = round((float)($asset['disposal_proceeds'] ?? 0), 2);
            $accumulatedDepreciation = round((float)($asset['accumulated_depreciation'] ?? 0), 2);
            $expectedTotal = round(max($cost, $proceeds + $accumulatedDepreciation), 2);
            $totalsMatch = $stateMatches
                && $expectedTotal > 0
                && $this->sameMoney((float)($journal['debit_total'] ?? 0), $expectedTotal)
                && $this->sameMoney((float)($journal['credit_total'] ?? 0), $expectedTotal);
            $verified = $stateMatches && $dateMatches && $totalsMatch;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    $verified => 'Linked asset disposal state, date and accounting totals reconcile to the journal.',
                    !$stateMatches => 'Linked asset does not have a recorded disposal state and date.',
                    !$dateMatches => 'Asset disposal date does not match the journal date.',
                    default => 'Asset disposal accounting does not reconcile to the journal totals.',
                },
            ];
        }
    }

    /** @param array<int, array{verified: bool, reason: string}> $results */
    private function verifyAssetRegisterJournals(
        array &$results,
        array $journalIds,
        array $journalsById,
        int $companyId
    ): void
    {
        if ($journalIds === []) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        $rows = \InterfaceDB::fetchAll(
            'SELECT linked_journal_id, purchase_date, cost
             FROM asset_register
             WHERE company_id = ?
               AND linked_journal_id IN (' . $this->placeholders($ids) . ')',
            array_merge([$companyId], $ids)
        );
        $assetsByJournal = [];
        foreach ($rows as $row) {
            $assetsByJournal[(int)($row['linked_journal_id'] ?? 0)] = $row;
        }

        foreach ($journalIds as $journalId) {
            $asset = (array)($assetsByJournal[(int)$journalId] ?? []);
            $journal = (array)($journalsById[(int)$journalId] ?? []);
            $exists = $asset !== [];
            $dateMatches = $exists
                && (string)($journal['journal_date'] ?? '') === (string)($asset['purchase_date'] ?? '');
            $expectedAmount = round((float)($asset['cost'] ?? 0), 2);
            $totalsMatch = $exists
                && $expectedAmount > 0
                && $this->sameMoney((float)($journal['debit_total'] ?? 0), $expectedAmount)
                && $this->sameMoney((float)($journal['credit_total'] ?? 0), $expectedAmount);
            $verified = $exists && $dateMatches && $totalsMatch;
            $results[(int)$journalId] = [
                'verified' => $verified,
                'reason' => match (true) {
                    $verified => 'Linked asset purchase date and cost reconcile to the journal.',
                    !$exists => 'No asset register entry links to this journal.',
                    !$dateMatches => 'Linked asset purchase date does not match the journal date.',
                    default => 'Linked asset cost does not reconcile to the journal totals.',
                },
            ];
        }
    }

    /** @return array<int, true> */
    private function integerSet(string $sql, array $params, string $column): array
    {
        $set = [];
        foreach (\InterfaceDB::fetchAll($sql, $params) as $row) {
            $value = (int)($row[$column] ?? 0);
            if ($value > 0) {
                $set[$value] = true;
            }
        }
        return $set;
    }

    /** @return array<string, true> */
    private function stringSet(string $sql, array $params, string $column): array
    {
        $set = [];
        foreach (\InterfaceDB::fetchAll($sql, $params) as $row) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') {
                $set[$value] = true;
            }
        }
        return $set;
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function sameMoney(float $left, float $right): bool
    {
        return abs(round($left - $right, 2)) < 0.005;
    }
}
