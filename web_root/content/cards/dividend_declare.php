<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_declareCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_declare';
    }

    public function title(): string
    {
        return 'Declare Dividend';
    }

    public function services(): array
    {
        return [
            $this->dividendContextService(),
            [
                'key' => 'dividendDeclarationParticipants',
                'service' => \eel_accounts\Service\DividendService::class,
                'method' => 'fetchDeclarationParticipants',
                'params' => [
                    'companyId' => ':company.id',
                ],
            ],
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companySettings = (array)($company['settings'] ?? []);
        $dividends = $this->dividendsContext($context);
        $capacity = (array)($dividends['capacity'] ?? []);
        $accountingPeriod = (array)($capacity['accounting_period'] ?? []);
        $participants = $this->declarationParticipants($context);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        $defaultDate = (string)($capacity['as_at_date'] ?? date('Y-m-d'));
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        $isLocked = array_key_exists('is_locked', $dividends)
            ? (bool)$dividends['is_locked']
            : (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);

        $disabledReason = '';
        if ($isLocked) {
            $disabledReason = 'This accounting period is locked.';
        } elseif ($companyId <= 0 || $accountingPeriodId <= 0) {
            $disabledReason = 'Select a company and accounting period before declaring a dividend.';
        } elseif (empty($capacity['available'])) {
            $capacityErrors = (array)($capacity['errors'] ?? []);
            $disabledReason = (string)($capacityErrors[0] ?? 'Dividend capacity is not available.');
        } elseif (empty($capacity['reserves_reliable'])) {
            $disabledReason = (string)($capacity['reserve_basis_detail'] ?? $capacity['retained_earnings_detail'] ?? 'Reserve basis is not ready for dividend declarations.');
        } elseif ($availableReserves < 0) {
            $disabledReason = 'Available distributable reserves are negative.';
        } elseif ($availableReserves <= 0) {
            $disabledReason = 'The selected period has no positive available reserves.';
        }

        $shareholderOptions = $this->shareholderOptions($participants, $defaultDate);
        $directorOptions = $this->directorOptions($participants, $defaultDate);
        if ($disabledReason === '' && $shareholderOptions === '') {
            $disabledReason = 'Record an effective shareholder on Ownership & Parties before declaring a dividend.';
        }
        if ($disabledReason === '' && $directorOptions === '') {
            $disabledReason = 'Record an authorising director before declaring a dividend.';
        }

        $shareholderOptions = '<option value="">Select shareholder</option>' . $shareholderOptions;
        $directorOptions = '<option value="">Select authorising director</option>' . $directorOptions;

        $canDeclare = $disabledReason === '';
        $disabled = $canDeclare ? '' : ' disabled';
        $helper = $canDeclare
            ? 'Maximum currently available: ' . $this->money($companySettings, $availableReserves) . '.'
            : 'Dividend declarations can be saved only once the form is enabled.';
        $statusItems = $canDeclare
            ? ''
            : '<div class="helper">Form Disabled - Reason: ' . HelperFramework::escape($disabledReason) . '</div>';
        $statusItems .= '<div class="helper">' . HelperFramework::escape($helper) . '</div>';
        $statusPanelClass = 'panel-soft dividend-declare-status ' . ($canDeclare ? 'success' : 'warn');

        return '<div class="settings-stack">
            <div class="' . $statusPanelClass . '">' . $statusItems . '</div>
            <form method="post" action="?page=dividends" data-ajax="true" class="form-grid">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Dividend">
                <input type="hidden" name="intent" value="declare_dividend">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <input type="hidden" name="settlement_target" value="unpaid_dividend_liability">
                <div class="form-row">
                    <label for="dividend_declaration_date">Declaration date</label>
                    <input class="input" id="dividend_declaration_date" type="date" name="declaration_date" value="' . HelperFramework::escape($defaultDate) . '" min="' . HelperFramework::escape($periodStart) . '" max="' . HelperFramework::escape($defaultDate !== '' ? $defaultDate : $periodEnd) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_amount">Amount</label>
                    <input class="input" id="dividend_amount" type="number" name="amount" step="0.01" min="0.01" max="' . HelperFramework::escape(number_format(max(0, $availableReserves), 2, '.', '')) . '"' . $disabled . '>
                </div>
                <div class="form-row">
                    <label for="dividend_shareholder_party_id">Shareholder</label>
                    <select class="select" id="dividend_shareholder_party_id" name="shareholder_party_id" required' . $disabled . '>
                        ' . $shareholderOptions . '
                    </select>
                </div>
                <div class="form-row">
                    <label for="dividend_director_id">Authorising director</label>
                    <select class="select" id="dividend_director_id" name="director_id" required' . $disabled . '>
                        ' . $directorOptions . '
                    </select>
                </div>
                <div class="form-row">
                    <label for="dividend_description">Description</label>
                    <input class="input" id="dividend_description" name="description" value="Interim dividend"' . $disabled . '>
                </div>
                <div class="actions-row">
                    <button class="button primary" type="submit"' . $disabled . '>Declare Dividend</button>
                </div>
            </form>
        </div>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }

    private function dividendContextService(): array
    {
        return [
            'key' => 'dividendContext',
            'service' => \eel_accounts\Service\DividendViewDataService::class,
            'method' => 'fetchCapacityContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ];
    }

    private function dividendsContext(array $context): array
    {
        $serviceContext = $context['services']['dividendContext'] ?? null;
        if (is_array($serviceContext)) {
            return $serviceContext;
        }

        return (array)($context['dividends'] ?? []);
    }

    private function declarationParticipants(array $context): array
    {
        $serviceParticipants = $context['services']['dividendDeclarationParticipants'] ?? null;
        if (is_array($serviceParticipants)) {
            return $serviceParticipants;
        }

        return (array)(($context['dividends'] ?? [])['declaration_participants'] ?? []);
    }

    private function shareholderOptions(array $participants, string $date): string
    {
        $labels = [];
        foreach ((array)($participants['shareholdings'] ?? []) as $holding) {
            if (!is_array($holding) || !$this->effectiveOn($holding, $date)) {
                continue;
            }
            $partyId = (int)($holding['party_id'] ?? 0);
            $name = trim((string)($holding['legal_name'] ?? ''));
            if ($partyId <= 0 || $name === '') {
                continue;
            }
            $holdingLabel = trim((int)($holding['quantity'] ?? 0) . ' ' . (string)($holding['share_class'] ?? 'shares'));
            $labels[$partyId]['name'] = $name;
            $labels[$partyId]['holdings'][] = $holdingLabel;
        }

        $html = '';
        foreach ($labels as $partyId => $party) {
            $html .= '<option value="' . (int)$partyId . '">'
                . HelperFramework::escape((string)$party['name'] . ' — ' . implode(', ', array_unique((array)$party['holdings'])))
                . '</option>';
        }

        return $html;
    }

    private function directorOptions(array $participants, string $date): string
    {
        $html = '';
        foreach ((array)($participants['directors'] ?? []) as $director) {
            if (!is_array($director) || !$this->effectiveOn($director, $date)) {
                continue;
            }
            $directorId = (int)($director['id'] ?? 0);
            $name = trim((string)($director['full_name'] ?? ''));
            if ($directorId > 0 && $name !== '') {
                $html .= '<option value="' . $directorId . '">' . HelperFramework::escape($name) . '</option>';
            }
        }

        return $html;
    }

    private function effectiveOn(array $record, string $date): bool
    {
        $from = trim((string)($record['effective_from'] ?? $record['appointed_on'] ?? ''));
        $to = trim((string)($record['effective_to'] ?? $record['resigned_on'] ?? ''));
        return ($from === '' || $from <= $date) && ($to === '' || $to >= $date);
    }
}
