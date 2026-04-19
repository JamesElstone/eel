<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end_audit_logCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'year_end_audit_log';
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
        $rows = (array)($context['page']['year_end_audit_rows'] ?? []);
        $tableRows = '';

        foreach ($rows as $row) {
            $period = $this->periodLabel($row);
            $changes = $this->jsonSummary((string)($row['old_value_json'] ?? ''), (string)($row['new_value_json'] ?? ''));
            $notes = trim((string)($row['notes'] ?? ''));

            $detailParts = [];
            if ($changes !== '') {
                $detailParts[] = $changes;
            }
            if ($notes !== '') {
                $detailParts[] = 'Notes: ' . $notes;
            }

            $tableRows .= '<tr>
                <td>' . HelperFramework::escape((string)($row['action_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['company_name'] ?? '')) . '<div class="helper">' . HelperFramework::escape($period) . '</div></td>
                <td><span class="badge info">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($row['action'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['action_by'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(implode(' | ', $detailParts)) . '</td>
            </tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td colspan="5">No year-end audit events have been recorded yet.</td></tr>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Year End Audit</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Recent year-end review, locking, unlocking, and notes changes recorded by the system.</p>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Company / Period</th>
                                <th>Action</th>
                                <th>By</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>' . $tableRows . '</tbody>
                    </table>
                </div>
            </div>
        </div>';
    }

    private function periodLabel(array $row): string
    {
        $start = trim((string)($row['tax_year_start'] ?? ''));
        $end = trim((string)($row['tax_year_end'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        return 'Tax year #' . (string)($row['tax_year_id'] ?? '');
    }

    private function jsonSummary(string $oldValueJson, string $newValueJson): string
    {
        $parts = [];
        $oldSummary = $this->flattenJson($oldValueJson);
        $newSummary = $this->flattenJson($newValueJson);

        if ($oldSummary !== '') {
            $parts[] = 'Old: ' . $oldSummary;
        }

        if ($newSummary !== '') {
            $parts[] = 'New: ' . $newSummary;
        }

        return implode(' | ', $parts);
    }

    private function flattenJson(string $json): string
    {
        $json = trim($json);
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return '';
        }

        $parts = [];
        foreach ($decoded as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string)$key) . ': ' . (string)$value;
        }

        return implode(', ', $parts);
    }
}
