<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_prepayment_treatmentCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'tax_prepayment_treatment';
    }

    public function title(): string
    {
        return 'Prepayment Accounting Treatment';
    }

    public function helper(array $context): string
    {
        return 'This is an accounting-period view, even when a Corporation Tax period is selected above. Prepayment journals change the ordinary P&L and balance sheet; this card does not create a separate tax adjustment.';
    }

    public function services(): array
    {
        return [[
            'key' => 'prepayment_period_context',
            'service' => \eel_accounts\Service\PrepaymentScheduleService::class,
            'method' => 'fetchPeriodContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['prepayments.state', 'year.end.state', 'tax.workings'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $periodContext = (array)($context['services']['prepayment_period_context'] ?? []);
        if (empty($periodContext['available'])) {
            return $this->messages((array)($periodContext['errors'] ?? ['Prepayment schedules are not available.']))
                . $this->guidanceLinks();
        }

        $settings = (array)($context['company']['settings'] ?? ($context['page']['settings'] ?? []));
        $schedules = (array)($periodContext['schedules'] ?? []);
        if ($schedules === []) {
            return '<div class="helper">No prepayment schedule overlaps the selected accounting period.</div>'
                . $this->accountingDisclosure()
                . $this->guidanceLinks();
        }

        $rows = '';
        $warnings = (array)($periodContext['errors'] ?? []);
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $amountPence = (int)($schedule['source_amount_pence'] ?? 0);
            $expensePence = (int)($allocation['expense_pence'] ?? 0);
            $closingPence = (int)($allocation['closing_deferred_pence'] ?? 0);
            $recognisedThroughPence = (int)($allocation['recognised_through_pence'] ?? ($amountPence - $closingPence));
            $recognisedBeforePence = $recognisedThroughPence - $expensePence;
            $overlapDays = (int)($allocation['overlap_days'] ?? 0);
            $totalDays = (int)($schedule['total_days'] ?? 0);
            $sourceLabel = trim((string)($schedule['source_description'] ?? ''));
            if ($sourceLabel === '') {
                $sourceLabel = HelperFramework::labelFromKey((string)($schedule['source_type'] ?? 'source'), '_')
                    . ' #' . (int)($schedule['source_id'] ?? 0);
            }

            $journalState = (string)($allocation['journal_state'] ?? $schedule['journal_state'] ?? 'not_posted');
            $rows .= '<tr>'
                . '<td><strong>' . HelperFramework::escape($sourceLabel) . '</strong><br><span class="helper">'
                . HelperFramework::escape((string)($schedule['source_date'] ?? '')) . ' · '
                . HelperFramework::escape(trim((string)($schedule['expense_nominal_code'] ?? '') . ' ' . (string)($schedule['expense_nominal_name'] ?? '')))
                . '</span></td>'
                . '<td class="numeric">' . HelperFramework::escape($this->moneyPence($settings, $amountPence)) . '</td>'
                . '<td>' . HelperFramework::escape((string)($schedule['service_start_date'] ?? '')) . ' to '
                . HelperFramework::escape((string)($schedule['service_end_date'] ?? ''))
                . '<br><span class="helper">' . $totalDays . ' inclusive days</span></td>'
                . '<td>' . HelperFramework::escape($this->moneyPence($settings, $recognisedThroughPence)) . ' cumulative − '
                . HelperFramework::escape($this->moneyPence($settings, $recognisedBeforePence)) . ' before = '
                . HelperFramework::escape($this->moneyPence($settings, $expensePence))
                . '<br><span class="helper">' . $overlapDays . ' of ' . $totalDays . ' inclusive days; cumulative half-up rounding. '
                . HelperFramework::escape((string)($allocation['overlap_start'] ?? '')) . ' to '
                . HelperFramework::escape((string)($allocation['overlap_end'] ?? '')) . '</span></td>'
                . '<td class="numeric">' . HelperFramework::escape($this->moneyPence($settings, $expensePence)) . '</td>'
                . '<td class="numeric">' . HelperFramework::escape($this->moneyPence($settings, $closingPence)) . '</td>'
                . '<td><span class="badge ' . ($journalState === 'posted' ? 'success' : ($journalState === 'correction_required' ? 'warning' : 'info')) . '">'
                . HelperFramework::escape(HelperFramework::labelFromKey($journalState, '_')) . '</span><br><span class="helper">'
                . HelperFramework::escape(HelperFramework::labelFromKey((string)($allocation['posting_role'] ?? ''), '_'))
                . ' target ' . HelperFramework::escape($this->moneyPence($settings, (int)($allocation['posting_target_pence'] ?? 0)))
                . '</span></td></tr>';

            $unallocatedPence = (int)($schedule['unallocated_pence'] ?? 0);
            if ($unallocatedPence > 0) {
                $warnings[] = $sourceLabel . ' has ' . $this->moneyPence($settings, $unallocatedPence)
                    . ' of future service not yet assigned because the later accounting period has not been created.';
            }
            foreach ((array)($schedule['source_errors'] ?? []) as $error) {
                $warnings[] = $sourceLabel . ': ' . (string)$error;
            }
        }

        return '<div class="summary-grid">'
            . $this->summary('Selected-AP expense', $this->moneyPence($settings, (int)($periodContext['total_expense_pence'] ?? 0)))
            . $this->summary('Closing Prepayments asset', $this->moneyPence($settings, (int)($periodContext['total_closing_deferred_pence'] ?? 0)))
            . '</div>'
            . '<div class="table-scroll"><table class="table"><thead><tr>'
            . '<th>Source</th><th class="numeric">Purchase</th><th>Service period</th><th>Inclusive-day calculation</th>'
            . '<th class="numeric">AP expense</th><th class="numeric">Closing deferred</th><th>Journal state</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . $this->messages(array_values(array_unique(array_filter(array_map('strval', $warnings)))))
            . $this->accountingDisclosure()
            . $this->guidanceLinks();
    }

    private function accountingDisclosure(): string
    {
        return '<div class="helper">Amounts use cumulative half-up rounding in integer pennies and inclusive calendar days, so all accounting-period allocations reconcile exactly to the purchase. The final Year End close posts one initial deferral and direct releases in later periods. No monthly release journals are created in this phase, so a later period\'s allocation appears in its first service month while the accounting-period total remains exact.</div>';
    }

    private function guidanceLinks(): string
    {
        return '<div class="actions-row">'
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('bim42201', 'HMRC - BIM42201')
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('bim70066', 'HMRC - BIM70066')
            . \eel_accounts\Renderer\TaxCardRenderer::guidanceLink('frs105', 'FRC - FRS 105')
            . '</div>';
    }

    private function summary(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label)
            . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function messages(array $messages): string
    {
        if ($messages === []) {
            return '';
        }

        return '<div class="helper"><ul><li>'
            . implode('</li><li>', array_map(static fn(mixed $message): string => HelperFramework::escape((string)$message), $messages))
            . '</li></ul></div>';
    }

    private function moneyPence(array $settings, int $pence): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $pence / 100);
    }
}
