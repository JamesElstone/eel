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
            . $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ])
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

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('incorporation-share-classes')
            ->exportLimit(5000)
            ->empty('No share classes have been recorded yet.')
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
        return array_values(array_filter(
            (array)($context['services']['incorporationShares']['share_classes'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
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
            'payment_matched' => 'Payment matched',
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
