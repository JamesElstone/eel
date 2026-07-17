<?php
declare(strict_types=1);

final class _hmrc_submission_logCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_log'; }

    public function title(): string { return 'Submission Events'; }

    public function services(): array
    {
        return [[
            'key' => 'hmrc_submission',
            'service' => \eel_accounts\Service\HmrcCtSubmissionReadModel::class,
            'method' => 'pageState',
            'params' => [
                'companyId' => ':company.id',
                'accountingPeriodId' => ':company.accounting_period_id',
                'selectedCtPeriodId' => ':hmrc_submission_selection.selected_ct_period_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['hmrc.submission', 'page.context'];
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['hmrc_submission'] ?? $context['hmrc_submission'] ?? []);
        $events = (array)($data['events'] ?? []);
        $latest = (array)($data['latest_submission'] ?? []);
        if ($events === []) {
            return '<div class="helper">No events have been recorded for the selected CT period. Prepare a package to start an immutable audit trail.</div>';
        }

        $rows = '';
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $level = strtolower(trim((string)($event['event_level'] ?? 'info')));
            $details = $this->eventDetails((string)($event['event_context_json'] ?? ''));
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($event['created_at'] ?? '')) . '</td>
                <td><span class="badge ' . $this->badge($level) . '">' . HelperFramework::escape($level) . '</span></td>
                <td>' . HelperFramework::escape((string)($event['event_message'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($details !== '' ? $details : '-') . '</td>
            </tr>';
        }

        return '<div class="settings-stack">
            <div class="summary-grid">
                ' . $this->metric('Submission', '#' . (int)($latest['id'] ?? 0)) . '
                ' . $this->metric('Transaction ID', (string)($latest['transaction_id'] ?? '-')) . '
                ' . $this->metric('Correlation ID', (string)($latest['hmrc_correlation_id'] ?? $latest['correlation_id'] ?? '-')) . '
                ' . $this->metric('Next poll', (string)($latest['next_poll_at'] ?? '-')) . '
            </div>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Structured detail</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
            <div class="helper">Credentials, authenticated GovTalk headers, raw secrets, and local artifact paths are intentionally excluded from this view.</div>
        </div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<div class="summary-card"><div class="summary-label">' . HelperFramework::escape($label) . '</div><div class="summary-value">' . HelperFramework::escape($value !== '' ? $value : '-') . '</div></div>';
    }

    private function badge(string $level): string
    {
        return match ($level) {
            'success' => 'success',
            'warning' => 'warning',
            'error' => 'danger',
            default => 'info',
        };
    }

    private function eventDetails(string $json): string
    {
        if (trim($json) === '') {
            return '';
        }
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '';
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        $walk = static function (mixed $item, string $prefix = '') use (&$walk, &$parts): void {
            if (count($parts) >= 12) {
                return;
            }
            if (is_array($item)) {
                foreach ($item as $key => $child) {
                    $key = is_string($key) ? HelperFramework::labelFromKey($key, '_') : '';
                    $walk($child, $key !== '' ? $key : $prefix);
                }
                return;
            }
            if (is_scalar($item) && trim((string)$item) !== '') {
                $parts[] = ($prefix !== '' ? $prefix . ': ' : '') . trim((string)$item);
            }
        };
        $walk($value);

        return implode('; ', $parts);
    }
}
