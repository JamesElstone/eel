<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_stateCard extends CardBaseFramework
{
    private const POSITION_TABLE_KEY = 'director_loan_positions';
    private const POSITION_PAGE_SIZE = 5;

    public function key(): string
    {
        return 'director_loan_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'directorLoanStatement',
                'service' => \eel_accounts\Service\DirectorLoanService::class,
                'method' => 'fetchStatement',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Participator Loan Statement';
    }

    public function helper(array $context): string
    {
        return 'Assign each posted Participator Loan control-account entry to the eligible party whose loan account it belongs to. Eligibility is checked on the transaction date.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'year.end.director.loan.offset',
            'year.end.checklist',
            'companies.house.snapshot',
            'year.end.companies.house.comparison',
            'ixbrl.readiness',
            'ixbrl.accounts.mapping',
            'ixbrl.facts.preview',
            'ixbrl.generation',
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyPaginationContext($request, $pageContext, 'positions');
    }

    public function tables(array $context): array
    {
        return [
            $this->configuredPositionTable($context),
        ];
    }

    public function render(array $context): string
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        if (empty($statement['success'])) {
            if (!empty($statement['missing_control_nominals'])) {
                return '<div class="panel-soft warn"><div class="helper">Configure both Participator Loan control nominals in Company Nominals.</div>'
                    . '<div class="actions-row"><a class="button" href="?page=companies&amp;show_card=companies_nominals">Configure Participator Loan Nominals</a></div></div>';
            }
            return $this->errors((array)($statement['errors'] ?? ['Director loan statement is unavailable.']));
        }

        $settings = ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $unattributedCount = (int)($statement['unattributed_count'] ?? 0);
        $invalidCount = (int)($statement['invalid_director_count'] ?? 0);

        return '
            <div class="actions-row">
                <a class="button" href="https://www.gov.uk/hmrc-internal-manuals/employment-income-manual/eim26198" target="_blank" rel="noopener noreferrer">HMRC Guidance on Netting Director Loan Balances</a>
            </div>
            <div class="month-grid">
                ' . $this->stat('Gross Participator Loan Asset', $this->money($settings, $statement['asset_receivable'] ?? 0)) . '
                ' . $this->stat('Gross Participator Loan Liability', $this->money($settings, $statement['liability_payable'] ?? 0)) . '
                ' . $this->stat('Calculated reclassification', $this->money($settings, $statement['desired_reclassification'] ?? 0)) . '
                ' . $this->stat('Net position', $this->money($settings, $statement['net_position'] ?? 0)) . '
                ' . $this->stat('Gross loan asset (not s455)', $this->money($settings, $statement['potential_s455_exposure'] ?? 0)) . '
                ' . $this->stat(
                    'Unattributed entries',
                    (string)($unattributedCount + $invalidCount),
                    ($unattributedCount + $invalidCount) > 0 ? 'danger' : '',
                    '<a class="button button-inline" href="?page=loans&amp;show_card=director_loan_attribution">Review Entries</a>'
                ) . '
            </div>
            <section class="panel-soft settings-stack director-loan-per-party-position">
                <div class="eyebrow">Per-party position</div>
                ' . $this->configuredPositionTable($context)->render($context, [
                    'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]) . '
            </section>
            ';
    }

    private function configuredPositionTable(array $context): TableFramework
    {
        $hiddenFields = $this->tableHiddenFields($context);
        $table = $this->positionTable($context);
        $pagination = HelperFramework::paginateArray(
            $table->sortedRows(),
            $this->paginationPage($context, 'positions'),
            self::POSITION_PAGE_SIZE
        );

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Per-director positions',
                $this->paginationPageField('positions'),
                $hiddenFields
            );
    }

    private function positionTable(array $context): TableFramework
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        $positions = array_values(array_filter(
            (array)($statement['per_director'] ?? []),
            static fn(mixed $position): bool => is_array($position)
        ));
        $settings = ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];

        return \eel_accounts\Support\Utf8Table::make(self::POSITION_TABLE_KEY, $positions)
            ->filename('director-loan-positions')
            ->exportLimit(5000)
            ->empty('No Director Loan activity or balance exists for this period.')
            ->textColumn('director_name', 'Director loan account', fallback: 'Unattributed')
            ->column(
                'gross_asset',
                'Gross asset',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $row['gross_asset'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['gross_asset'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'gross_liability',
                'Gross liability',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $row['gross_liability'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['gross_liability'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'desired_reclassification',
                'Reclassification',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $row['desired_reclassification'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['desired_reclassification'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'net_closing_position',
                'Net closing',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $row['net_closing_position'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['net_closing_position'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('net_position_label', 'Position')
            ->column(
                'potential_s455_exposure',
                'Gross asset principal',
                html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $row['potential_s455_exposure'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['potential_s455_exposure'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function tableHiddenFields(array $context): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? 'loans'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'company_id' => (int)($context['company']['id'] ?? 0),
            'accounting_period_id' => (int)($context['company']['accounting_period_id'] ?? 0),
        ];
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'director.loan.state');
    }

    private function stat(string $label, string $value, string $class = '', string $actionHtml = ''): string
    {
        return '<div class="summary-card' . ($class !== '' ? ' ' . \eel_accounts\Support\Utf8::html($class) : '') . '"><div class="summary-label">' . \eel_accounts\Support\Utf8::html($label) . '</div><div class="summary-value">' . \eel_accounts\Support\Utf8::html($value) . '</div>' . ($actionHtml !== '' ? '<div class="actions-row">' . $actionHtml . '</div>' : '') . '</div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($settings, $value);
    }

    private function errors(array $errors): string
    {
        return implode('', array_map(
            static fn(mixed $error): string => '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)$error) . '</div>',
            $errors
        ));
    }
}
