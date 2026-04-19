<?php
declare(strict_types=1);

final class _transaction_category_audit_logCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'transaction_category_audit_log';
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
        $rows = (array)($context['page']['transaction_audit_rows'] ?? []);
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

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Transaction Category Audit</h2>
                </div>
            </div>
            <div class="card-body stack">
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
            </div>
        </div>';
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
