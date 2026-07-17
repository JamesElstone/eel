<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_facts_previewCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_facts_preview'; }

    public function title(): string { return 'iXBRL Facts Preview'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $companySettings = (array)($company['settings'] ?? []);
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $facts = (array)($context['ixbrl']['facts'] ?? []);
        $disabled = !empty($readiness['can_build_facts']) ? '' : ' disabled';
        $freshness = (array)($readiness['run_freshness'] ?? []);
        $freshnessState = (string)($freshness['state'] ?? ($facts === [] ? 'missing' : 'unknown'));

        $rows = '';
        foreach ($facts as $fact) {
            $rows .= '<tr>
                <td>' . HelperFramework::escape($this->section($fact)) . '</td>
                <td><div>' . HelperFramework::escape((string)($fact['taxonomy_concept'] ?? '')) . '</div><div class="helper">' . HelperFramework::escape((string)($fact['label'] ?? $fact['fact_key'] ?? '')) . '</div></td>
                <td>' . HelperFramework::escape($this->typeAndUnit($fact)) . '</td>
                <td><div>' . HelperFramework::escape((string)($fact['context_ref'] ?? '')) . '</div><div class="helper">' . HelperFramework::escape($this->dimensions($fact)) . '</div></td>
                <td>' . HelperFramework::escape($this->value($fact, $companySettings)) . '</td>
                <td>' . HelperFramework::escape($this->sourceSummary($fact)) . '</td>
            </tr>';
        }

        $table = $facts === []
            ? '<div class="helper">No generated facts yet. Build facts once the readiness checks pass.</div>'
            : '<div class="table-scroll"><table class="data-table"><thead><tr><th>Section</th><th>Concept</th><th>Type / unit</th><th>Context / dimensions</th><th>Value</th><th>Source</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head"><h3 class="card-title">Latest fact snapshot</h3><span class="badge ' . HelperFramework::escape($this->freshnessClass($freshnessState)) . '">' . HelperFramework::escape(HelperFramework::labelFromKey($freshnessState, '_')) . '</span></div>
                <div class="helper">' . HelperFramework::escape((string)($freshness['detail'] ?? 'Build facts to create a traceable snapshot of the current accounts report.')) . '</div>
            </section>
            <form method="post" action="?page=disclosures" data-ajax="true" class="actions-row">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Ixbrl">
                <input type="hidden" name="intent" value="build_ixbrl_facts">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <button class="button primary" type="submit"' . $disabled . '>Build / Refresh Facts</button>
            </form>
            ' . $table . '
        </div>';
    }

    private function value(array $fact, array $companySettings): string
    {
        return match ((string)($fact['value_type'] ?? 'text')) {
            'numeric' => $this->numericValue($fact, $companySettings),
            'date' => (string)($fact['date_value'] ?? ''),
            'boolean' => $this->booleanLabel($fact['text_value'] ?? null),
            default => (string)($fact['text_value'] ?? ''),
        };
    }

    private function numericValue(array $fact, array $companySettings): string
    {
        $value = (float)($fact['numeric_value'] ?? 0);
        $unit = strtolower(trim((string)($fact['unit_ref'] ?? '')));
        if (in_array($unit, ['gbp', 'iso4217:gbp'], true)) {
            return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
        }

        $decimals = filter_var($fact['decimals_value'] ?? null, FILTER_VALIDATE_INT);
        $places = $decimals === false ? 2 : max(0, min(6, (int)$decimals));
        return number_format($value, $places, '.', ',') . ($unit !== '' ? ' ' . (string)($fact['unit_ref'] ?? '') : '');
    }

    private function booleanLabel(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        if (in_array($value, ['1', 'true', 'yes'], true)) {
            return 'Yes';
        }
        if (in_array($value, ['0', 'false', 'no'], true)) {
            return 'No';
        }

        return (string)$value;
    }

    private function section(array $fact): string
    {
        $section = trim((string)($fact['section'] ?? $fact['section_key'] ?? ''));
        if ($section !== '') {
            return HelperFramework::labelFromKey($section, '_');
        }

        $source = $this->source($fact);
        $section = trim((string)($source['section'] ?? $source['report_section'] ?? ''));
        return $section !== '' ? HelperFramework::labelFromKey($section, '_') : 'Accounts';
    }

    private function typeAndUnit(array $fact): string
    {
        $type = HelperFramework::labelFromKey((string)($fact['value_type'] ?? 'text'), '_');
        $unit = trim((string)($fact['unit_ref'] ?? ''));
        $decimals = trim((string)($fact['decimals_value'] ?? ''));

        return $type
            . ($unit !== '' ? ' / ' . $unit : '')
            . ($decimals !== '' ? ' (' . $decimals . ' decimals)' : '');
    }

    private function dimensions(array $fact): string
    {
        $summary = trim((string)($fact['dimension_summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $json = trim((string)($fact['dimensions_json'] ?? $fact['dimension_json'] ?? ''));
        if ($json === '') {
            return 'No dimensions';
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        $parts = [];
        foreach ($decoded as $dimension => $member) {
            $parts[] = (string)$dimension . ': ' . (is_scalar($member) ? (string)$member : json_encode($member));
        }
        return $parts !== [] ? implode('; ', $parts) : 'No dimensions';
    }

    private function sourceSummary(array $fact): string
    {
        $source = $this->source($fact);
        foreach (['summary', 'source_summary', 'label', 'source_label'] as $key) {
            if (trim((string)($source[$key] ?? '')) !== '') {
                return trim((string)$source[$key]);
            }
        }

        $parts = [];
        foreach (['calculation_type', 'source_key', 'source_service', 'report_line'] as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '') {
                $parts[] = HelperFramework::labelFromKey($value, '_');
            }
        }

        return $parts !== [] ? implode(' · ', array_values(array_unique($parts))) : 'Stored fact snapshot';
    }

    private function source(array $fact): array
    {
        $decoded = json_decode((string)($fact['source_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function freshnessClass(string $state): string
    {
        return match ($state) {
            'current' => 'success',
            'stale', 'unverifiable' => 'warning',
            default => 'muted',
        };
    }
}
