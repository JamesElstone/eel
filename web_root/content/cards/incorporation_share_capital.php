<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _incorporation_share_capitalCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'incorporation_share_capital';
    }

    public function title(): string
    {
        return 'Formation Share Capital';
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
        return ['incorporation.status', 'incorporation.payment.matching', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        if ($companyId <= 0) {
            return '<div class="helper">Select or add a company before recording formation shares.</div>';
        }

        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if (empty($summary['available'])) {
            return '<section class="settings-stack"><div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Formation share capital is not available.')) . '</div></section>';
        }

        $shareClasses = (array)($summary['share_classes'] ?? []);
        $draftShareClass = (array)($context['incorporation_share_capital']['draft_share_class'] ?? []);
        $existingHtml = '';
        foreach ($shareClasses as $shareClass) {
            if (is_array($shareClass)) {
                $existingHtml .= $this->shareForm($companyId, $shareClass);
            }
        }

        return '<section class="settings-stack" id="incorporation-share-capital">
            ' . ($shareClasses === [] ? '<div class="helper">Enter the statement of capital from the incorporation filing. Companies House exposes this in the document, not as structured API fields.</div>' : '') . '
            ' . $existingHtml . '
            ' . $this->newincDraftButton($companyId) . '
            ' . $this->shareForm($companyId, null, $draftShareClass) . '
        </section>';
    }

    private function shareForm(int $companyId, ?array $shareClass, array $draftShareClass = []): string
    {
        $isExisting = is_array($shareClass);
        $id = $isExisting ? (int)($shareClass['id'] ?? 0) : 0;
        $formId = 'incorporation-share-form-' . ($id > 0 ? $id : 'new');
        $title = $isExisting ? 'Recorded share class' : 'Add share class';
        $values = $isExisting ? (array)$shareClass : $draftShareClass;
        $aggregateNominalValue = $isExisting ? $this->aggregateNominalValue($shareClass) : $this->decimalValue($values['aggregate_nominal_value'] ?? '');
        $totalAggregateUnpaid = $isExisting ? $this->totalAggregateUnpaid($shareClass) : $this->decimalValue($values['total_aggregate_unpaid'] ?? '0');

        return '<div class="panel-soft stack">
            <h3 class="card-title">' . HelperFramework::escape($title) . '</h3>
            <div class="helper">Copy the Statement of Capital figures from the incorporation filing. The per-share values used by the ledger are calculated from these aggregate filing values.</div>
            <form id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="Incorporation">
                <input type="hidden" name="intent" value="save_incorporation_shares">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="share_class_id" value="' . $id . '">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-share-class">Class of shares</label>
                        <input class="input" id="' . HelperFramework::escape($formId) . '-share-class" name="share_class" value="' . HelperFramework::escape((string)($values['share_class'] ?? 'Ordinary')) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-currency">Currency</label>
                        <input class="input" id="' . HelperFramework::escape($formId) . '-currency" name="currency" value="' . HelperFramework::escape((string)($values['currency'] ?? 'GBP')) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-quantity">Number allotted</label>
                        <input class="input" type="number" min="1" step="1" id="' . HelperFramework::escape($formId) . '-quantity" name="quantity" value="' . HelperFramework::escape((string)($values['quantity'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-aggregate-nominal">Aggregate nominal value</label>
                        <input class="input" type="number" min="0" step="0.01" id="' . HelperFramework::escape($formId) . '-aggregate-nominal" name="aggregate_nominal_value" value="' . HelperFramework::escape($aggregateNominalValue) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-aggregate-unpaid">Total aggregate unpaid</label>
                        <input class="input" type="number" min="0" step="0.01" id="' . HelperFramework::escape($formId) . '-aggregate-unpaid" name="total_aggregate_unpaid" value="' . HelperFramework::escape($totalAggregateUnpaid) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-document">Source document/reference</label>
                        <input class="input" id="' . HelperFramework::escape($formId) . '-document" name="document_reference" value="' . HelperFramework::escape((string)($values['document_reference'] ?? '')) . '">
                    </div>
                    <div class="form-row">
                        <label for="' . HelperFramework::escape($formId) . '-particulars">Prescribed particulars</label>
                        <textarea class="input" rows="3" id="' . HelperFramework::escape($formId) . '-particulars" name="source_note">' . HelperFramework::escape((string)($values['source_note'] ?? '')) . '</textarea>
                    </div>
                </div>
                <div class="actions-row"><button class="button primary" type="submit">' . ($isExisting ? 'Save Share Class' : 'Add Share Class') . '</button></div>
            </form>
            ' . ($isExisting ? $this->unpaidForm($companyId, $id) : '') . '
        </div>';
    }

    private function newincDraftButton(int $companyId): string
    {
        return '<form method="post" data-ajax="true" class="actions-row">
            <input type="hidden" name="card_action" value="Incorporation">
            <input type="hidden" name="intent" value="populate_incorporation_shares_from_newinc">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <button class="button secondary" type="submit">Pull from NEWINC PDF</button>
        </form>';
    }

    private function unpaidForm(int $companyId, int $shareClassId): string
    {
        return '<form method="post" data-ajax="true" class="actions-row">
            <input type="hidden" name="card_action" value="Incorporation">
            <input type="hidden" name="intent" value="mark_shares_unpaid">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="share_class_id" value="' . $shareClassId . '">
            <button class="button secondary" type="submit">Mark Not Paid Up</button>
        </form>';
    }

    private function decimalValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
    }

    private function aggregateNominalValue(?array $shareClass): string
    {
        if (!is_array($shareClass)) {
            return '';
        }

        if (isset($shareClass['nominal_total'])) {
            return $this->decimalValue($shareClass['nominal_total']);
        }

        return $this->decimalValue((int)($shareClass['quantity'] ?? 0) * (float)($shareClass['nominal_value_per_share'] ?? 0));
    }

    private function totalAggregateUnpaid(?array $shareClass): string
    {
        if (!is_array($shareClass)) {
            return '0';
        }

        if (isset($shareClass['unpaid_total'])) {
            return $this->decimalValue($shareClass['unpaid_total']);
        }

        return $this->decimalValue((int)($shareClass['quantity'] ?? 0) * (float)($shareClass['unpaid_value_per_share'] ?? 0));
    }
}
