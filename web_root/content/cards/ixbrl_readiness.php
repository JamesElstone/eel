<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_readinessCard extends CardBaseFramework
{
    public function key(): string { return 'ixbrl_readiness'; }

    public function title(): string { return 'iXBRL Readiness'; }

    public function helper(array $context): string
    {
        return 'This builder creates a generated FRS 105 micro-entity accounts iXBRL export for review and validation before filing.';
    }

    public function services(): array
    {
        return [[
            'key' => 'companies_house_ixbrl',
            'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
            'method' => 'fetchContext',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array { return ['ixbrl.readiness', 'page.context']; }

    public function render(array $context): string
    {
        $readiness = (array)($context['ixbrl']['readiness'] ?? []);
        $accountingPeriod = (array)($readiness['accounting_period'] ?? []);
        $checks = (array)($readiness['checks'] ?? []);
        $filingReadiness = (array)($context['ixbrl']['ct600_filing_readiness'] ?? []);
        $computationPeriods = (array)($context['ixbrl']['computation_periods'] ?? []);
        $latestRun = (array)($context['ixbrl']['latest_run'] ?? []);
        $companiesHouse = (array)(($context['services'] ?? [])['companies_house_ixbrl'] ?? []);
        [$headline, $headlineClass] = $this->headline($readiness);
        $period = (string)($accountingPeriod['period_start'] ?? '') . ' to ' . (string)($accountingPeriod['period_end'] ?? '');

        $checkGroups = [
            'Accounts and ledger readiness' => [
                'supported_companies_house_identity', 'presentation_currency_gbp',
                'journals_exist', 'journal_lines_balance', 'trial_balance_balanced',
                'closing_balance_sheet_balanced', 'closing_balance_reliable',
                'uncategorised_clear', 'journals_posted', 'required_settings',
                'frs105_deferred_tax_nominal',
            ],
            'Year End and disclosure approval' => [
                'accounts_disclosures_complete', 'micro_entity_size_thresholds',
                'year_end_locked', 'filing_basis_approved',
            ],
            'Generated facts and iXBRL validation' => [
                'facts_generated', 'required_profile_facts', 'ixbrl_generated',
                'ixbrl_validation_passed', 'ixbrl_external_validation',
                'ixbrl_validated_artifact_current',
            ],
        ];
        $checksByCode = [];
        foreach ($checks as $check) {
            $checksByCode[(string)($check['key'] ?? '')] = $check;
        }
        $groupPanels = '';
        foreach ($checkGroups as $title => $codes) {
            $items = '';
            foreach ($codes as $code) {
                if (!isset($checksByCode[$code])) {
                    continue;
                }
                $items .= $this->checkCard((array)$checksByCode[$code]);
            }
            if ($items !== '') {
                $filingOutputs = $title === 'Generated facts and iXBRL validation'
                    ? $this->filingOutputs($readiness, $latestRun, $computationPeriods, $companiesHouse)
                    : '';
                $groupPanels .= '<section class="panel-soft"><h3 class="card-title">'
                    . \eel_accounts\Support\Utf8::html($title) . '</h3>' . $filingOutputs . '<div class="summary-grid">'
                    . $items . '</div></section>';
            }
        }

        $filingItems = '';
        foreach ($filingReadiness as $check) {
            $ready = !empty($check['ready']);
            $filingItems .= '<div class="summary-card">
                <div class="summary-label">' . \eel_accounts\Support\Utf8::html((string)($check['label'] ?? 'Filing prerequisite')) . '</div>
                <div class="summary-value"><span class="badge ' . ($ready ? 'success' : 'warning') . '">' . ($ready ? 'Ready' : 'Not ready') . '</span></div>
                <div class="helper">' . \eel_accounts\Support\Utf8::html((string)($check['detail'] ?? '')) . '</div>
            </div>';
        }

        return '<div class="settings-stack">
            <section class="panel-soft">
                <h3 class="card-title">Filing status</h3>
                <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Period</div>
                    <div class="summary-value">' . \eel_accounts\Support\Utf8::html($period) . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Status</div>
                    <div class="summary-value"><span class="badge ' . \eel_accounts\Support\Utf8::html($headlineClass) . '">' . \eel_accounts\Support\Utf8::html($headline) . '</span></div>
                </div>
                ' . $this->capability('Build facts', !empty($readiness['can_build_facts'])) . '
                ' . $this->capability('Generate filing', !empty($readiness['can_generate'])) . '
                ' . $this->capability('Run Arelle', !empty($readiness['can_validate'])) . '
                ' . $this->capability('Filing ready', !empty($readiness['ready_for_filing'])) . '
                </div>
            </section>
            ' . $groupPanels . '
            <section class="panel-soft">
                <h3 class="card-title">CT600 filing prerequisites</h3>
                <p class="helper">These checks apply to filing preparation after Year End. They do not affect the Year End lock.</p>
                <div class="summary-grid">' . $filingItems . '</div>
            </section>
        </div>';
    }

    private function headline(array $readiness): array
    {
        if (!empty($readiness['ready_for_filing'])) {
            return ['Filing ready', 'success'];
        }
        if (!empty($readiness['can_generate'])) {
            return ['Ready to generate', 'warning'];
        }
        if (!empty($readiness['can_build_facts'])) {
            return ['Ready to build facts', 'info'];
        }

        return ['Not ready', 'danger'];
    }

    private function statusLabel(string $label): string
    {
        return preg_match('/^(?:Build|Generation|Filing) blocked$/i', trim($label)) === 1
            ? 'Not ready'
            : $label;
    }

    private function checkCard(array $check): string
    {
        $statusLabel = $this->statusLabel((string)($check['status_label'] ?? (!empty($check['complete']) ? 'Ready' : 'Warning')));
        return '<div class="summary-card">
            <div class="summary-label">' . \eel_accounts\Support\Utf8::html((string)($check['label'] ?? 'Check')) . '</div>
            <div class="summary-value"><span class="badge ' . \eel_accounts\Support\Utf8::html((string)($check['status'] ?? 'warning')) . '">' . \eel_accounts\Support\Utf8::html($statusLabel) . '</span></div>
            <div class="helper">' . \eel_accounts\Support\Utf8::html((string)($check['detail'] ?? '')) . '</div>
        </div>';
    }

    /** @param list<array<string, mixed>> $computationPeriods */
    private function filingOutputs(array $readiness, array $latestRun, array $computationPeriods, array $companiesHouse): string
    {
        $accountsGenerated = (int)($latestRun['fact_count'] ?? 0) > 0
            && trim((string)($latestRun['generated_path'] ?? '')) !== '';
        $accountsStatus = !empty($readiness['ready_for_filing'])
            ? ['Filing ready', 'success']
            : ($accountsGenerated ? ['Generated', 'warning'] : ['Not generated', 'muted']);

        $ctTotal = count($computationPeriods);
        $ctFresh = 0;
        $ctFileable = 0;
        foreach ($computationPeriods as $period) {
            $status = (array)($period['status'] ?? []);
            $ctFresh += !empty($status['fresh']) ? 1 : 0;
            $ctFileable += !empty($status['fileable']) ? 1 : 0;
        }
        $ctStatus = $ctTotal === 0
            ? ['No CT periods', 'muted']
            : ($ctFileable === $ctTotal
                ? ['Filing ready', 'success']
                : ($ctFresh === $ctTotal ? ['Generated', 'warning'] : ['Not generated', 'muted']));
        $ctDetail = $ctTotal === 0
            ? 'No active Corporation Tax periods.'
            : ($ctFileable . ' of ' . $ctTotal . ' filing-ready; ' . $ctFresh . ' of ' . $ctTotal . ' generated.');

        $submission = (array)($companiesHouse['submission'] ?? []);
        $artifact = (array)($companiesHouse['prepared_artifact'] ?? []);
        $preparationBlockers = array_map('strval', (array)($companiesHouse['preparation_blockers'] ?? []));
        $notRequired = array_filter($preparationBlockers, static fn(string $blocker): bool => str_contains($blocker, 'No Companies House comparison variance requires revised accounts')) !== [];
        $artifactCurrent = !array_key_exists('state', $artifact)
            ? !empty($artifact['filename'])
            : (!empty($artifact['current']) || (string)$artifact['state'] === 'current');
        $companiesHouseStatus = $artifactCurrent && !empty($artifact['filename'])
            ? [HelperFramework::labelFromKey((string)($submission['lifecycle'] ?? 'prepared'), '_'), 'info']
            : (!empty($artifact['filename'])
                ? ['Rebuild required', 'warning']
            : ($notRequired
                ? ['Not required', 'muted']
                : (!empty($companiesHouse['can_prepare']) ? ['Ready to prepare', 'warning'] : ['Not prepared', 'muted'])));

        return '<h4 class="card-title">Filing outputs</h4><div class="summary-grid">'
            . $this->outputCard('HMRC accounts iXBRL', '1 file', $accountsStatus, 'Accounts filing export for this accounting period.')
            . $this->outputCard('HMRC Corporation Tax iXBRL', $ctTotal . ($ctTotal === 1 ? ' CT period' : ' CT periods'), $ctStatus, $ctDetail)
            . $this->outputCard('Companies House revised accounts iXBRL', 'Conditional', $companiesHouseStatus, 'Separate revised-accounts artifact when Companies House revision is required.')
            . '</div>';
    }

    /** @param array{0:string,1:string} $status */
    private function outputCard(string $label, string $quantity, array $status, string $detail): string
    {
        return '<div class="summary-card"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div>'
            . '<div class="summary-value">' . \eel_accounts\Support\Utf8::html($quantity) . '</div>'
            . '<div><span class="badge ' . \eel_accounts\Support\Utf8::html($status[1]) . '">'
            . \eel_accounts\Support\Utf8::html($status[0]) . '</span></div><div class="helper">'
            . \eel_accounts\Support\Utf8::html($detail) . '</div></div>';
    }

    private function capability(string $label, bool $available): string
    {
        return '<div class="summary-card">
            <div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div>
            <div class="summary-value"><span class="badge ' . ($available ? 'success' : 'muted') . '">' . ($available ? 'Available' : 'Not available') . '</span></div>
        </div>';
    }
}
