<?php
declare(strict_types=1);

final class _hmrc_submission_overviewCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_overview'; }

    public function title(): string { return 'CT600 Filing Readiness'; }

    public function services(): array
    {
        return [[
            'key' => 'hmrc_submission',
            'service' => \eel_accounts\Service\HmrcCtSubmissionReadModel::class,
            'method' => 'pageState',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'selectedCtPeriodId' => ':hmrc_submission_selection.selected_ct_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['hmrc.submission', 'page.context'];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $data = $this->data($context);
        $readiness = (array)($data['readiness'] ?? []);
        $selectedPeriod = (array)($data['selected_period'] ?? []);
        $latest = (array)($data['latest_submission'] ?? []);
        $environment = (string)($data['environment'] ?? 'TEST');
        $frozen = $this->frozenPackage($latest);

        $checks = '';
        foreach ((array)($readiness['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $complete = !empty($check['complete']) || !empty($check['ok']) || !empty($check['passed']);
            $blocking = array_key_exists('blocking', $check) ? !empty($check['blocking']) : !$complete;
            $state = $complete ? 'success' : ($blocking ? 'danger' : 'warning');
            $label = trim((string)($check['label'] ?? $check['key'] ?? 'Readiness check'));
            $detail = trim((string)($check['detail'] ?? $check['message'] ?? ''));
            $checks .= '<tr>
                <td>' . HelperFramework::escape($label) . '</td>
                <td><span class="badge ' . $state . '">' . ($complete ? 'Ready' : ($blocking ? 'Blocked' : 'Review')) . '</span></td>
                <td>' . HelperFramework::escape($detail) . '</td>
            </tr>';
        }
        if ($checks === '') {
            $checks = '<tr><td>CT600 filing readiness</td><td><span class="badge danger">Blocked</span></td><td>The readiness assessment is unavailable.</td></tr>';
        }

        $blockers = $this->messages((array)($readiness['blockers'] ?? []), 'danger', 'Blocking issue');
        $warnings = $this->messages((array)($readiness['warnings'] ?? []), 'warning', 'Review');

        return '<div class="settings-stack">
            <section class="panel-soft">
                <div class="status-head">
                    <div>
                        <h3 class="card-title">' . HelperFramework::escape((string)($data['environment_label'] ?? $environment)) . '</h3>
                        <div class="helper">' . HelperFramework::escape((string)($data['environment_notice'] ?? '')) . '</div>
                    </div>
                    <span class="badge ' . $this->environmentBadge($environment) . '">' . HelperFramework::escape($environment) . '</span>
                </div>
            </section>
            <div class="summary-grid">
                ' . $this->metric('Company', (string)($company['name'] ?? $company['company_name'] ?? '')) . '
                ' . $this->metric('Accounting period', (string)($company['accounting_period_label'] ?? ('#' . (int)($data['accounting_period_id'] ?? 0)))) . '
                ' . $this->metric('Selected return', $this->periodLabel($selectedPeriod)) . '
                ' . $this->metric('Year End lock', !empty($readiness['lock']['is_locked']) || !empty($readiness['lock']['locked']) || !empty($readiness['lock']['complete']) ? 'Locked' : 'Not locked') . '
                ' . $this->metric('Accounts iXBRL', $this->artifactLabel((array)($readiness['accounts'] ?? []))) . '
                ' . $this->metric('Computations iXBRL', $this->artifactLabel((array)($readiness['computations'] ?? []))) . '
                ' . $this->metric('Protocol state', $this->stateLabel((string)($latest['protocol_state'] ?? $latest['status'] ?? 'Not prepared'))) . '
                ' . $this->metric('Business outcome', $this->stateLabel((string)($latest['business_outcome'] ?? $latest['outcome'] ?? 'None'))) . '
            </div>
            <section class="panel-soft">
                <h3 class="card-title">Returns required for this Year End</h3>
                <div class="summary-grid">' . $this->progress((array)($data['progress'] ?? [])) . '</div>
            </section>
            ' . $this->frozenPackageHtml($latest, $frozen) . '
            ' . $blockers . $warnings . '
            <div class="table-scroll"><table class="data-table">
                <thead><tr><th>Readiness check</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>' . $checks . '</tbody>
            </table></div>
        </div>';
    }

    private function data(array $context): array
    {
        return (array)($context['services']['hmrc_submission'] ?? $context['hmrc_submission'] ?? []);
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }

    private function progress(array $steps): string
    {
        if ($steps === []) {
            return $this->metric('CT periods', 'None available');
        }

        $html = '';
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $label = (string)($step['label'] ?? 'CT Period');
            $dates = trim((string)($step['period_start'] ?? '') . ' to ' . (string)($step['period_end'] ?? ''));
            $state = (string)($step['state'] ?? 'pending');
            $selected = !empty($step['selected']) ? ' - selected' : '';
            $html .= $this->metric($label . $selected, $dates . ' - ' . $this->stateLabel($state));
        }

        return $html;
    }

    private function frozenPackage(array $latest): array
    {
        $validation = [];
        $json = trim((string)($latest['validation_json'] ?? ''));
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                $validation = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
            }
        }

        $mapping = (array)($validation['mapping'] ?? $validation['package_mapping'] ?? []);
        $source = (array)($mapping['source'] ?? []);
        $calculation = (array)($mapping['calculation'] ?? []);
        $taxPence = (int)($calculation[\eel_accounts\Service\Ct600ReturnData::TAX_PAYABLE] ?? 0);
        if ($taxPence === 0 && isset($source['estimated_corporation_tax'])) {
            $taxPence = (int)round((float)$source['estimated_corporation_tax'] * 100);
        }

        return [
            'tax_result' => number_format($taxPence / 100, 2),
            'local_validation' => (string)(
                $validation['local_validation']['status']
                ?? $validation['status']
                ?? ''
            ),
        ];
    }

    private function frozenPackageHtml(array $latest, array $frozen): string
    {
        if ((int)($latest['id'] ?? 0) <= 0) {
            return '';
        }

        return '<section class="panel-soft">
            <h3 class="card-title">Exact frozen package</h3>
            <div class="helper">Approval and submission are bound to these fingerprints and the IRmark. Any source change requires a new package.</div>
            <div class="summary-grid">
                ' . $this->metric('Tax payable', '£' . (string)($frozen['tax_result'] ?? '0.00')) . '
                ' . $this->metric('Declarant', trim((string)($latest['declarant_name'] ?? '') . ' - ' . (string)($latest['declarant_status'] ?? ''))) . '
                ' . $this->metric('Accounts SHA-256', (string)($latest['accounts_sha256'] ?? '')) . '
                ' . $this->metric('Computations SHA-256', (string)($latest['computations_sha256'] ?? '')) . '
                ' . $this->metric('Package SHA-256', (string)($latest['package_hash'] ?? '')) . '
                ' . $this->metric('IRmark', (string)($latest['irmark'] ?? '')) . '
                ' . $this->metric('RIM schema', (string)($latest['schema_version'] ?? '')) . '
                ' . $this->metric('Local validation', $this->stateLabel((string)($frozen['local_validation'] ?? 'passed'))) . '
            </div>
        </section>';
    }

    private function messages(array $messages, string $badge, string $label): string
    {
        $html = '';
        foreach ($messages as $message) {
            if (is_array($message)) {
                $message = $message['message'] ?? $message['detail'] ?? '';
            }
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $html .= '<div class="helper"><span class="badge ' . $badge . '">' . HelperFramework::escape($label) . '</span> ' . HelperFramework::escape($message) . '</div>';
        }

        return $html;
    }

    private function artifactLabel(array $artifact): string
    {
        if (empty($artifact['ok']) && empty($artifact['ready'])) {
            return (string)($artifact['state'] ?? $artifact['status'] ?? 'Missing');
        }

        return (string)($artifact['filename'] ?? $artifact['generated_filename'] ?? 'Ready');
    }

    private function periodLabel(array $period): string
    {
        if ($period === []) {
            return 'None';
        }

        $label = (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0)));
        return $label . ': ' . (string)($period['period_start'] ?? '') . ' to ' . (string)($period['period_end'] ?? '');
    }

    private function environmentBadge(string $environment): string
    {
        return match ($environment) {
            'LIVE' => 'danger',
            'TIL' => 'warning',
            default => 'info',
        };
    }

    private function stateLabel(string $state): string
    {
        $state = trim($state);
        return $state === '' ? 'None' : HelperFramework::labelFromKey($state, '_');
    }
}
