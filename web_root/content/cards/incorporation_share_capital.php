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
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'incorporation_share_capital';
    }

    public function title(): string
    {
        return 'Share Capital';
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
            [
                'key' => 'ownership',
                'service' => \eel_accounts\Service\OwnershipPartyService::class,
                'method' => 'fetchSummary',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['incorporation.status', 'incorporation.payment.matching', 'ownership.parties', 'year.end.checklist'];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        if ($companyId <= 0) {
            return '<div class="helper">Select or add a company before reviewing formation shares.</div>';
        }

        $summary = (array)($context['services']['incorporationShares'] ?? []);
        if (empty($summary['available'])) {
            return '<section class="settings-stack"><div class="helper">' . HelperFramework::escape((string)(($summary['errors'] ?? [])[0] ?? 'Formation share capital is not available.')) . '</div></section>';
        }

        return '<section class="settings-stack" id="incorporation-share-capital">'
            . $this->shareCapitalTableWithNewRow($context)
            . '</section>';
    }

    public function tables(array $context): array
    {
        if ((int)($context['company']['id'] ?? 0) <= 0 || empty($context['services']['incorporationShares']['available'])) {
            return [];
        }

        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? ''),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination($pagination, 'Share classes', $this->paginationPageField(), $hiddenFields);
    }

    private function shareCapitalTableWithNewRow(array $context): string
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $formId = 'incorporation-share-form-new';
        $tableHtml = $this->configuredTable($context)->render($context, [
            'cards[]' => (array)($context['page']['page_cards'] ?? []),
        ]);

        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        return $this->newShareForm($companyId, $formId, (int)($accountingPeriod['id'] ?? 0))
            . str_replace('</tbody>', $this->newShareRow($formId, (array)($context['incorporation_shares']['draft_share_class'] ?? []), (array)(($context['company'] ?? [])['settings'] ?? []), (string)($accountingPeriod['period_end'] ?? '')) . '</tbody>', $tableHtml);
    }

    private function newShareForm(int $companyId, string $formId, int $accountingPeriodId): string
    {
        return '<form id="' . HelperFramework::escape($formId) . '" method="post" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="Incorporation">'
            . '<input type="hidden" name="intent" value="save_incorporation_shares">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="share_class_id" value="0">'
            . '</form>';
    }

    private function newShareRow(string $formId, array $draftShareClass, array $companySettings, string $periodEnd): string
    {
        $field = static fn(string $name): string => HelperFramework::escape($formId . '-' . $name);
        $form = HelperFramework::escape($formId);
        $currency = strtoupper(trim((string)($draftShareClass['currency'] ?? 'GBP'))) ?: 'GBP';
        $issueDate = $this->newShareIssueDate($draftShareClass, $periodEnd);

        return '<tr class="incorporation-share-new-row">'
            . '<td><input class="input" form="' . $form . '" type="date" id="' . $field('issued-at') . '" name="issued_at" value="' . HelperFramework::escape($issueDate) . '" max="' . HelperFramework::escape($periodEnd) . '" aria-label="Date" required></td>'
            . '<td><input class="input" form="' . $form . '" id="' . $field('share-class') . '" name="share_class" value="' . HelperFramework::escape((string)($draftShareClass['share_class'] ?? 'Ordinary')) . '" aria-label="Class of shares"></td>'
            . '<td><select class="select" form="' . $form . '" id="' . $field('currency') . '" name="currency" aria-label="Currency">' . $this->currencyOptions($currency, $companySettings) . '</select></td>'
            . '<td><input class="input" form="' . $form . '" inputmode="numeric" pattern="[0-9,]*" id="' . $field('quantity') . '" name="quantity" value="' . HelperFramework::escape((string)($draftShareClass['quantity'] ?? '')) . '" aria-label="Number allotted"></td>'
            . '<td class="numeric">—</td>'
            . '<td><input class="input" form="' . $form . '" inputmode="numeric" pattern="[0-9,]*" id="' . $field('aggregate-nominal') . '" name="aggregate_nominal_value" value="' . HelperFramework::escape($this->decimalValue($draftShareClass['aggregate_nominal_value'] ?? '')) . '" aria-label="Aggregate nominal value"></td>'
            . '<td><input class="input" form="' . $form . '" inputmode="numeric" pattern="[0-9,]*" id="' . $field('aggregate-unpaid') . '" name="total_aggregate_unpaid" value="' . HelperFramework::escape($this->decimalValue($draftShareClass['total_aggregate_unpaid'] ?? '0')) . '" aria-label="Total aggregate unpaid"></td>'
            . '<td>—</td>'
            . '<td><input class="input" form="' . $form . '" id="' . $field('document') . '" name="document_reference" value="' . HelperFramework::escape((string)($draftShareClass['document_reference'] ?? '')) . '" aria-label="Source document or reference"></td>'
            . '<td><textarea class="input" form="' . $form . '" rows="1" id="' . $field('particulars') . '" name="source_note" aria-label="Prescribed particulars">' . HelperFramework::escape((string)($draftShareClass['source_note'] ?? '')) . '</textarea></td>'
            . '<td class="cell-fit"><button class="button primary" form="' . $form . '" type="submit">Add Share Class</button></td>'
            . '</tr>';
    }

    private function currencyOptions(string $selectedCurrency, array $companySettings): string
    {
        $defaultCurrencySymbol = (new \eel_accounts\Service\CompanySettingsService())->defaultCurrencySymbol($companySettings);
        $defaultCurrencyLabel = 'GBP - ' . $defaultCurrencySymbol;

        return '<option value="GBP"' . ($selectedCurrency === 'GBP' ? ' selected' : '') . '>' . HelperFramework::escape($defaultCurrencyLabel) . '</option>';
    }

    private function issueDate(mixed $issuedAt): string
    {
        $timestamp = strtotime((string)$issuedAt);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function newShareIssueDate(array $draftShareClass, string $periodEnd): string
    {
        $draftDate = $this->issueDate($draftShareClass['issued_at'] ?? null);
        if ($draftDate !== '') {
            return $draftDate;
        }
        $today = date('Y-m-d');
        return $periodEnd !== '' && $periodEnd < $today ? $periodEnd : $today;
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('incorporation-share-classes')
            ->exportLimit(5000)
            ->empty('No share classes have been recorded yet.')
            ->column(
                'issued_at',
                'Date',
                html: fn(array $row): string => HelperFramework::escape($this->issueDate($row['issued_at'] ?? null)),
                export: fn(array $row): string => $this->issueDate($row['issued_at'] ?? null),
                sort: fn(array $row): string => (string)($row['issued_at'] ?? '')
            )
            ->column(
                'share_class',
                'Class of shares',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['share_class'] ?? '')),
                export: static fn(array $row): string => (string)($row['share_class'] ?? ''),
                sort: true
            )
            ->column(
                'currency',
                'Currency',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['currency'] ?? '')),
                export: static fn(array $row): string => (string)($row['currency'] ?? ''),
                sort: true
            )
            ->column(
                'quantity',
                'Number allotted',
                html: static fn(array $row): string => HelperFramework::escape((string)(int)($row['quantity'] ?? 0)),
                export: static fn(array $row): string => (string)(int)($row['quantity'] ?? 0),
                exportType: 'number',
                sort: static fn(array $row): int => (int)($row['quantity'] ?? 0)
            )
            ->column(
                'allocated',
                'Allocated',
                html: static fn(array $row): string => HelperFramework::escape((string)(int)($row['allocated'] ?? 0)),
                export: static fn(array $row): string => (string)(int)($row['allocated'] ?? 0),
                exportType: 'number',
                sort: static fn(array $row): int => (int)($row['allocated'] ?? 0)
            )
            ->column(
                'aggregate_nominal_value',
                'Aggregate nominal value',
                html: fn(array $row): string => HelperFramework::escape($this->aggregateNominalValue($row)),
                export: fn(array $row): string => $this->aggregateNominalValue($row),
                exportType: 'number',
                sort: fn(array $row): float => (float)$this->aggregateNominalValue($row)
            )
            ->column(
                'total_aggregate_unpaid',
                'Total aggregate unpaid',
                html: fn(array $row): string => HelperFramework::escape($this->totalAggregateUnpaid($row)),
                export: fn(array $row): string => $this->totalAggregateUnpaid($row),
                exportType: 'number',
                sort: fn(array $row): float => (float)$this->totalAggregateUnpaid($row)
            )
            ->column(
                'payment_status',
                'Paid-up status',
                html: fn(array $row): string => $this->paymentStatusBadge($row),
                export: fn(array $row): string => $this->paymentStatusLabel((string)($row['payment_status'] ?? '')),
                sort: fn(array $row): string => $this->paymentStatusLabel((string)($row['payment_status'] ?? ''))
            )
            ->column(
                'document_reference',
                'Source document/reference',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['document_reference'] ?? '')),
                export: static fn(array $row): string => (string)($row['document_reference'] ?? ''),
                sort: true
            )
            ->column(
                'source_note',
                'Prescribed particulars',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['source_note'] ?? '')),
                export: static fn(array $row): string => (string)($row['source_note'] ?? ''),
                sort: true
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->actionsCell((int)($context['company']['id'] ?? 0)),
                exportable: false,
                sort: false,
                cellClass: 'cell-fit'
            );
    }

    private function rows(array $context): array
    {
        $allocatedByClassId = [];
        foreach ((array)($context['services']['ownership']['reconciliation']['rows'] ?? []) as $reconciliationRow) {
            if (!is_array($reconciliationRow)) {
                continue;
            }

            $allocatedByClassId[(int)($reconciliationRow['share_class_id'] ?? 0)] = (int)($reconciliationRow['held_quantity'] ?? 0);
        }

        $shareClasses = array_values(array_filter(
            (array)($context['services']['incorporationShares']['share_classes'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));

        return array_map(
            static function (array $shareClass) use ($allocatedByClassId): array {
                $shareClass['allocated'] = $allocatedByClassId[(int)($shareClass['id'] ?? 0)] ?? 0;

                return $shareClass;
            },
            $shareClasses
        );
    }

    private function actionsCell(int $companyId): string
    {
        if ($companyId <= 0) {
            return '';
        }

        return '<a class="button secondary" href="?page=incorporation&amp;show_card=incorporation_payment_matching">Review payment</a>';
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

    private function paymentStatusBadge(array $row): string
    {
        $status = (string)($row['payment_status'] ?? '');

        return '<span class="badge ' . HelperFramework::escape($this->paymentStatusBadgeClass($status)) . '">'
            . HelperFramework::escape($this->paymentStatusLabel($status))
            . '</span>';
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'payment_matched' => 'Paid-Up',
            'payment_mismatch' => 'Payment mismatch',
            'not_paid_up' => 'Not paid up',
            default => 'Payment not matched',
        };
    }

    private function paymentStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'payment_matched' => 'success',
            'not_paid_up' => 'warning',
            default => 'danger',
        };
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
