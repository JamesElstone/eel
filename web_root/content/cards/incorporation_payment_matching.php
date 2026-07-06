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
    private const CANDIDATE_PAGE_SIZE = 10;
    private const CANDIDATE_TABLE_SCOPE = 'candidate_payments';

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

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyPaginationContext($request, $pageContext, self::CANDIDATE_TABLE_SCOPE);
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
                $blocks .= $this->shareClassBlock($companyId, $settings, $shareClass, $context);
            }
        }

        if ($blocks === '') {
            $blocks = '<div class="helper">Record formation share capital before matching the incoming payment.</div>';
        }

        return '<section class="settings-stack" id="incorporation-payment-matching">' . $blocks . '</section>';
    }

    public function tables(array $context): array
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $settings = (array)($company['settings'] ?? []);
        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if ($companyId <= 0 || empty($summary['available'])) {
            return [];
        }

        $tables = [];
        foreach ((array)($summary['share_classes'] ?? []) as $shareClass) {
            if (!is_array($shareClass) || $this->hasValidMatch($shareClass)) {
                continue;
            }

            $tables[] = $this->configuredCandidateTable($companyId, $settings, $shareClass, $context);
        }

        return $tables;
    }

    private function shareClassBlock(int $companyId, array $settings, array $shareClass, array $context): string
    {
        $shareClassId = (int)($shareClass['id'] ?? 0);
        $currentMatch = $shareClass['current_match'] ?? null;
        $status = (string)($shareClass['payment_status'] ?? '');
        $hasValidMatch = $this->hasValidMatch($shareClass);

        $matchWarning = is_array($currentMatch) && empty($currentMatch['match_valid'])
            ? '<div class="helper warning">' . HelperFramework::escape($this->invalidMatchMessage((string)($currentMatch['match_invalid_reason'] ?? ''))) . '</div>'
            : '';
        $matchHtml = is_array($currentMatch)
            ? '<div class="panel-soft">
                <div class="eyebrow">Current match</div>
                <div><strong>' . HelperFramework::escape($this->money($settings, $currentMatch['matched_amount'] ?? 0)) . '</strong> from transaction #' . (int)($currentMatch['transaction_id'] ?? 0) . '</div>
                <div class="helper">' . HelperFramework::escape(HelperFramework::displayDate((string)($currentMatch['txn_date'] ?? '')) . ' ' . (string)($currentMatch['description'] ?? '')) . '</div>
                ' . $matchWarning . '
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
                <div class="summary-card"><div class="summary-label">Unpaid share capital</div><div class="summary-value">' . HelperFramework::escape($this->money($settings, $shareClass['paid_up_unpaid_total'] ?? ($shareClass['unpaid_total'] ?? 0))) . '</div></div>
            </div>
            ' . $matchHtml . '
            ' . ($hasValidMatch ? '' : '<h3 class="card-title">Candidate Payments</h3>'
                . $this->configuredCandidateTable($companyId, $settings, $shareClass, $context)->render($context, $this->tableHiddenFields($context))) . '
        </div>';
    }

    private function configuredCandidateTable(int $companyId, array $settings, array $shareClass, array $context): TableFramework
    {
        $table = $this->candidateTable($companyId, $settings, $shareClass);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context, self::CANDIDATE_TABLE_SCOPE), self::CANDIDATE_PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Candidate Payments',
                $this->paginationPageField(self::CANDIDATE_TABLE_SCOPE),
                $this->tableHiddenFields($context)
            );
    }

    private function candidateTable(int $companyId, array $settings, array $shareClass): TableFramework
    {
        $shareClassId = (int)($shareClass['id'] ?? 0);
        $shareClassLabel = HelperFramework::normaliseCardKey((string)($shareClass['share_class'] ?? 'share_class'));

        return TableFramework::make($this->candidateTableKey($shareClass), $this->candidateRows($shareClass))
            ->filename('incorporation-candidate-payments-' . $shareClassLabel)
            ->exportLimit(5000)
            ->empty('No exact incoming payment candidates were found.')
            ->column('txn_date_display', 'Date')
            ->primarySecondaryColumn('description', 'Transaction', 'reference')
            ->column(
                'amount',
                'Amount',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                exportType: 'number',
                cellClass: 'numeric'
            )
            ->column('category_status', 'Status')
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->matchForm($companyId, $shareClassId, (int)($row['id'] ?? 0)),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function candidateRows(array $shareClass): array
    {
        $rows = [];
        foreach ((array)($shareClass['payment_candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidate['txn_date_display'] = HelperFramework::displayDate((string)($candidate['txn_date'] ?? ''));
            $rows[] = $candidate;
        }

        return $rows;
    }

    private function candidateTableKey(array $shareClass): string
    {
        return self::CANDIDATE_TABLE_SCOPE . '_' . max(0, (int)($shareClass['id'] ?? 0));
    }

    private function hasValidMatch(array $shareClass): bool
    {
        $currentMatch = $shareClass['current_match'] ?? null;

        return is_array($currentMatch) && !empty($currentMatch['match_valid']);
    }

    private function tableHiddenFields(array $context): array
    {
        return [
            'page' => (string)(($context['page'] ?? [])['page_id'] ?? 'incorporation'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }

    private function matchForm(int $companyId, int $shareClassId, int $transactionId): string
    {
        if ($transactionId <= 0) {
            return '';
        }

        return '<form method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
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
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
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

    private function invalidMatchMessage(string $reason): string
    {
        return match ($reason) {
            'transaction_recategorised' => 'This matched transaction has been re-categorised away from Ordinary Share Capital, so these shares are currently treated as not paid up.',
            'transaction_amount_changed' => 'This matched transaction no longer matches the expected paid share total, so these shares are currently treated as not paid up.',
            'transaction_company_mismatch' => 'This matched transaction no longer belongs to this company, so these shares are currently treated as not paid up.',
            default => 'This matched transaction is no longer valid, so these shares are currently treated as not paid up.',
        };
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
