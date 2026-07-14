<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_obligations_timelineCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_obligations_timeline'; }

    public function title(): string { return 'HMRC Obligations Timeline'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $items = (array)($context['hmrc_obligations']['timeline'] ?? []);
        $filters = (array)($context['hmrc_obligations']['filters'] ?? []);
        $selected = (string)($context['hmrc_obligations']['filter'] ?? 'all');
        $companyId = (int)($context['company']['id'] ?? 0);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $pageId = (string)(($context['page'] ?? [])['page_id'] ?? 'hmrc_obligations');

        $filterOptions = '';
        foreach ($filters as $value => $label) {
            $filterOptions .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        $filterForm = '<form class="toolbar" method="post" action="?page=hmrc_obligations" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="HmrcObligation">
            <input type="hidden" name="intent" value="filter_obligations">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <select class="select" name="hmrc_filter">' . $filterOptions . '</select>
            <button class="button primary" type="submit">Filter</button>
        </form>';

        if ($items === []) {
            return $filterForm . '<div class="helper">No HMRC obligations match the selected filter.</div>';
        }

        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($item['accounting_period_label'] ?? '')) . '<div class="helper">' . HelperFramework::escape((string)($item['period_start'] ?? '') . ' to ' . (string)($item['period_end'] ?? '')) . '</div></td>
                <td>' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['obligation_type'] ?? ''), '_')) . $this->chHint((array)($item['companies_house'] ?? [])) . '</td>
                <td>' . HelperFramework::escape((string)($item['due_date'] ?? '')) . '<div class="helper">' . HelperFramework::escape($this->daysLabel((int)($item['days_delta'] ?? 0))) . '</div></td>
                <td>' . HelperFramework::escape($this->amountLabel($item, $companySettings)) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->badgeClass((string)($item['effective_status'] ?? ''))) . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($item['effective_status'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($item['action_needed'] ?? '')) . '</td>
                <td>' . $this->actionForms($item, $companyId, $selected) . '</td>
            </tr>';
        }

        return $filterForm . '<div class="table-scroll"><table>
            <thead><tr><th>Period</th><th>Obligation</th><th>Due date</th><th>Amount</th><th>Status</th><th>Action needed</th><th>Record evidence</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }

    private function actionForms(array $item, int $companyId, string $filter): string
    {
        $id = (int)($item['id'] ?? 0);
        $type = (string)($item['obligation_type'] ?? '');
        $common = '<input type="hidden" name="card_action" value="HmrcObligation">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="obligation_id" value="' . $id . '">
            <input type="hidden" name="hmrc_filter" value="' . HelperFramework::escape($filter) . '">';

        if ($type === 'ct600_filing') {
            return '<form method="post" action="?page=hmrc_obligations" data-ajax="true" class="mini-form">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                ' . $common . '
                <input type="hidden" name="intent" value="mark_filed">
                <input class="input" name="source_reference" placeholder="HMRC ref">
                <input class="input" name="notes" placeholder="Notes">
                <button class="button primary" type="submit">Mark Filed</button>
            </form>';
        }

        $legacyAmount = (float)($item['legacy_unlinked_amount'] ?? 0);
        $legacyHtml = $legacyAmount > 0.004
            ? '<div class="helper"><span class="badge warning">Legacy unlinked payment</span> £' . number_format($legacyAmount, 2) . ' requires review.</div>'
            : '';
        $linksHtml = $legacyHtml . $this->evidenceLinksHtml((array)($item['evidence_links'] ?? []), $common);
        $options = '<option value="">Select transaction or expense</option>';
        foreach ((array)($item['evidence_candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $value = (string)($candidate['source_type'] ?? '') . ':' . (int)($candidate['id'] ?? 0);
            $label = (string)($candidate['source_label'] ?? 'Evidence') . ' | ' . (string)($candidate['evidence_date'] ?? '') . ' | '
                . (string)($candidate['description'] ?? '') . ' | £' . number_format((float)($candidate['amount'] ?? 0), 2);
            $options .= '<option value="' . HelperFramework::escape($value) . '">' . HelperFramework::escape($label) . '</option>';
        }

        return $linksHtml . '<form method="post" action="?page=hmrc_obligations" data-ajax="true" class="mini-form">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            ' . $common . '
            <input type="hidden" name="intent" value="link_payment_evidence">
            <select class="select" name="evidence_source" required>' . $options . '</select>
            <input class="input" type="number" step="0.01" min="0.01" name="allocated_amount" placeholder="Full available amount">
            <button class="button primary" type="submit">Link Payment</button>
        </form>';
    }

    private function evidenceLinksHtml(array $links, string $common): string
    {
        $html = '';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = HelperFramework::labelFromKey((string)($link['source_type'] ?? ''), '_') . ' | '
                . (string)($link['evidence_date'] ?? '') . ' | ' . (string)($link['description'] ?? '') . ' | '
                . number_format((float)($link['allocated_amount'] ?? 0), 2);
            $html .= '<form method="post" action="?page=hmrc_obligations" data-ajax="true" class="actions-row">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . $common
                . '<input type="hidden" name="intent" value="unlink_payment_evidence">'
                . '<input type="hidden" name="evidence_link_id" value="' . (int)($link['id'] ?? 0) . '">'
                . '<span class="helper">' . HelperFramework::escape($label) . '</span>'
                . '<button class="button button-inline danger" type="submit">Unlink</button></form>';
        }

        return $html;
    }

    private function chHint(array $ch): string
    {
        return !empty($ch['filed']) ? '<div class="helper">CH accounts filed ' . HelperFramework::escape((string)($ch['filing_date'] ?? '')) . '</div>' : '';
    }

    private function amountLabel(array $item, array $companySettings): string
    {
        if (($item['amount_due'] ?? null) === null) {
            return 'Not set';
        }
        return $this->money($companySettings, $item['amount_due']) . ' due / ' . $this->money($companySettings, $item['amount_paid'] ?? 0) . ' paid';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function daysLabel(int $days): string
    {
        if ($days < 0) {
            return abs($days) . ' day(s) overdue';
        }
        if ($days === 0) {
            return 'Due today';
        }
        return $days . ' day(s) remaining';
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
}
