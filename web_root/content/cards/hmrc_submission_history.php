<?php
declare(strict_types=1);

final class _hmrc_submission_historyCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_history'; }
    public function title(): string { return 'Submission History'; }
    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $rows = (array)($context['hmrc_submission']['history'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No HMRC CT600 submission attempts have been recorded yet.</div>';
        }
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($row['created_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['company_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['period_start'] ?? '') . ' to ' . (string)($row['period_end'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['mode'] ?? '')) . '</td>
                <td><span class="badge ' . HelperFramework::escape($this->badge((string)($row['status'] ?? ''))) . '">' . HelperFramework::escape((string)($row['status'] ?? '')) . '</span></td>
                <td>' . HelperFramework::escape((string)($row['hmrc_submission_reference'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['hmrc_response_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['submitted_by'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->actions($row)) . '</td>
            </tr>';
        }

        return '<div class="table-scroll"><table class="data-table"><thead><tr><th>Date</th><th>Company</th><th>Period</th><th>Mode</th><th>Status</th><th>Reference</th><th>Code</th><th>Submitted by</th><th>Actions</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }

    private function badge(string $status): string
    {
        return match ($status) {
            'accepted', 'ready' => 'success',
            'rejected', 'failed', 'validation_failed' => 'danger',
            'submitting', 'validating' => 'warning',
            default => 'muted',
        };
    }

    private function actions(array $row): string
    {
        $items = [];
        if (trim((string)($row['request_body_path'] ?? '')) !== '') {
            $items[] = 'package stored';
        }
        if (trim((string)($row['response_body_path'] ?? '')) !== '') {
            $items[] = 'response stored';
        }

        return $items === [] ? 'Audit row only' : implode(', ', $items);
    }
}
