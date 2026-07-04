<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_payment_matchingCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'incorporation_payment_matching';
    }

    public function title(): string
    {
        return 'Share Payment Matching';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'incorporationShares',
                'service' => \eel_accounts\Service\IncorporationShareCapitalService::class,
                'method' => 'fetchSummary',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['incorporation.status', 'incorporation.share.capital', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $settings = (array)($company['settings'] ?? []);
        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if ($companyId <= 0) {
            return '<div class="helper">Select or add a company before matching share payments.</div>';
        }
        if (empty($summary['available'])) {
            return '<section class="settings-stack"><div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Share payment matching is not available.')) . '</div></section>';
        }

        $blocks = '';
        foreach ((array)($summary['share_classes'] ?? []) as $shareClass) {
            if (is_array($shareClass)) {
                $blocks .= $this->shareClassBlock($companyId, $settings, $shareClass);
            }
        }

        if ($blocks === '') {
            $blocks = '<div class="helper">Record formation share capital before matching the incoming payment.</div>';
        }

        return '<section class="settings-stack" id="incorporation-payment-matching">' . $blocks . '</section>';
    }

    private function shareClassBlock(int $companyId, array $settings, array $shareClass): string
    {
        $shareClassId = (int)($shareClass['id'] ?? 0);
        $currentMatch = $shareClass['current_match'] ?? null;
        $candidates = (array)($shareClass['payment_candidates'] ?? []);
        $status = (string)($shareClass['payment_status'] ?? '');
        $candidateRows = '';

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateRows .= '<tr>
                <td>' . HelperFramework::escape(HelperFramework::displayDate((string)($candidate['txn_date'] ?? ''))) . '</td>
                <td>' . HelperFramework::escape((string)($candidate['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($candidate['reference'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->money($settings, $candidate['amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape((string)($candidate['category_status'] ?? '')) . '</td>
                <td>' . $this->matchForm($companyId, $shareClassId, (int)($candidate['id'] ?? 0)) . '</td>
            </tr>';
        }

        $matchHtml = is_array($currentMatch)
            ? '<div class="panel-soft">
                <div class="eyebrow">Current match</div>
                <div><strong>' . HelperFramework::escape($this->money($settings, $currentMatch['matched_amount'] ?? 0)) . '</strong> from transaction #' . (int)($currentMatch['transaction_id'] ?? 0) . '</div>
                <div class="helper">' . HelperFramework::escape(HelperFramework::displayDate((string)($currentMatch['txn_date'] ?? '')) . ' ' . (string)($currentMatch['description'] ?? '')) . '</div>
                ' . $this->clearForm($companyId, $shareClassId) . '
            </div>'
            : '<div class="helper">No incoming share payment has been matched yet.</div>';

        return '<div class="panel-soft stack">
            <div class="status-head">
                <h3 class="card-title">' . HelperFramework::escape((string)($shareClass['share_class'] ?? 'Share class')) . '</h3>
                <span class="badge ' . HelperFramework::escape($this->badgeClass($status)) . '">' . HelperFramework::escape($this->statusLabel($status)) . '</span>
            </div>
            <div class="summary-grid">
                <div class="summary-card"><div class="summary-label">Expected paid total</div><div class="summary-value">' . HelperFramework::escape($this->money($settings, $shareClass['expected_paid_total'] ?? 0)) . '</div></div>
                <div class="summary-card"><div class="summary-label">Unpaid total</div><div class="summary-value">' . HelperFramework::escape($this->money($settings, $shareClass['unpaid_total'] ?? 0)) . '</div></div>
            </div>
            ' . $matchHtml . '
            <h3 class="card-title">Candidate receipts</h3>
            ' . ($candidateRows === ''
                ? '<div class="helper">No exact incoming payment candidates were found in the incorporation window.</div>'
                : '<div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Reference</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>' . $candidateRows . '</tbody></table></div>') . '
        </div>';
    }

    private function matchForm(int $companyId, int $shareClassId, int $transactionId): string
    {
        if ($transactionId <= 0) {
            return '';
        }

        return '<form method="post" data-ajax="true">
            <input type="hidden" name="card_action" value="Incorporation">
            <input type="hidden" name="intent" value="match_share_payment">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="share_class_id" value="' . $shareClassId . '">
            <input type="hidden" name="transaction_id" value="' . $transactionId . '">
            <button class="button primary" type="submit">Match</button>
        </form>';
    }

    private function clearForm(int $companyId, int $shareClassId): string
    {
        return '<form method="post" data-ajax="true" class="actions-row">
            <input type="hidden" name="card_action" value="Incorporation">
            <input type="hidden" name="intent" value="clear_share_payment_match">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="share_class_id" value="' . $shareClassId . '">
            <button class="button secondary" type="submit">Clear Match</button>
        </form>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'payment_matched' => 'Payment matched',
            'payment_mismatch' => 'Payment mismatch',
            'not_paid_up' => 'Not paid up',
            default => 'Payment not matched',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'payment_matched' => 'success',
            'not_paid_up' => 'warning',
            default => 'danger',
        };
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
