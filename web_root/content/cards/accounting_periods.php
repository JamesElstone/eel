<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _accounting_periodsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'accounting_periods';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $settings = (array)($page['settings'] ?? []);
        $taxYears = (array)($page['tax_years'] ?? []);
        $accountingGuidance = (array)($page['accounting_guidance'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $errors = $this->normaliseErrors($page['accounting_errors'] ?? $page['errors']['accounting'] ?? []);
        $selectedTaxYearId = trim((string)($settings['tax_year_id'] ?? ''));
        $usesFiledPeriods = (string)($accountingGuidance['suggestion_basis'] ?? '') === 'companies_house_filed_periods';

        ob_start();
        ?>
        <div class="card settings-section" data-section="accounting" data-accounting-section>
            <div class="card-header">
                <h2 class="card-title">Accounting Periods</h2>
            </div>
            <div class="card-body">
                <div data-accounting-inline-feedback><?= $this->renderInlineFeedback($errors) ?></div>
                <?php if (!empty($accountingGuidance['missing_suggested_periods'])): ?>
                    <div class="card" style="margin-bottom: 16px; box-shadow: none;">
                        <div class="card-header">
                            <h3 class="card-title">Missing Accounting Periods Detected</h3>
                        </div>
                        <div class="card-body">
                            <?php if ($usesFiledPeriods): ?>
                                <div class="helper" style="margin-bottom: 12px;">Latest imported filed period end: <?= $this->escape(($accountingGuidance['latest_filed_period_end_display'] ?? '') !== '' ? (string)$accountingGuidance['latest_filed_period_end_display'] : 'Not available') ?></div>
                                <div class="helper" style="margin-bottom: 12px;">Accounting periods imported from Companies House records have been automatically added. The suggest periods below follow periods already filled for.</div>
                            <?php else: ?>
                                <div class="helper" style="margin-bottom: 12px;">Detected incorporation date: <?= $this->escape(($accountingGuidance['incorporation_date_display'] ?? '') !== '' ? (string)$accountingGuidance['incorporation_date_display'] : 'Not available') ?></div>
                                <div class="helper" style="margin-bottom: 12px;">Setting the accounting period to end on the last day of the month one year after incorporation aligns with the standard accounting reference date automatically assigned by Companies House. This approach ensures consistency with statutory filing expectations, simplifies period comparisons, and provides a clear and practical month-end cut-off for financial reporting, reconciliation, and tax preparation.</div>
                            <?php endif; ?>
                            <div class="list">
                                <?php foreach ((array)$accountingGuidance['missing_suggested_periods'] as $period): ?>
                                    <div class="list-item">
                                        <strong>Suggested period</strong>
                                        <span><?= $this->escape($this->periodDisplayRange((array)$period)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 16px;">
                                <button class="button primary" type="submit" onclick="document.getElementById('settings_action_field').value='create_suggested_periods'" data-ajax-card-update="companies-accounting,companies-setup-health">Create Missing Suggested Accounting Periods</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="form-grid">
                    <div class="form-row full">
                        <label for="tax_year_id">Accounting period</label>
                        <select
                            class="select"
                            id="tax_year_id"
                            name="tax_year_id"
                            data-accounting-period-select
                            onchange="if (!window.eelAccountingInlineRefreshEnabled) { document.getElementById('settings_action_field').value='refresh_tax_year'; this.form.submit(); }"
                        >
                            <option value=""<?= $selectedTaxYearId === '' ? ' selected' : '' ?>>New Period</option>
                            <?php foreach ($taxYears as $taxYear): ?>
                                <option value="<?= (int)($taxYear['id'] ?? 0) ?>"<?= (string)($taxYear['id'] ?? '') === $selectedTaxYearId ? ' selected' : '' ?>><?= $this->escape((string)($taxYear['label'] ?? '')) ?> (<?= $this->escape($this->displayDate((string)($taxYear['period_start'] ?? ''), $selectedCompanyId, $dateFormat)) ?> to <?= $this->escape($this->displayDate((string)($taxYear['period_end'] ?? ''), $selectedCompanyId, $dateFormat)) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row half">
                        <label for="financial_period_label">Period Alias Name</label>
                        <input class="input" id="financial_period_label" name="financial_period_label" value="<?= $this->escape((string)($settings['financial_period_label'] ?? '')) ?>">
                    </div>
                    <div class="form-row half">
                        <label for="period_start">Period start</label>
                        <input class="input" type="date" id="period_start" name="period_start" value="<?= $this->escape((string)($settings['period_start'] ?? '')) ?>">
                    </div>
                    <div class="form-row half">
                        <label for="period_end">Period end</label>
                        <input class="input" type="date" id="period_end" name="period_end" value="<?= $this->escape((string)($settings['period_end'] ?? '')) ?>">
                    </div>
                    <div class="form-row full">
                        <button class="button section-save-button" type="submit" disabled onclick="document.getElementById('settings_action_field').value='save_accounting'" data-ajax-card-update="companies-accounting,companies-setup-health">Save Accounting Defaults</button>
                    </div>
                </div>
                <?php if (!empty($accountingGuidance['ct_periods'])): ?>
                    <div class="card" style="margin-top: 16px; box-shadow: none;">
                        <div class="card-header">
                            <h3 class="card-title">Derived CT600 Returns Required</h3>
                        </div>
                        <div class="card-body">
                            <?php if (($accountingGuidance['ct600_summary'] ?? '') !== ''): ?>
                                <div class="helper" style="margin-bottom: 12px;"><?= $this->escape((string)$accountingGuidance['ct600_summary']) ?></div>
                            <?php endif; ?>
                            <div class="list">
                                <?php foreach ((array)$accountingGuidance['ct_periods'] as $index => $ctPeriod): ?>
                                    <div class="list-item">
                                        <strong><?= $this->escape('CT Period ' . ((int)$index + 1)) ?></strong>
                                        <span><?= $this->escape($this->periodDisplayRange((array)$ctPeriod)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($accountingGuidance['coverage']['months'])): ?>
                    <div class="card" style="margin-top: 16px; box-shadow: none;">
                        <div class="card-header">
                            <h3 class="card-title">Transaction Coverage Summary</h3>
                        </div>
                        <div class="card-body">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Month</th>
                                        <th>Transactions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ((array)$accountingGuidance['coverage']['months'] as $month): ?>
                                        <tr>
                                            <td>
                                                <span class="coverage-light-dot" style="background: <?= (int)($month['txn_count'] ?? 0) > 0 ? '#16a34a' : '#dc2626' ?>; display:inline-block;"></span>
                                            </td>
                                            <td><?= $this->escape((string)($month['label'] ?? '')) ?></td>
                                            <td><?= (int)($month['txn_count'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if ((int)($accountingGuidance['coverage']['outside_period_count'] ?? 0) > 0): ?>
                                <div class="helper" style="margin-top: 6px;"><?= (int)$accountingGuidance['coverage']['outside_period_count'] ?> linked transaction<?= (int)$accountingGuidance['coverage']['outside_period_count'] === 1 ? '' : 's' ?> fall outside the selected accounting period.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return trim((string)ob_get_clean());
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function periodDisplayRange(array $period): string
    {
        $displayRange = trim((string)($period['display_range'] ?? ''));
        if ($displayRange !== '') {
            return $displayRange;
        }

        $start = trim((string)($period['start'] ?? ''));
        $end = trim((string)($period['end'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return '';
    }

    private function renderInlineFeedback(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        return '<div class="helper">' . $this->escape(implode(' ', $errors)) . '</div>';
    }

    private function normaliseErrors(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item !== '') {
                    $errors[] = $item;
                }
            }
        }

        return $errors;
    }

    private function displayDate(string $value, int $companyId, string $dateFormat): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return HelperFramework::displayDate($value, $companyId, $dateFormat);
    }
}
