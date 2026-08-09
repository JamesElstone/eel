<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _director_loan_attributionCard extends CardBaseFramework
{
    private const TABLE_KEY = 'director_loan_attribution';
    private const PAGE_SIZE = 10;
    private const FILTER_FIELD = 'director_loan_attribution_filter';

    public function key(): string
    {
        return 'director_loan_attribution';
    }

    public function services(): array
    {
        return [[
            'key' => 'directorLoanStatement',
            'service' => \eel_accounts\Service\DirectorLoanService::class,
            'method' => 'fetchStatement',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
            ],
        ]];
    }

    public function title(): string
    {
        return 'Participant Loan Assignment';
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
        $pageContext[$this->key()][self::FILTER_FIELD] = $this->normaliseFilter((string)$request->input(
            self::FILTER_FIELD,
            (string)(($pageContext[$this->key()] ?? [])[self::FILTER_FIELD] ?? 'all')
        ));

        return $pageContext;
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
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

        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        return '<section class="panel-soft settings-stack">
            <div class="eyebrow">Participator Loan Party</div>
            ' . $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]) . '
        </section>';
    }

    private function configuredTable(array $context): TableFramework
    {
        $filter = $this->selectedFilter($context);
        $table = $this->table($context, $filter);
        $pagination = HelperFramework::paginateArray(
            $table->sortedRows(),
            $this->paginationPage($context),
            self::PAGE_SIZE
        );

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Director attribution',
                $this->paginationPageField(),
                $this->tableHiddenFields($context)
            )
            ->filterSelect(
                self::FILTER_FIELD,
                'Show',
                $this->filterOptions(),
                $filter,
                $this->tableHiddenFields($context, false)
            );
    }

    private function tableHiddenFields(array $context, bool $includeFilter = true): array
    {
        $fields = [
            'page' => (string)($context['page']['page_id'] ?? 'loans'),
            '_pagination' => '1',
            '_invalidate_fact' => (string)($this->invalidationFacts()[0] ?? 'director.loan.state'),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'company_id' => (int)($context['company']['id'] ?? 0),
            'accounting_period_id' => (int)($context['company']['accounting_period_id'] ?? 0),
        ];
        if ($includeFilter) {
            $fields[self::FILTER_FIELD] = $this->selectedFilter($context);
        }

        return $fields;
    }

    private function table(array $context, string $filter): TableFramework
    {
        $statement = (array)($context['services']['directorLoanStatement'] ?? []);
        $entries = $this->withRunningBalances((array)($statement['attribution_entries'] ?? []));
        $entries = array_values(array_filter(
            $entries,
            fn(mixed $entry): bool => is_array($entry) && ($filter === 'all' || (int)($entry['director_id'] ?? 0) <= 0)
        ));
        $directors = array_values(array_filter((array)($statement['directors'] ?? []), static fn(mixed $director): bool => is_array($director)));
        $settings = ['default_currency_symbol' => (string)($statement['default_currency_symbol'] ?? '&#163;')];
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        return \eel_accounts\Support\Utf8Table::make(self::TABLE_KEY, $entries)
            ->filename('director-loan-attribution')
            ->exportLimit(5000)
            ->empty($filter === 'requires_assignment'
                ? 'No Director Loan entries currently require assignment.'
                : 'No Director Loan control-account entries were found.')
            ->column('journal_date', 'Date', html: static fn(array $row): string => \eel_accounts\Support\Utf8::html(HelperFramework::displayDate((string)($row['journal_date'] ?? ''))), export: static fn(array $row): string => (string)($row['journal_date'] ?? ''), exportType: 'date')
            ->textColumn('description', 'Description')
            ->column('counterparty_name', 'Actual counterparty', html: static function (array $row): string {
                $counterparty = trim((string)($row['counterparty_name'] ?? ''));
                return $counterparty !== '' ? \eel_accounts\Support\Utf8::html($counterparty) : '<span class="helper">Not stated</span>';
            }, export: static fn(array $row): string => trim((string)($row['counterparty_name'] ?? '')))
            ->column('source_label', 'Source', html: fn(array $row): string => $this->attributionSourceHtml($row), export: static fn(array $row): string => trim((string)($row['source_label'] ?? '')))
            ->column('signed_amount', 'Movement', html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, $this->attributionAmount($row))), export: fn(array $row): string => number_format($this->attributionAmount($row), 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('running_balance', 'Running balance', html: fn(array $row): string => \eel_accounts\Support\Utf8::html($this->money($settings, (float)($row['running_balance'] ?? 0))), export: static fn(array $row): string => number_format((float)($row['running_balance'] ?? 0), 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('director_id', 'Participator loan account', html: fn(array $row): string => $this->attributionForm($row, $directors, $companyId, $accountingPeriodId, $filter), export: fn(array $row): string => $this->attributedDirectorLabel($row, $directors));
    }

    /** @param list<mixed> $entries @return list<array<string,mixed>> */
    private function withRunningBalances(array $entries): array
    {
        $entries = array_values(array_filter($entries, 'is_array'));
        usort($entries, static fn(array $left, array $right): int => [
            (string)($left['journal_date'] ?? ''),
            (int)($left['journal_id'] ?? 0),
            (int)($left['journal_line_id'] ?? 0),
        ] <=> [
            (string)($right['journal_date'] ?? ''),
            (int)($right['journal_id'] ?? 0),
            (int)($right['journal_line_id'] ?? 0),
        ]);

        $balance = 0.0;
        foreach ($entries as &$entry) {
            $balance = round($balance + $this->attributionAmount($entry), 2);
            $entry['running_balance'] = $balance;
        }
        unset($entry);

        return $entries;
    }

    private function selectedFilter(array $context): string
    {
        return $this->normaliseFilter((string)(($context[$this->key()] ?? [])[self::FILTER_FIELD] ?? 'all'));
    }

    private function normaliseFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return array_key_exists($filter, $this->filterOptions()) ? $filter : 'all';
    }

    private function filterOptions(): array
    {
        return [
            'all' => 'All',
            'requires_assignment' => 'Requires Assignment',
        ];
    }

    private function attributionSourceHtml(array $entry): string
    {
        $sourceLabel = trim((string)($entry['source_label'] ?? ''));
        $sourceUrl = trim((string)($entry['source_url'] ?? ''));
        $sourceHtml = $sourceUrl !== '' ? '<a class="button" href="' . \eel_accounts\Support\Utf8::html($sourceUrl) . '">' . \eel_accounts\Support\Utf8::html($sourceLabel) . '</a>' : \eel_accounts\Support\Utf8::html($sourceLabel);
        return $sourceHtml;
    }

    private function attributionAmount(array $entry): float
    {
        return round((float)($entry['signed_amount'] ?? ((float)($entry['credit'] ?? 0) - (float)($entry['debit'] ?? 0))), 2);
    }

    private function attributionForm(array $entry, array $directors, int $companyId, int $accountingPeriodId, string $filter): string
    {
        $currentDirectorId = (int)($entry['director_id'] ?? 0);
        $options = '<option value="" disabled' . ($currentDirectorId <= 0 ? ' selected' : '') . '>Choose party</option>';
        foreach ((new \eel_accounts\Service\OwnershipPartyService())->effectiveParties($companyId, (string)($entry['journal_date'] ?? '')) as $director) {
            $directorId = (int)($director['id'] ?? 0);
            $options .= '<option value="' . $directorId . '"' . ($directorId === $currentDirectorId ? ' selected' : '') . '>' . \eel_accounts\Support\Utf8::html((string)$director['legal_name'] . ((int)($director['linked_director_id'] ?? 0) > 0 ? ' (Director)' : '')) . '</option>';
        }
        $isLocked = (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        return '<form method="post" data-ajax="true" class="actions-row">' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '<input type="hidden" name="card_action" value="YearEnd"><input type="hidden" name="intent" value="set_participator_loan_attribution"><input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '"><input type="hidden" name="journal_line_id" value="' . (int)($entry['journal_line_id'] ?? 0) . '"><input type="hidden" name="director_loan_attribution_filter" value="' . \eel_accounts\Support\Utf8::html($this->normaliseFilter($filter)) . '"><select class="input' . ($isLocked ? ' control-disabled' : '') . '" name="party_id" required' . ($isLocked ? ' disabled aria-disabled="true"' : '') . '>' . $options . '</select></form>';
    }

    private function attributedDirectorLabel(array $entry, array $directors): string
    {
        $currentDirectorId = (int)($entry['director_id'] ?? 0);
        foreach ($directors as $director) {
            if ((int)($director['id'] ?? 0) === $currentDirectorId) return $this->directorLabel($director);
        }
        return 'Unattributed';
    }

    private function directorLabel(array $director): string
    {
        return trim((string)($director['full_name'] ?? '')) . (empty($director['is_active']) ? ' (former director)' : '');
    }

    private function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($settings, $value);
    }

    private function errors(array $errors): string
    {
        return implode('', array_map(static fn(mixed $error): string => '<div class="helper">' . \eel_accounts\Support\Utf8::html((string)$error) . '</div>', $errors));
    }
}
