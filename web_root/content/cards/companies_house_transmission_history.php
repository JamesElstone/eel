<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_house_transmission_historyCard extends CardBaseFramework
{
    public function key(): string { return 'companies_house_transmission_history'; }

    public function title(): string { return 'Companies House Transmission History'; }

    public function helper(array $context): string
    {
        return 'Submission History covers the current accounting period. XML Exchange History covers every accounting year for the selected company and provides the exact private XML sent or received.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext['companies_house_history'] = [
            'submission_id' => max(0, (int)$request->input('ch_submission_id', 0)),
        ];

        return $pageContext;
    }

    public function services(): array
    {
        return [
            [
                'key' => 'companies_house_submission_history',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'submissionHistory',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
            [
                'key' => 'companies_house_exchange_history',
                'service' => \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class,
                'method' => 'protocolExchangeHistory',
                'params' => [
                    'companyId' => ':company.id',
                    'submissionId' => ':companies_house_history.submission_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['companies.house.accounts.submission', 'page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="notice warning">Select a company and accounting period to review Companies House history.</div>';
        }

        $services = (array)($context['services'] ?? []);
        $submissions = (array)($services['companies_house_submission_history'] ?? []);
        $exchanges = (array)($services['companies_house_exchange_history'] ?? []);
        $selectedSubmissionId = (int)(($context['companies_house_history'] ?? [])['submission_id'] ?? 0);

        return '<div class="settings-stack">'
            . $this->submissionTable($submissions)
            . $this->exchangeTable(
                $companyId,
                $accountingPeriodId,
                $exchanges,
                $selectedSubmissionId
            )
            . '</div>';
    }

    private function submissionTable(array $submissions): string
    {
        if ($submissions === []) {
            return '<section class="panel-soft"><h3 class="card-title">Submission History</h3>'
                . '<div class="helper">No Companies House submission attempts are recorded.</div></section>';
        }
        $rows = '';
        foreach ($submissions as $submission) {
            if (!is_array($submission)) {
                continue;
            }
            $submissionId = (int)($submission['id'] ?? 0);
            $number = trim((string)($submission['submission_number'] ?? ''));
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html($number !== '' ? $number : 'Not sent')
                . '</td><td>' . \eel_accounts\Support\Utf8::html(ucfirst((string)($submission['filing_kind'] ?? 'accounts')))
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)($submission['environment'] ?? ''))
                . '</td><td>' . \eel_accounts\Support\Utf8::html($this->timestamp((string)($submission['prepared_at'] ?? '')))
                . '</td><td>' . \eel_accounts\Support\Utf8::html($this->timestamp((string)($submission['submitted_at'] ?? '')))
                . '</td><td><span class="badge ' . $this->badge((string)($submission['lifecycle'] ?? '')) . '">'
                . \eel_accounts\Support\Utf8::html($this->submissionStatus($submission))
                . '</span></td><td><a class="button button-inline" href="?page=transmit&amp;show_card='
                . 'companies_house_transmission_history&amp;ch_submission_id=' . $submissionId
                . '#companies-house-xml-exchanges">View conversation</a></td></tr>';
        }

        return '<section class="panel-soft"><h3 class="card-title">Submission History</h3>'
            . '<div class="table-scroll"><table><thead><tr><th>Submission</th><th>Filing type</th>'
            . '<th>Environment</th><th>Prepared</th><th>Submitted</th><th>Latest status</th>'
            . '<th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function exchangeTable(
        int $companyId,
        int $accountingPeriodId,
        array $exchanges,
        int $selectedSubmissionId
    ): string {
        $heading = '<div class="status-head"><h3 class="card-title">XML Exchange History</h3>'
            . ($selectedSubmissionId > 0
                ? '<a class="button button-inline" href="?page=transmit&amp;show_card='
                    . 'companies_house_transmission_history#companies-house-xml-exchanges">Show all conversations</a>'
                : '')
            . '</div>';
        $warning = '<div class="notice warning">Exact outbound XML contains presenter and company authentication values. '
            . 'Downloads are private, integrity-checked and not cached.</div>';
        if ($exchanges === []) {
            return '<section class="panel-soft" id="companies-house-xml-exchanges">' . $heading . $warning
                . '<div class="helper">No Companies House XML exchanges are recorded'
                . ($selectedSubmissionId > 0 ? ' for this submission' : '') . '.</div></section>';
        }

        $rows = '';
        foreach ($exchanges as $exchange) {
            if (!is_array($exchange)) {
                continue;
            }
            $number = trim((string)($exchange['submission_number'] ?? ''));
            $httpStatus = (int)($exchange['response_status_code'] ?? 0);
            $outcome = trim((string)($exchange['display_outcome'] ?? ''));
            if ($httpStatus > 0) {
                $outcome .= ($outcome !== '' ? ' · ' : '') . 'HTTP ' . $httpStatus;
            }
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html($number !== '' ? $number : 'Not allocated')
                . '</td><td>' . \eel_accounts\Support\Utf8::html(
                    $this->operationLabel((string)($exchange['operation'] ?? ''))
                )
                . '</td><td>' . \eel_accounts\Support\Utf8::html((string)($exchange['transaction_id'] ?? ''))
                . '</td><td>' . \eel_accounts\Support\Utf8::html(
                    $exchange['sent_at'] !== null
                        ? $this->timestamp((string)$exchange['sent_at'])
                        : 'Not sent'
                )
                . '</td><td>' . $this->downloadButton(
                    $companyId,
                    $accountingPeriodId,
                    (int)$exchange['id'],
                    'request',
                    !empty($exchange['request_available'])
                )
                . '</td><td>' . \eel_accounts\Support\Utf8::html(
                    $exchange['received_at'] !== null
                        ? $this->timestamp((string)$exchange['received_at'])
                        : '—'
                )
                . '</td><td>' . $this->downloadButton(
                    $companyId,
                    $accountingPeriodId,
                    (int)$exchange['id'],
                    'response',
                    !empty($exchange['response_available'])
                )
                . '</td><td><span class="badge ' . $this->badge((string)($exchange['exchange_state'] ?? '')) . '">'
                . \eel_accounts\Support\Utf8::html($outcome !== '' ? $outcome : 'Unknown')
                . '</span></td></tr>';
        }

        return '<section class="panel-soft" id="companies-house-xml-exchanges">' . $heading . $warning
            . '<div class="table-scroll"><table><thead><tr><th>Submission</th><th>Operation</th>'
            . '<th>Transaction ID</th><th>Sent</th><th>Request XML</th><th>Received</th>'
            . '<th>Response XML</th><th>Outcome</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private function downloadButton(
        int $companyId,
        int $accountingPeriodId,
        int $exchangeId,
        string $direction,
        bool $available
    ): string {
        if (!$available) {
            return '—';
        }
        $message = $direction === 'request'
            ? 'This exact outbound XML contains authentication values. Download it?'
            : 'Download the exact received Companies House XML?';

        return '<form method="post" action="?page=transmit" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="download_protocol_evidence">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="exchange_id" value="' . $exchangeId . '">'
            . '<input type="hidden" name="direction" value="' . $direction . '">'
            . '<button class="button button-inline" type="submit" data-chicken-check="true" '
            . 'data-chicken-message="' . \eel_accounts\Support\Utf8::html($message) . '" '
            . 'data-chicken-confirm-text="Download XML">Download</button></form>';
    }

    private function submissionStatus(array $submission): string
    {
        $acknowledgement = strtolower(trim((string)($submission['pending_acknowledgement_state'] ?? '')));
        $pendingStatus = strtolower(trim((string)($submission['pending_normalized_status'] ?? '')));
        if ($pendingStatus !== '' && in_array($acknowledgement, ['required', 'sending', 'failed', 'transport_unknown'], true)) {
            return HelperFramework::labelFromKey($pendingStatus, '_')
                . ' — acknowledgement ' . HelperFramework::labelFromKey($acknowledgement, '_');
        }

        return HelperFramework::labelFromKey((string)($submission['lifecycle'] ?? 'unknown'), '_');
    }

    private function operationLabel(string $operation): string
    {
        return strtolower(trim($operation)) === 'company_data'
            ? 'Company authentication check'
            : HelperFramework::labelFromKey($operation, '_');
    }

    private function timestamp(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('d/m/Y H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    private function badge(string $state): string
    {
        return match (strtolower(trim($state))) {
            'accepted', 'succeeded', 'verified', 'acknowledged' => 'success',
            'rejected', 'failed', 'internal_failure', 'evidence_incomplete' => 'danger',
            'transport_unknown', 'parked' => 'warning',
            'prepared', 'sent', 'received', 'pending', 'submitting' => 'info',
            default => 'muted',
        };
    }
}
