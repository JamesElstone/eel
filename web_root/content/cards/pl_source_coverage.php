<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_source_coverageCard extends CardBaseFramework
{
    public function key(): string { return 'pl_source_coverage'; }

    public function title(): string { return 'Source Coverage'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $sources = (array)($context['profit_loss']['source_coverage'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        if ($sources === []) {
            return '<div class="helper">No source coverage data is available.</div>';
        }
        $html = '';
        $coverageSummary = (array)($sources['coverage_summary'] ?? []);
        foreach ($sources as $source) {
            if (!is_array($source) || !empty($source['is_summary'])) {
                continue;
            }
            $present = !empty($source['present']);
            $unverified = (int)($source['unverified_journal_count'] ?? 0);
            $statusClass = !$present ? 'info' : ($unverified > 0 ? 'warning' : 'success');
            $statusLabel = !$present ? 'None' : ($unverified > 0 ? 'Review' : 'Verified');
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($source['label'] ?? $source['source_type'] ?? '')) . '</td>
                <td><span class="badge ' . $statusClass . '">' . $statusLabel . '</span></td>
                <td>' . (int)($source['journal_count'] ?? 0) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $source['debit_total'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $source['credit_total'] ?? 0)) . '</td>
            </tr>';
        }
        $summary = (array)($context['profit_loss']['summary'] ?? []);
        $depreciation = (float)($summary['depreciation_expense'] ?? 0);
        $prepayments = (float)($summary['prepayment_expense_adjustment'] ?? 0);
        $previewParts = [];
        if (abs($depreciation) >= 0.005) {
            $previewParts[] = HelperFramework::escape($this->money($companySettings, $depreciation)) . ' of depreciation';
        }
        if (abs($prepayments) >= 0.005) {
            $previewParts[] = HelperFramework::escape($this->money($companySettings, $prepayments)) . ' of prepayment adjustment';
        }
        $previewNote = $previewParts !== []
            ? '<div class="helper"><span class="badge info">Close preview</span> Journal coverage below is posted-source evidence only. The P&amp;L cards also include ' . implode(' and ', $previewParts) . ' from the Year End close preview.</div>'
            : '';
        $reconciled = !empty($coverageSummary['reconciled']);
        $coverageNote = '<div class="helper"><span class="badge ' . ($reconciled ? 'success' : 'warning') . '">' . ($reconciled ? 'Reconciled' : 'Review') . '</span> Covered '
            . (int)($coverageSummary['covered_journal_count'] ?? 0) . ' of '
            . (int)($coverageSummary['posted_journal_count'] ?? 0) . ' posted journals; '
            . (int)($coverageSummary['uncovered_journal_count'] ?? 0) . ' uncovered.</div>';
        $failureRows = $this->evidenceFailureRows($coverageSummary);
        $failureHtml = '';
        if ($failureRows !== []) {
            $failureHtml = '<div class="panel-soft"><div class="eyebrow">Unverified journal evidence</div>'
                . $this->evidenceFailureTable($failureRows)->render($context, [
                    'cards[]' => (array)($context['page']['page_cards'] ?? []),
                ])
                . '</div>';
        }

        return '<div class="settings-stack">' . $previewNote . $coverageNote . $failureHtml . '<div class="table-scroll"><table><thead><tr><th>Source</th><th>Status</th><th>Journals</th><th>Debits</th><th>Credits</th></tr></thead><tbody>' . $html . '</tbody></table></div></div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    /** @return list<array{journal_id: int, source_type: string, source_ref: string, reason: string}> */
    private function evidenceFailureRows(array $coverageSummary): array
    {
        $rows = [];
        foreach ((array)($coverageSummary['evidence_failures'] ?? []) as $failure) {
            if (!is_array($failure)) {
                continue;
            }

            $rows[] = [
                'journal_id' => (int)($failure['journal_id'] ?? 0),
                'source_type' => (string)($failure['source_type'] ?? 'unknown'),
                'source_ref' => (string)($failure['source_ref'] ?? 'no reference'),
                'reason' => (string)($failure['reason'] ?? 'Source evidence could not be verified.'),
            ];
        }

        return $rows;
    }

    private function evidenceFailureTable(array $rows): TableFramework
    {
        return TableFramework::make('pl_source_coverage_evidence', $rows)
            ->filename('unverified-journal-evidence')
            ->exports(true)
            ->exportLimit(max(1, count($rows)))
            ->empty('No unverified journal evidence remains.')
            ->column(
                'journal_id',
                'Journal',
                html: static fn(array $row): string => self::journalLink((int)($row['journal_id'] ?? 0)),
                export: static fn(array $row): string => (string)(int)($row['journal_id'] ?? 0),
                exportType: 'number'
            )
            ->column(
                'source_type',
                'Source',
                html: static fn(array $row): string => self::evidenceSourceLabel((string)($row['source_type'] ?? '')),
                export: static fn(array $row): string => self::evidenceSourceLabel((string)($row['source_type'] ?? ''))
            )
            ->textColumn('source_ref', 'Reference')
            ->textColumn('reason', 'Reason');
    }

    private static function journalLink(int $journalId): string
    {
        if ($journalId <= 0) {
            return '';
        }

        $url = '?page=journal&show_card=journals_list&journals_list_keyword=' . rawurlencode((string)$journalId);

        return '<a class="button button-inline" href="' . HelperFramework::escape($url) . '">'
            . HelperFramework::escape('#' . $journalId)
            . '</a>';
    }

    private static function evidenceSourceLabel(string $sourceType): string
    {
        return match (trim($sourceType)) {
            'director_loan_offset' => 'System-generated Director Loan journal',
            'dividend' => 'Dividend declaration / void',
            '' => 'Unknown journal source',
            default => ucwords(str_replace('_', ' ', trim($sourceType))),
        };
    }
}
