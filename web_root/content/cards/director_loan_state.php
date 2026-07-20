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
    private const ATTRIBUTION_TABLE_KEY = 'director_loan_attribution';
    private const ATTRIBUTION_PAGE_SIZE = 10;

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
            [
                'key' => 'directorLoanReportingPresentation',
                'service' => \eel_accounts\Service\DirectorLoanReportingPresentationService::class,
                'method' => 'fetchPresentation',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Director Loan Statement';
    }

    public function helper(array $context): string
    {
        return 'Assign each posted Director Loan control-account entry to the director whose loan account it belongs to. The transaction counterparty remains separate.';
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
            $this->configuredAttributionTable($context),
        ];
    }

    public function render(array $context): string
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        $presentation = (array)($context['services']['directorLoanReportingPresentation'] ?? []);
        if (empty($statement['success'])) {
            return $this->errors((array)($statement['errors'] ?? ['Director loan statement is unavailable.']));
        }

        $settings = ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $unattributedCount = (int)($statement['unattributed_count'] ?? 0);
        $invalidCount = (int)($statement['invalid_director_count'] ?? 0);

        return '
            <div class="actions-row">
                <a class="button" href="https://www.gov.uk/hmrc-internal-manuals/employment-income-manual/eim26198" target="_blank" rel="noopener noreferrer">HMRC guidance on netting director loan balances</a>
            </div>
            <div class="month-grid">
                ' . $this->stat('Gross Director Loan Asset', $this->money($settings, $statement['asset_receivable'] ?? 0)) . '
                ' . $this->stat('Gross Director Loan Liability', $this->money($settings, $statement['liability_payable'] ?? 0)) . '
                ' . $this->stat('Calculated reclassification', $this->money($settings, $statement['desired_reclassification'] ?? 0)) . '
                ' . $this->stat('Net position', $this->money($settings, $statement['net_position'] ?? 0)) . '
                ' . $this->stat('Gross loan asset (not s455)', $this->money($settings, $statement['potential_s455_exposure'] ?? 0)) . '
                ' . $this->stat(
                    'Unattributed entries',
                    (string)($unattributedCount + $invalidCount),
                    ($unattributedCount + $invalidCount) > 0 ? 'danger' : ''
                ) . '
            </div>
            ' . $this->reportingPresentation(
                $presentation,
                $companyId,
                $accountingPeriodId
            ) . '
            <section class="panel-soft settings-stack">
                <div class="eyebrow">Per-director position</div>
                ' . $this->configuredPositionTable($context)->render($context, [
                    'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]) . '
            </section>
            <section class="panel-soft settings-stack">
                <div class="eyebrow">Director Attribution</div>
                ' . $this->configuredAttributionTable($context)->render($context, [
                    'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]) . '
            </section>';
    }

    private function reportingPresentation(
        array $presentation,
        int $companyId,
        int $accountingPeriodId
    ): string {
        if (empty($presentation['success'])) {
            return '<section class="panel-soft settings-stack">
                <div class="eyebrow">Statutory repayment presentation</div>
                ' . $this->errors((array)($presentation['errors'] ?? ['The Director Loan reporting presentation is unavailable.'])) . '
            </section>';
        }

        $classification = (string)($presentation['classification']
            ?? \eel_accounts\Service\DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR);
        $withinOneYear = \eel_accounts\Service\DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR;
        $afterMoreThanOneYear = \eel_accounts\Service\DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR;
        $nominal = (array)($presentation['liability_nominal'] ?? []);
        $nominalLabel = trim((string)($nominal['code'] ?? '') . ' - ' . (string)($nominal['name'] ?? ''), ' -');
        $schemaReady = !empty($presentation['schema_ready']);
        $isLocked = !empty($presentation['is_locked']);
        $lockedHtml = $isLocked
            ? '<span class="badge warning">Period locked - reporting choice is read only</span>'
            : '';
        $basisHtml = !empty($presentation['explicit'])
            ? '<span class="badge success">Saved reporting choice</span>'
            : '<span class="badge muted">Default: within one year</span>';
        $schemaHtml = $schemaReady
            ? ''
            : '<div class="helper">The reporting-presentation database migration must be applied before this choice can be saved.</div>';
        $currentNominal = (array)($presentation['current_liability_nominal'] ?? []);
        $currentNominalLabel = trim(
            (string)($currentNominal['code'] ?? '') . ' - ' . (string)($currentNominal['name'] ?? ''),
            ' -'
        );
        $mappingHtml = !empty($presentation['nominal_mapping_changed'])
            ? '<div class="helper"><span class="badge warning">Historic nominal retained</span> This period remains tied to '
                . HelperFramework::escape($nominalLabel)
                . ($currentNominalLabel !== ''
                    ? ', while current Company Nominals points to ' . HelperFramework::escape($currentNominalLabel)
                    : '')
                . '. This prevents a later settings change from rewriting the period\'s statutory presentation.</div>'
            : '';

        return '<section class="panel-soft settings-stack director-loan-reporting-presentation">
            <div class="status-head">
                <div>
            <div class="eyebrow">Statutory Repayment Presentation</div>
                    <h3 class="card-title">When is money lent to the company due back?</h3>
                </div>
                <div class="pill-row">' . $basisHtml . $lockedHtml . '</div>
            </div>
            <div class="helper">
                This applies to the full gross balance in '
                . HelperFramework::escape($nominalLabel !== '' ? $nominalLabel : 'the Director Loan Liability control account')
                . '. It changes only the Companies House and iXBRL presentation; it does not alter journals, transactions, balances, nominal accounts, or the Year End lock.
            </div>
            <form method="post" data-ajax="true" class="settings-stack">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="DirectorLoan">
                <input type="hidden" name="intent" value="save_director_loan_reporting_presentation">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
                <div class="segmented-control">
                    <label class="segmented-option">
                        <input type="radio" name="classification" value="' . $withinOneYear . '"'
                            . ($classification === $withinOneYear ? ' checked' : '') . ' required' . ($isLocked ? ' disabled' : '') . '>
                        <span>Due within one year</span>
                    </label>
                    <label class="segmented-option">
                        <input type="radio" name="classification" value="' . $afterMoreThanOneYear . '"'
                            . ($classification === $afterMoreThanOneYear ? ' checked' : '') . ' required' . ($isLocked ? ' disabled' : '') . '>
                        <span>Due after more than one year</span>
                    </label>
                </div>
                <div>
                    <button class="button primary" type="submit"' . ($schemaReady && !$isLocked ? '' : ' disabled') . '>Save reporting presentation</button>
                </div>
            </form>
            ' . $mappingHtml . $schemaHtml . '
        </section>';
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

        return TableFramework::make(self::POSITION_TABLE_KEY, $positions)
            ->filename('director-loan-positions')
            ->exportLimit(5000)
            ->empty('No Director Loan activity or balance exists for this period.')
            ->textColumn('director_name', 'Director loan account', fallback: 'Unattributed')
            ->column(
                'gross_asset',
                'Gross asset',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['gross_asset'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['gross_asset'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'gross_liability',
                'Gross liability',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['gross_liability'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['gross_liability'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'desired_reclassification',
                'Reclassification',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['desired_reclassification'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['desired_reclassification'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'net_closing_position',
                'Net closing',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['net_closing_position'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['net_closing_position'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('net_position_label', 'Position')
            ->column(
                'potential_s455_exposure',
                'Gross asset principal',
                html: fn(array $row): string => HelperFramework::escape($this->money($settings, $row['potential_s455_exposure'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['potential_s455_exposure'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function configuredAttributionTable(array $context): TableFramework
    {
        $hiddenFields = $this->tableHiddenFields($context);
        $table = $this->attributionTable($context);
        $pagination = HelperFramework::paginateArray(
            $table->sortedRows(),
            $this->paginationPage($context),
            self::ATTRIBUTION_PAGE_SIZE
        );

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Director attribution',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function tableHiddenFields(array $context): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? 'director_loans'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'company_id' => (int)($context['company']['id'] ?? 0),
            'accounting_period_id' => (int)($context['company']['accounting_period_id'] ?? 0),
        ];
    }

    private function attributionTable(array $context): TableFramework
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        $entries = array_values(array_filter(
            (array)($statement['attribution_entries'] ?? []),
            static fn(mixed $entry): bool => is_array($entry)
        ));
        $directors = array_values(array_filter(
            (array)($statement['directors'] ?? []),
            static fn(mixed $director): bool => is_array($director)
        ));
        $settings = ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        return TableFramework::make(self::ATTRIBUTION_TABLE_KEY, $entries)
            ->filename('director-loan-attribution')
            ->exportLimit(5000)
            ->empty('No Director Loan control-account entries were found.')
            ->column(
                'journal_date',
                'Date',
                html: static fn(array $row): string => HelperFramework::escape(
                    HelperFramework::displayDate((string)($row['journal_date'] ?? ''))
                ),
                export: static fn(array $row): string => (string)($row['journal_date'] ?? ''),
                exportType: 'date'
            )
            ->textColumn('description', 'Description')
            ->column(
                'counterparty_name',
                'Actual counterparty',
                html: static function (array $row): string {
                    $counterparty = trim((string)($row['counterparty_name'] ?? ''));

                    return $counterparty !== ''
                        ? HelperFramework::escape($counterparty)
                        : '<span class="helper">Not stated</span>';
                },
                export: static fn(array $row): string => trim((string)($row['counterparty_name'] ?? ''))
            )
            ->column(
                'source_label',
                'Source',
                html: fn(array $row): string => $this->attributionSourceHtml($row),
                export: static fn(array $row): string => trim(
                    (string)($row['source_label'] ?? '')
                    . (!empty($row['is_opening']) ? ' (Opening)' : '')
                )
            )
            ->column(
                'signed_amount',
                'Movement',
                html: fn(array $row): string => HelperFramework::escape(
                    $this->money($settings, $this->attributionAmount($row))
                ),
                export: fn(array $row): string => number_format($this->attributionAmount($row), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'director_id',
                'Director loan account',
                html: fn(array $row): string => $this->attributionForm(
                    $row,
                    $directors,
                    $companyId,
                    $accountingPeriodId
                ),
                export: fn(array $row): string => $this->attributedDirectorLabel($row, $directors)
            );
    }

    private function attributionSourceHtml(array $entry): string
    {
        $sourceLabel = trim((string)($entry['source_label'] ?? ''));
        $sourceUrl = trim((string)($entry['source_url'] ?? ''));
        $sourceHtml = $sourceUrl !== ''
            ? '<a class="button" href="' . HelperFramework::escape($sourceUrl) . '">' . HelperFramework::escape($sourceLabel) . '</a>'
            : HelperFramework::escape($sourceLabel);

        return $sourceHtml . (!empty($entry['is_opening']) ? ' <span class="badge">Opening</span>' : '');
    }

    private function attributionAmount(array $entry): float
    {
        return round(
            (float)($entry['signed_amount']
                ?? ((float)($entry['credit'] ?? 0) - (float)($entry['debit'] ?? 0))),
            2
        );
    }

    private function attributionForm(
        array $entry,
        array $directors,
        int $companyId,
        int $accountingPeriodId
    ): string {
        $currentDirectorId = (int)($entry['director_id'] ?? 0);
        $options = '<option value="" disabled' . ($currentDirectorId <= 0 ? ' selected' : '') . '>Choose director</option>';
        foreach ($directors as $director) {
            $directorId = (int)($director['id'] ?? 0);
            $options .= '<option value="' . $directorId . '"'
                . ($directorId === $currentDirectorId ? ' selected' : '')
                . '>' . HelperFramework::escape($this->directorLabel($director)) . '</option>';
        }

        $isLocked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        return '<form method="post" data-ajax="true" class="actions-row">
            ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="YearEnd">
            <input type="hidden" name="intent" value="set_director_loan_attribution">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="journal_line_id" value="' . (int)($entry['journal_line_id'] ?? 0) . '">
            <select class="input' . ($isLocked ? ' control-disabled' : '') . '" name="director_id" required' . ($isLocked ? ' disabled aria-disabled="true"' : '') . '>' . $options . '</select>
        </form>';
    }

    private function attributedDirectorLabel(array $entry, array $directors): string
    {
        $currentDirectorId = (int)($entry['director_id'] ?? 0);
        foreach ($directors as $director) {
            if ((int)($director['id'] ?? 0) === $currentDirectorId) {
                return $this->directorLabel($director);
            }
        }

        return 'Unattributed';
    }

    private function directorLabel(array $director): string
    {
        $label = trim((string)($director['full_name'] ?? ''));

        return $label . (empty($director['is_active']) ? ' (former director)' : '');
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'director.loan.state');
    }

    private function stat(string $label, string $value, string $class = ''): string
    {
        return '<div class="summary-card' . ($class !== '' ? ' ' . HelperFramework::escape($class) : '') . '"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value) . '</div></div>';
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($settings, $value);
    }

    private function errors(array $errors): string
    {
        return implode('', array_map(
            static fn(mixed $error): string => '<div class="helper">' . HelperFramework::escape((string)$error) . '</div>',
            $errors
        ));
    }
}
