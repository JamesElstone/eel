<?php
declare(strict_types=1);

final class _hmrc_submission_historyCard extends CardBaseFramework
{
    public function key(): string { return 'hmrc_submission_history'; }

    public function title(): string { return 'Submission History'; }

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
        $rows = (array)($data['history'] ?? []);
        $csrf = (string)($context['page']['csrf_token'] ?? '');
        if ($rows === []) {
            return '<div class="helper">No CT600 packages or HMRC exchanges have been recorded for this accounting period.</div>';
        }

        $html = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $environment = strtoupper(trim((string)($row['environment'] ?? $row['mode'] ?? 'TEST')));
            $protocol = strtolower(trim((string)($row['protocol_state'] ?? $row['status'] ?? '')));
            $outcome = strtolower(trim((string)($row['business_outcome'] ?? $row['outcome'] ?? '')));
            if ($outcome === 'none') {
                $outcome = '';
            }
            if ($outcome === '' && in_array($protocol, ['accepted', 'rejected'], true)) {
                $outcome = $protocol;
            }
            $reference = trim((string)($row['hmrc_submission_reference'] ?? $row['submission_reference'] ?? ''));
            $correlation = trim((string)($row['hmrc_correlation_id'] ?? $row['correlation_id'] ?? ''));
            $downloads = $this->downloads($row, $csrf);
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($row['created_at'] ?? '')) . '</td>
                <td>' . HelperFramework::escape('CT Period ' . (int)($row['ct_period_sequence_no'] ?? 0) . ': ' . (string)($row['period_start'] ?? '') . ' to ' . (string)($row['period_end'] ?? '')) . '</td>
                <td><span class="badge ' . $this->environmentBadge($environment) . '">' . HelperFramework::escape($environment) . '</span></td>
                <td><span class="badge ' . $this->stateBadge($protocol) . '">' . HelperFramework::escape($this->label($protocol)) . '</span></td>
                <td><span class="badge ' . $this->stateBadge($outcome) . '">' . HelperFramework::escape($this->label($outcome !== '' ? $outcome : 'pending')) . '</span></td>
                <td>' . HelperFramework::escape($reference !== '' ? $reference : $correlation) . '</td>
                <td>' . HelperFramework::escape((string)($row['submitted_by'] ?? $row['declaration_approved_by'] ?? $row['approved_by'] ?? '')) . '</td>
                <td>' . $downloads . '</td>
            </tr>';
        }

        return '<div class="settings-stack">
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Created</th><th>Return</th><th>Environment</th><th>Protocol</th><th>Outcome</th><th>HMRC reference</th><th>Actor</th><th>Artifacts</th></tr></thead><tbody>' . $html . '</tbody></table></div>
            <div class="helper">TEST and TIL results are retained as assurance evidence but never count as statutory filing. Only a final LIVE acceptance completes a CT period.</div>
        </div>';
    }

    private function label(string $state): string
    {
        return $state === '' ? 'None' : HelperFramework::labelFromKey($state, '_');
    }

    private function downloads(array $row, string $csrf): string
    {
        $available = [
            'ct600' => !empty($row['ct600_xml_path']),
            'accounts' => !empty($row['accounts_ixbrl_path']),
            'computations' => !empty($row['computations_ixbrl_path']),
            'manifest' => !empty($row['manifest_path']),
            'receipt' => !empty($row['response_body_path']),
        ];
        $labels = [
            'ct600' => 'CT600',
            'accounts' => 'Accounts',
            'computations' => 'Computations',
            'manifest' => 'Manifest',
            'receipt' => 'Receipt',
        ];
        $forms = '';
        foreach ($available as $artifact => $present) {
            if (!$present) {
                continue;
            }
            $forms .= '<form method="post" action="?page=hmrc_submission" class="mini-form">'
                . '<input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrf) . '">'
                . '<input type="hidden" name="card_action" value="HmrcSubmission">'
                . '<input type="hidden" name="intent" value="download_ct600_artifact">'
                . '<input type="hidden" name="ct_period_id" value="' . (int)($row['ct_period_id'] ?? 0) . '">'
                . '<input type="hidden" name="submission_id" value="' . (int)($row['id'] ?? 0) . '">'
                . '<input type="hidden" name="artifact" value="' . HelperFramework::escape($artifact) . '">'
                . '<button class="button" type="submit">' . HelperFramework::escape($labels[$artifact]) . '</button>'
                . '</form>';
        }

        return $forms !== '' ? '<div class="actions-row">' . $forms . '</div>' : '-';
    }

    private function environmentBadge(string $environment): string
    {
        return match ($environment) {
            'LIVE' => 'danger',
            'TIL' => 'warning',
            default => 'info',
        };
    }

    private function stateBadge(string $status): string
    {
        return match ($status) {
            'accepted', 'live_accepted', 'sandbox_passed', 'til_validated', 'deleted', 'closed', 'prepared', 'ready' => 'success',
            'rejected', 'error', 'failed', 'validation_failed', 'transport_unknown', 'transport_uncertain' => 'danger',
            'acknowledged', 'awaiting_poll', 'polling', 'final_received', 'delete_pending', 'cleanup_pending' => 'warning',
            default => 'muted',
        };
    }
}
