<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_fines_tableCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_fines_table'; }

    public function title(): string { return 'HMRC Fines & Interest'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $items = array_values(array_filter(
            (array)($context['hmrc_obligations']['all_obligations'] ?? []),
            static fn(array $item): bool => in_array((string)($item['obligation_type'] ?? ''), ['hmrc_penalty', 'hmrc_interest'], true)
        ));

        $form = '<section class="panel-soft">
            <h3 class="card-title">Record HMRC notice</h3>
            <form method="post" action="?page=hmrc_obligations" data-ajax="true" class="form-grid">
                <input type="hidden" name="card_action" value="HmrcObligation">
                <input type="hidden" name="intent" value="create_manual_obligation">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="form-row"><label>Type</label><select class="select" name="obligation_type"><option value="hmrc_penalty">HMRC penalty</option><option value="hmrc_interest">HMRC interest</option><option value="other">Other HMRC balance</option></select></div>
                <div class="form-row"><label>Due date</label><input class="input" type="date" name="due_date"></div>
                <div class="form-row"><label>Amount due</label><input class="input" type="number" step="0.01" min="0" name="amount_due"></div>
                <div class="form-row"><label>HMRC reference</label><input class="input" name="source_reference"></div>
                <div class="form-row"><label>Notes / evidence path</label><input class="input" name="notes"></div>
                <div class="actions-row"><button class="button primary" type="submit">Record Notice</button></div>
            </form>
            <div class="helper">HMRC fines and penalties are normally disallowable for Corporation Tax. Record the notice here, then post/link journals separately when available.</div>
        </section>';

        if ($items === []) {
            return '<div class="settings-stack">' . $form . '<div class="helper">No HMRC fines or interest have been recorded yet.</div></div>';
        }

        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($item['accounting_period_label'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['obligation_type'] ?? ''), '_')) . '</td>
                <td>' . HelperFramework::escape((string)($item['due_date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($item['amount_due'] === null ? 'Not set' : $this->money($companySettings, $item['amount_due'])) . '</td>
                <td>' . HelperFramework::escape($this->money($companySettings, $item['amount_paid'] ?? 0)) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->badgeClass((string)($item['effective_status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['effective_status'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($item['source_reference'] ?? '')) . '</td>
            </tr>';
        }

        return '<div class="settings-stack">' . $form . '<div class="table-scroll"><table><thead><tr><th>Period</th><th>Type</th><th>Due date</th><th>Due</th><th>Paid</th><th>Status</th><th>Reference</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'overdue' => 'danger',
            'paid', 'filed' => 'success',
            'ready', 'in_progress', 'part_paid' => 'info',
            'not_applicable' => 'muted',
            default => 'warning',
        };
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
