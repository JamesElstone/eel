<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transaction_category_audit_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'transaction_category_audit_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'transaction_audit_rows',
                'service' => \eel_accounts\Repository\AccountingAuditRepository::class,
                'method' => 'fetchRecentTransactionCategoryAudit',
                'params' => [
                    'limit' => 200,
                ],
            ],
        ];
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = $this->applyPaginationContext($request, $pageContext);

        return TransactionAction::withTransactionCardContext($request, $services, $pageContext, $actionResult);
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $rows = (array)($context['services']['transaction_audit_rows'] ?? []);
        $pagination = HelperFramework::paginateArray(
            $rows,
            $this->paginationPage($context),
            self::PAGE_SIZE
        );
        $rows = (array)$pagination['items'];
        $tableRows = '';

        foreach ($rows as $row) {
            $transactionSummary = trim((string)($row['transaction_description'] ?? ''));
            if ($transactionSummary === '') {
                $transactionSummary = 'Transaction #' . (string)($row['transaction_id'] ?? '');
            }

            $detail = 'From ' . $this->valueLabel((string)($row['old_nominal_name'] ?? ''), (string)($row['old_category_status'] ?? ''), (int)($row['old_is_auto_excluded'] ?? 0));
            $detail .= ' to ' . $this->valueLabel((string)($row['new_nominal_name'] ?? ''), (string)($row['new_category_status'] ?? ''), (int)($row['new_is_auto_excluded'] ?? 0));

            $reason = trim((string)($row['reason'] ?? ''));
            if ($reason !== '') {
                $detail .= '<div class="helper">' . HelperFramework::escape($reason) . '</div>';
            }

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['changed_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['changed_by'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($transactionSummary) . '<div class="helper">ID ' . HelperFramework::escape((string)($row['transaction_id'] ?? '')) . '</div></td>
                <td>' . $detail . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="4">No transaction categorisation audit events have been recorded yet.</td></tr>';
        }

        return '
            <p class="helper">Recent categorisation changes for transactions, including old and new accounting treatment.</p>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Changed By</th>
                            <th>Transaction</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>' . $tableRows . '</tbody>
                </table>
            </div>
            ' . $this->paginationControls($context, $pagination, 'Transaction audit events') . '
        ';
    }

    private function valueLabel(string $nominalName, string $status, int $isAutoExcluded): string
    {
        $parts = [];

        $nominalName = trim($nominalName);
        if ($nominalName !== '') {
            $parts[] = $nominalName;
        }

        $status = trim($status);
        if ($status !== '') {
            $parts[] = str_replace('_', ' ', $status);
        }

        if ($isAutoExcluded === 1) {
            $parts[] = 'auto excluded';
        }

        if ($parts === []) {
            return 'not set';
        }

        return HelperFramework::escape(implode(' | ', $parts));
    }
}
