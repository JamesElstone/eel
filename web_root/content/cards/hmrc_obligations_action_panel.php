<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_obligations_action_panelCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_obligations_action_panel'; }

    public function title(): string { return 'HMRC Next Action'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $guidance = (array)($context['hmrc_obligations']['guidance'] ?? []);
        $messages = (array)($guidance['messages'] ?? []);
        $matches = (array)($guidance['suggested_matches'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);

        $html = '';
        foreach ($messages as $message) {
            $html .= '<section class="panel-soft"><div class="helper">' . HelperFramework::escape((string)$message) . '</div></section>';
        }

        if ($matches !== []) {
            $rows = '';
            foreach ($matches as $match) {
                $rows .= '<tr>
                    <td>#' . (int)($match['obligation_id'] ?? 0) . '</td>
                    <td>' . HelperFramework::escape((string)($match['txn_date'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape((string)($match['description'] ?? $match['reference'] ?? '')) . '</td>
                    <td>' . HelperFramework::escape(FormattingFramework::money($match['amount'] ?? 0)) . '</td>
                    <td><span class="badge warning">Suggested only</span></td>
                </tr>';
            }
            $html .= '<section class="panel-soft"><h3 class="card-title">Possible bank matches</h3><div class="helper">Bank payments are never auto-matched. Confirm them before marking obligations as paid.</div><div class="table-scroll"><table><thead><tr><th>Obligation</th><th>Date</th><th>Transaction</th><th>Amount</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        }

        $syncForm = '<form method="post" action="?page=hmrc_obligations" data-ajax="true">
            <input type="hidden" name="card_action" value="HmrcObligation">
            <input type="hidden" name="intent" value="sync_hmrc_obligations">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <button class="button primary" type="submit">Sync Calculated Obligations</button>
        </form>';

        return '<div class="settings-stack">' . ($html !== '' ? $html : '<div class="helper">No HMRC guidance is available yet.</div>') . $syncForm . '</div>';
    }
}
