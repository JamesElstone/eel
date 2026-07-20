<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_sharesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'incorporation_shares';
    }

    public function title(): string
    {
        return 'Add Shares';
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
        return ['incorporation.share.capital', 'incorporation.status', 'incorporation.payment.matching', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        if ($companyId <= 0) {
            return '<div class="helper">Select or add a company before recording formation shares.</div>';
        }

        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if (empty($summary['available'])) {
            return '<section class="settings-stack"><div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Formation share capital is not available.')) . '</div></section>';
        }

        $draftShareClass = (array)($context['incorporation_shares']['draft_share_class'] ?? []);

        return '<section class="settings-stack" id="incorporation-add-shares">
            ' . $this->shareForm($companyId, $draftShareClass, (array)(($context['company'] ?? [])['settings'] ?? [])) . '
        </section>';
    }

    private function shareForm(int $companyId, array $draftShareClass = [], array $companySettings = []): string
    {
        $formId = 'incorporation-share-form-new';
        $aggregateNominalValue = $this->decimalValue($draftShareClass['aggregate_nominal_value'] ?? '');
        $totalAggregateUnpaid = $this->decimalValue($draftShareClass['total_aggregate_unpaid'] ?? '0');
        $currency = strtoupper(trim((string)($draftShareClass['currency'] ?? 'GBP'))) ?: 'GBP';

        return '
            <form class="incorporation-share-add-form" id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Incorporation">
                <input type="hidden" name="intent" value="save_incorporation_shares">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="share_class_id" value="0">
                <div class="incorporation-share-fields">
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-share-class">Class of shares</label>
                        <input class="input" id="' . HelperFramework::escape($formId) . '-share-class" name="share_class" value="' . HelperFramework::escape((string)($draftShareClass['share_class'] ?? 'Ordinary')) . '">
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-currency">Currency</label>
                        <select class="select" id="' . HelperFramework::escape($formId) . '-currency" name="currency">
                            ' . $this->currencyOptions($currency, $companySettings) . '
                        </select>
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-quantity">Number allotted</label>
                        <input class="input" inputmode="numeric" pattern="[0-9,]*" id="' . HelperFramework::escape($formId) . '-quantity" name="quantity" value="' . HelperFramework::escape((string)($draftShareClass['quantity'] ?? '')) . '">
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-aggregate-nominal">Aggregate nominal value</label>
                        <input class="input" inputmode="numeric" pattern="[0-9,]*" id="' . HelperFramework::escape($formId) . '-aggregate-nominal" name="aggregate_nominal_value" value="' . HelperFramework::escape($aggregateNominalValue) . '">
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-aggregate-unpaid">Total aggregate unpaid</label>
                        <input class="input" inputmode="numeric" pattern="[0-9,]*" id="' . HelperFramework::escape($formId) . '-aggregate-unpaid" name="total_aggregate_unpaid" value="' . HelperFramework::escape($totalAggregateUnpaid) . '">
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-document">Source document/reference</label>
                        <input class="input" id="' . HelperFramework::escape($formId) . '-document" name="document_reference" value="' . HelperFramework::escape((string)($draftShareClass['document_reference'] ?? '')) . '">
                    </div>
                    <div class="field">
                        <label for="' . HelperFramework::escape($formId) . '-particulars">Prescribed particulars (text note)</label>
                        <textarea class="input" rows="1" id="' . HelperFramework::escape($formId) . '-particulars" name="source_note">' . HelperFramework::escape((string)($draftShareClass['source_note'] ?? '')) . '</textarea>
                    </div>
                    <div class="field incorporation-share-actions">
                        <button class="button primary" type="submit">Add Share Class</button>
                    </div>
                </div>
            </form>
        ';
    }

    private function currencyOptions(string $selectedCurrency, array $companySettings): string
    {
        $defaultCurrencySymbol = (new \eel_accounts\Service\CompanySettingsService())->defaultCurrencySymbol($companySettings);
        $defaultCurrencyLabel = 'GBP - ' . $defaultCurrencySymbol;

        return '<option value="GBP"' . ($selectedCurrency === 'GBP' ? ' selected' : '') . '>' . HelperFramework::escape($defaultCurrencyLabel) . '</option>';
    }

    private function decimalValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
    }
}
