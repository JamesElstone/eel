<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Produces the source-backed analysis which supports the single HMRC
 * miscellaneous-expenses add-back fact. Category rows are explanatory only;
 * the aggregate remains the reportable taxonomy fact.
 */
final class DisallowableExpenseBreakdownService
{
    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public function fromTaxWorkings(array $rows, float $expectedAmount): array
    {
        $sourceRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceRows[] = [
                'nominal_code' => (string)($row['nominal_code'] ?? ''),
                'nominal_name' => (string)($row['nominal_name'] ?? ''),
                'source_date' => (string)($row['journal_date'] ?? ''),
                'source_type' => (string)($row['source'] ?? 'posted_ledger'),
                'source_label' => (string)($row['source_label'] ?? 'Posted ledger'),
                'source_reference' => $this->sourceReference($row),
                'description' => trim((string)($row['line_description'] ?? $row['journal_description'] ?? '')),
                'amount' => (float)($row['amount'] ?? 0),
            ];
        }
        return $this->build($sourceRows, $expectedAmount);
    }

    /** @param array<string,mixed> $audit @return array<string,mixed> */
    public function fromFrozenAudit(array $audit, float $expectedAmount): array
    {
        $sourceRows = [];
        foreach ((array)($audit['rows'] ?? []) as $row) {
            if (!is_array($row) || (string)($row['tax_treatment'] ?? '') !== 'disallowable') {
                continue;
            }
            $amount = round((float)($row['tax_adjustment_amount'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $metadata = (array)($row['metadata'] ?? []);
            $sourceRows[] = [
                'nominal_code' => (string)($row['nominal_code'] ?? $metadata['nominal_code'] ?? ''),
                'nominal_name' => (string)($row['nominal_name'] ?? $metadata['nominal_name'] ?? ''),
                'source_date' => (string)($row['source_date'] ?? ''),
                'source_type' => (string)($row['source_type'] ?? ''),
                'source_label' => (string)($row['source_label'] ?? ''),
                'source_reference' => $this->sourceReference($metadata, (int)($row['source_id'] ?? 0)),
                'description' => trim((string)($row['label'] ?? '')),
                'amount' => $amount,
            ];
        }
        return $this->build($sourceRows, $expectedAmount);
    }

    /** @param list<array<string,mixed>> $sourceRows @return array<string,mixed> */
    private function build(array $sourceRows, float $expectedAmount): array
    {
        $expectedAmount = round($expectedAmount, 2);
        $total = 0.0;
        $incomplete = [];
        $categories = [];
        foreach ($sourceRows as $index => $row) {
            $amount = round((float)($row['amount'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $code = trim((string)($row['nominal_code'] ?? ''));
            $name = trim((string)($row['nominal_name'] ?? ''));
            $sourceType = trim((string)($row['source_type'] ?? ''));
            if ($code === '' || $name === '' || $sourceType === '' || $sourceType === 'calculation_reconciliation') {
                $incomplete[] = $index + 1;
            }
            $row['amount'] = $amount;
            $key = $code . '|' . $name;
            if (!isset($categories[$key])) {
                $categories[$key] = ['nominal_code' => $code, 'nominal_name' => $name, 'amount' => 0.0, 'sources' => []];
            }
            $categories[$key]['amount'] = round((float)$categories[$key]['amount'] + $amount, 2);
            $categories[$key]['sources'][] = $row;
            $total = round($total + $amount, 2);
        }
        ksort($categories, SORT_NATURAL);
        foreach ($categories as &$category) {
            usort($category['sources'], static fn(array $left, array $right): int => [
                (string)($left['source_date'] ?? ''), (string)($left['source_reference'] ?? ''), (string)($left['description'] ?? ''),
            ] <=> [
                (string)($right['source_date'] ?? ''), (string)($right['source_reference'] ?? ''), (string)($right['description'] ?? ''),
            ]);
        }
        unset($category);
        $difference = round($expectedAmount - $total, 2);
        $reconciled = abs($difference) < 0.005
            && $incomplete === []
            && (abs($expectedAmount) < 0.005 || $categories !== []);
        return [
            'expected_amount' => $expectedAmount,
            'amount' => $total,
            'difference' => $difference,
            'reconciled' => $reconciled,
            'categories' => array_values($categories),
            'source_count' => count($sourceRows),
            'incomplete_source_rows' => $incomplete,
        ];
    }

    /** @param array<string,mixed> $row */
    private function sourceReference(array $row, int $fallbackSourceId = 0): string
    {
        $journalId = (int)($row['journal_id'] ?? 0);
        $lineId = (int)($row['journal_line_id'] ?? 0);
        if ($journalId > 0 && $lineId > 0) {
            return 'Journal #' . $journalId . ', line #' . $lineId;
        }
        if ($journalId > 0) {
            return 'Journal #' . $journalId;
        }
        return $fallbackSourceId > 0 ? 'Source #' . $fallbackSourceId : '';
    }
}
