<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

class _govtalk_transmission_historyCard extends CardBaseFramework
{
    private const EXCHANGE_PAGE_SIZE = 10;

    public function key(): string { return 'govtalk_transmission_history'; }

    public function title(): string { return 'Submission History'; }

    public function helper(array $context): string
    {
        return 'Submission History shows HMRC and Companies House filings for the current accounting period.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $authority = strtolower(trim((string)$request->input('history_authority', '')));
        $environment = strtoupper(trim((string)$request->input('history_environment', '')));
        $conversationAuthority = strtolower(trim((string)$request->input(
            'history_conversation_authority',
            ''
        )));
        $pageContext['govtalk_history'] = [
            'authority' => in_array($authority, ['companies_house', 'hmrc'], true)
                ? $authority
                : '',
            'environment' => in_array($environment, ['TEST', 'TIL', 'LIVE'], true)
                ? $environment
                : '',
            'conversation_authority' => in_array(
                $conversationAuthority,
                ['companies_house', 'hmrc'],
                true
            ) ? $conversationAuthority : '',
            'conversation_id' => max(0, (int)$request->input(
                'history_conversation_id',
                0
            )),
        ];

        return $pageContext;
    }

    public function services(): array
    {
        return [
            [
                'key' => 'govtalk_submission_history',
                'service' => \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
                'method' => 'submissionHistory',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'authority' => ':govtalk_history.authority',
                    'environment' => ':govtalk_history.environment',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'companies.house.accounts.submission',
            'hmrc.ct600.submissions',
            'page.context',
        ];
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
            return '<div class="notice warning">Select a company and accounting period to review transmission history.</div>';
        }

        $services = (array)($context['services'] ?? []);
        $submissions = (array)($services['govtalk_submission_history'] ?? []);

        $developerOptions = (bool)AppConfigurationStore::get('developer_options', false);

        return '<div class="settings-stack">'
            . $this->submissionTable(
                $submissions,
                $companyId,
                $accountingPeriodId,
                $developerOptions
            )
            . '</div>';
    }

    private function submissionTable(
        array $submissions,
        int $companyId,
        int $accountingPeriodId,
        bool $developerOptions
    ): string
    {
        if ($submissions === []) {
            return '<section class="panel-soft">'
                . '<div class="helper">No HMRC or Companies House submission attempts are recorded for the current accounting period.</div></section>';
        }
        $rows = '';
        foreach ($submissions as $submission) {
            if (!is_array($submission)) {
                continue;
            }
            $submissionId = (int)($submission['conversation_id'] ?? 0);
            $authority = (string)($submission['authority'] ?? '');
            $statusKey = strtolower(trim((string)($submission['status_key'] ?? '')));
            $statusAction = '';
            if ($authority === 'companies_house' && $statusKey === 'pending') {
                $statusAction = $this->checkCompaniesHouseSubmissionStatusButton(
                    $companyId,
                    $accountingPeriodId,
                    $submissionId
                );
            } elseif ($authority === 'hmrc'
                && in_array($statusKey, ['awaiting_poll', 'delete_pending'], true)) {
                $statusAction = $this->checkHmrcSubmissionStatusButton(
                    $companyId,
                    $accountingPeriodId,
                    (int)($submission['ct_period_id'] ?? 0),
                    $submissionId
                );
            }
            $reprocessAction = $authority === 'hmrc'
                && $developerOptions
                && !empty($submission['response_reprocess_available'])
                && (int)($submission['response_reprocess_exchange_id'] ?? 0) > 0
                ? $this->reprocessHmrcResponseButton(
                    $companyId,
                    $accountingPeriodId,
                    (int)($submission['ct_period_id'] ?? 0),
                    $submissionId,
                    (int)$submission['response_reprocess_exchange_id']
                )
                : '';
            $rows .= '<tr><td>' . \eel_accounts\Support\Utf8::html(
                    (string)($submission['authority_label'] ?? '')
                )
                . '</td><td>' . $this->submissionReference(
                    (string)($submission['submission_reference'] ?? ''),
                    $authority === 'hmrc'
                        ? (string)($submission['hmrc_document_reference'] ?? '')
                        : ''
                )
                . '</td><td>' . \eel_accounts\Support\Utf8::html(
                    (string)($submission['filing_context'] ?? '')
                )
                . '<div class="helper">' . \eel_accounts\Support\Utf8::html(
                    (string)($submission['filing_type'] ?? '')
                )
                . '</div></td><td>' . \eel_accounts\Support\Utf8::html(
                    (string)($submission['environment'] ?? '')
                )
                . '</td><td>' . $this->identifierPair(
                    (string)($submission['transaction_id'] ?? ''),
                    $authority === 'hmrc' ? (string)($submission['correlation_id'] ?? '') : ''
                )
                . '</td><td>' . \eel_accounts\Support\Utf8::html($this->timestamp((string)($submission['prepared_at'] ?? '')))
                . '</td><td>' . \eel_accounts\Support\Utf8::html($this->timestamp((string)($submission['submitted_at'] ?? '')))
                . '</td><td><span class="badge ' . $this->badge((string)(
                    $submission['status_tone'] ?? $submission['status_key'] ?? ''
                )) . '">'
                . \eel_accounts\Support\Utf8::html(
                    (string)($submission['latest_status'] ?? 'Unknown')
                )
                . '</span></td><td><form method="post" action="?page=transmit" data-ajax="true">'
                . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
                . '<input type="hidden" name="page" value="transmit">'
                . '<input type="hidden" name="show_card" value="govtalk_transmission_history">'
                . '<input type="hidden" name="_card_refresh" value="1">'
                . '<input type="hidden" name="_invalidate_fact" value="govtalk.exchanges.selection">'
                . '<input type="hidden" name="cards[]" value="govtalk_exchanges">'
                . '<input type="hidden" name="history_conversation_authority" value="'
                . \eel_accounts\Support\Utf8::html($authority) . '">'
                . '<input type="hidden" name="history_conversation_id" value="' . $submissionId . '">'
                . '<button class="button primary" type="submit">View conversation</button></form>'
                . $statusAction . $reprocessAction . '</td></tr>';
        }

        return '<section class="panel-soft">'
            . '<div class="table-scroll"><table><thead><tr><th>Authority</th><th>Submission</th>'
            . '<th>Filing / Period</th><th>Environment</th><th>Transaction / Correlation ID</th><th>Prepared</th>'
            . '<th>Submitted</th><th>Latest status</th>'
            . '<th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function checkCompaniesHouseSubmissionStatusButton(
        int $companyId,
        int $accountingPeriodId,
        int $submissionId
    ): string {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $submissionId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="CompaniesHouseAccounts">'
            . '<input type="hidden" name="intent" value="refresh_accounts_status">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button primary" type="submit">Check Submission Status</button></form>';
    }

    private function checkHmrcSubmissionStatusButton(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $submissionId
    ): string {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || $ctPeriodId <= 0
            || $submissionId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="HmrcSubmission">'
            . '<input type="hidden" name="intent" value="hmrc_poll">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">'
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<button class="button primary" type="submit">Check Submission Status</button></form>';
    }

    private function reprocessHmrcResponseButton(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $submissionId,
        int $exchangeId
    ): string {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || $ctPeriodId <= 0
            || $submissionId <= 0
            || $exchangeId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=transmit" data-ajax="true" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="HmrcSubmission">'
            . '<input type="hidden" name="intent" value="hmrc_reprocess_response">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">'
            . '<input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">'
            . '<input type="hidden" name="submission_id" value="' . $submissionId . '">'
            . '<input type="hidden" name="exchange_id" value="' . $exchangeId . '">'
            . '<button class="button button-inline danger" type="submit" title="Developer only" '
            . 'data-chicken-check="true" data-chicken-title="Reprocess archived HMRC response" '
            . 'data-chicken-message="Reprocess this archived HMRC response locally and apply its strictly verified protocol result?'
            . '&lt;br&gt;&lt;br&gt;Nothing will be sent to HMRC by this action." '
            . 'data-chicken-confirm-text="Reprocess Response" '
            . 'data-chicken-button-class="button danger">Reprocess Response</button></form>';
    }

    protected function exchangeTable(
        array $context,
        int $companyId,
        array $exchanges,
        array $history
    ): string {
        $selectedConversationId = (int)($history['conversation_id'] ?? 0);
        $heading = '<div class="status-head">'
            . ($selectedConversationId > 0
                ? '<a class="button button-inline" href="?page=transmit&amp;show_card='
                . $this->key() . '#govtalk-xml-exchanges">Show all conversations</a>'
                : '')
            . '</div>';
        $warning = '';
        $table = $this->exchangeHistoryTable($companyId, $exchanges, $history);
        $pagination = HelperFramework::paginateArray(
            $table->sortedRows(),
            $this->paginationPage($context),
            self::EXCHANGE_PAGE_SIZE
        );
        $hiddenFields = [
            'page' => 'transmit',
            'show_card' => $this->key(),
            'history_authority' => (string)($history['authority'] ?? ''),
            'history_environment' => (string)($history['environment'] ?? ''),
            'history_conversation_authority' => (string)($history['conversation_authority'] ?? ''),
            'history_conversation_id' => (string)($history['conversation_id'] ?? 0),
            '_pagination' => '1',
            '_invalidate_fact' => (string)($this->invalidationFacts()[0] ?? 'page.context'),
            'cards[]' => [$this->key()],
        ];

        return '<section class="panel-soft" id="govtalk-xml-exchanges">' . $heading . $warning
            . $table->visibleRows((array)$pagination['items'])
                ->pagination($pagination, 'XML exchanges', $this->paginationPageField(), $hiddenFields)
                ->render($context, $hiddenFields)
            . '</section>';
    }

    protected function exchangeHistoryTable(int $companyId, array $exchanges, array $history): TableFramework
    {
        $empty = 'No GovTalk XML exchanges are recorded'
            . ((int)($history['conversation_id'] ?? 0) > 0 ? ' for this submission.' : '.');

        return \eel_accounts\Support\Utf8Table::make('govtalk_xml_exchange_history', $exchanges)
            ->filename('govtalk-xml-exchange-history')
            ->exports(true)
            ->exportLimit(1000)
            ->empty($empty)
            ->classes(wrapperClass: 'table-scroll')
            ->toolbarActions($this->exchangeFilters($history))
            ->textColumn('authority_label', 'Authority')
            ->column(
                'submission_reference',
                'Submission',
                html: fn(array $exchange): string => $this->submissionReference(
                    (string)($exchange['submission_reference'] ?? ''),
                    (string)($exchange['hmrc_document_reference'] ?? '')
                ),
                export: static function (array $exchange): string {
                    $internal = trim((string)($exchange['submission_reference'] ?? ''));
                    $remote = trim((string)($exchange['hmrc_document_reference'] ?? ''));
                    return implode("\n", array_filter([
                        $internal,
                        $remote !== '' ? 'HMRC ref ' . $remote : '',
                    ], static fn(string $value): bool => $value !== ''));
                }
            )
            ->column(
                'operation',
                'Operation',
                html: static function (array $exchange): string {
                    $details = implode(' · ', array_filter([
                        trim((string)($exchange['request_qualifier'] ?? '')),
                        trim((string)($exchange['request_function'] ?? '')),
                    ], static fn(string $value): bool => $value !== ''));
                    return \eel_accounts\Support\Utf8::html((string)($exchange['request_message_class'] ?? ''))
                        . ($details !== ''
                            ? '<div class="helper">' . \eel_accounts\Support\Utf8::html($details) . '</div>'
                            : '');
                },
                export: static fn(array $exchange): string => (string)($exchange['request_message_class'] ?? '')
            )
            ->column(
                'transaction_id',
                'Transaction / Correlation ID',
                html: static function (array $exchange): string {
                    $transactionId = trim((string)($exchange['transaction_id'] ?? ''));
                    $correlationId = trim((string)($exchange['correlation_id'] ?? ''));
                    return \eel_accounts\Support\Utf8::html($transactionId !== '' ? $transactionId : '—')
                        . ($correlationId !== ''
                            ? '<div class="helper">'
                                . \eel_accounts\Support\Utf8::html($correlationId) . '</div>'
                            : '');
                },
                export: static function (array $exchange): string {
                    return implode("\n", array_filter([
                        trim((string)($exchange['transaction_id'] ?? '')),
                        trim((string)($exchange['correlation_id'] ?? '')),
                    ], static fn(string $value): bool => $value !== ''));
                }
            )
            ->column(
                'sent_at',
                'Sent',
                html: fn(array $exchange): string => ($exchange['sent_at'] ?? null) !== null
                    ? \eel_accounts\Support\Utf8::html($this->timestamp((string)$exchange['sent_at']))
                    : 'Not sent',
                export: static fn(array $exchange): string => (string)($exchange['sent_at'] ?? '')
            )
            ->column('request_xml', 'Request XML', html: fn(array $exchange): string => $this->downloadButton($companyId, (int)($exchange['id'] ?? 0), 'request', !empty($exchange['request_available'])), exportable: false)
            ->column(
                'received_at',
                'Received',
                html: fn(array $exchange): string => ($exchange['received_at'] ?? null) !== null
                    ? \eel_accounts\Support\Utf8::html($this->timestamp((string)$exchange['received_at']))
                    : '—',
                export: static fn(array $exchange): string => (string)($exchange['received_at'] ?? '')
            )
            ->column('response_xml', 'Response XML', html: fn(array $exchange): string => $this->downloadButton($companyId, (int)($exchange['id'] ?? 0), 'response', !empty($exchange['response_available'])), exportable: false)
            ->column('http_status', 'HTTP Response Code', html: static fn(array $exchange): string => \eel_accounts\Support\Utf8::html(trim((string)($exchange['display_http_status'] ?? '')) ?: '—'), export: static fn(array $exchange): string => (string)($exchange['display_http_status'] ?? ''))
            ->column(
                'govtalk_errors',
                'GovTalk Errors',
                html: fn(array $exchange): string => $this->govTalkErrors(
                    (array)($exchange['govtalk_errors'] ?? [])
                ),
                export: fn(array $exchange): string => $this->govTalkErrorsExport(
                    (array)($exchange['govtalk_errors'] ?? [])
                )
            )
            ->column(
                'outcome',
                'Outcome',
                html: fn(array $exchange): string => '<span class="badge '
                    . $this->badge((string)($exchange['exchange_state'] ?? '')) . '">'
                    . \eel_accounts\Support\Utf8::html(trim((string)($exchange['display_outcome'] ?? '')) ?: 'Unknown')
                    . '</span>',
                export: static fn(array $exchange): string => trim((string)($exchange['display_outcome'] ?? '')) ?: 'Unknown'
            );
    }

    protected function downloadButton(
        int $companyId,
        int $exchangeId,
        string $direction,
        bool $available
    ): string {
        if (!$available) {
            return '—';
        }
        $message = $direction === 'request'
            ? 'This exact outbound XML contains authentication values. Download it?'
            : 'Download the exact received GovTalk XML?';

        return '<form method="post" action="?page=transmit" class="actions-row">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="GovTalkTransmissionHistory">'
            . '<input type="hidden" name="intent" value="download_protocol_evidence">'
            . '<input type="hidden" name="company_id" value="' . $companyId . '">'
            . '<input type="hidden" name="exchange_id" value="' . $exchangeId . '">'
            . '<input type="hidden" name="direction" value="' . $direction . '">'
            . '<button class="button button-inline" type="submit" data-chicken-check="true" '
            . 'data-chicken-message="' . \eel_accounts\Support\Utf8::html($message) . '" '
            . 'data-chicken-confirm-text="Download XML">Download</button></form>';
    }

    protected function exchangeFilters(array $history): string
    {
        $authority = (string)($history['authority'] ?? '');
        $environment = (string)($history['environment'] ?? '');
        $option = static function (
            string $value,
            string $label,
            string $selected
        ): string {
            return '<option value="' . \eel_accounts\Support\Utf8::html($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>'
                . \eel_accounts\Support\Utf8::html($label) . '</option>';
        };

        return '<div class="toolbar govtalk-xml-exchange-filter-controls">'
            . '<form method="get" action="">'
            . '<input type="hidden" name="page" value="transmit">'
            . '<input type="hidden" name="show_card" value="' . $this->key() . '">'
            . '<div class="form-row table-filter-row"><label for="table-filter-govtalk_xml_exchange_history-authority">Authority</label>'
            . '<select class="selector-input" id="table-filter-govtalk_xml_exchange_history-authority" name="history_authority">'
            . $option('', 'All authorities', $authority)
            . $option('companies_house', 'Companies House', $authority)
            . $option('hmrc', 'HMRC', $authority)
            . '</select></div><div class="form-row table-filter-row"><label for="table-filter-govtalk_xml_exchange_history-environment">Environment</label>'
            . '<select class="selector-input" id="table-filter-govtalk_xml_exchange_history-environment" name="history_environment">'
            . $option('', 'All environments', $environment)
            . $option('TEST', 'TEST', $environment)
            . $option('TIL', 'TIL', $environment)
            . $option('LIVE', 'LIVE', $environment)
            . '</select></div>'
            . '<button class="button primary" type="submit">Apply Filters</button></form>'
            . '<form method="post" action="?page=transmit" data-ajax="true">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="page" value="transmit">'
            . '<input type="hidden" name="show_card" value="' . $this->key() . '">'
            . '<input type="hidden" name="_card_refresh" value="1">'
            . '<input type="hidden" name="_invalidate_fact" value="govtalk.exchanges.selection">'
            . '<input type="hidden" name="cards[]" value="' . $this->key() . '">'
            . '<button class="button primary" type="submit">Clear filters</button></form></div>';
    }

    protected function govTalkErrors(array $errors): string
    {
        if ($errors === []) {
            return '—';
        }
        $items = '';
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $number = trim((string)($error['number'] ?? ''));
            $texts = array_values(array_filter(
                array_map('strval', (array)($error['texts'] ?? [])),
                static fn(string $text): bool => trim($text) !== ''
            ));
            $title = implode(' — ', array_filter([
                $number,
                implode('; ', $texts),
            ], static fn(string $part): bool => $part !== ''));
            if ($title === '') {
                $title = 'GovTalk error';
            }
            $details = [];
            $type = trim((string)($error['type'] ?? ''));
            if ($type !== '') {
                $details[] = ucfirst(strtolower($type));
            }
            $raisedBy = trim((string)($error['raised_by'] ?? ''));
            if ($raisedBy !== '') {
                $details[] = 'Raised by ' . $raisedBy;
            }
            $locations = array_values(array_filter(
                array_map('strval', (array)($error['locations'] ?? [])),
                static fn(string $location): bool => trim($location) !== ''
            ));
            if ($locations !== []) {
                $details[] = 'Location: ' . implode('; ', $locations);
            }
            $items .= '<li><strong>' . \eel_accounts\Support\Utf8::html($title) . '</strong>'
                . ($details !== []
                    ? '<div class="helper">' . \eel_accounts\Support\Utf8::html(
                        implode(' · ', $details)
                    ) . '</div>'
                    : '')
                . '</li>';
        }

        return $items !== '' ? '<ul class="helper">' . $items . '</ul>' : '—';
    }

    protected function govTalkErrorsExport(array $errors): string
    {
        $lines = [];
        $errorNumber = 0;
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $errorNumber++;
            $fields = [
                'Error ' . $errorNumber,
                'RaisedBy: ' . (string)($error['raised_by'] ?? ''),
                'Number: ' . (string)($error['number'] ?? ''),
                'Type: ' . (string)($error['type'] ?? ''),
            ];
            foreach (['source' => 'Source', 'scope' => 'Scope'] as $key => $label) {
                $value = trim((string)($error[$key] ?? ''));
                if ($value !== '') {
                    $fields[] = $label . ': ' . $value;
                }
            }
            foreach (array_values((array)($error['texts'] ?? [])) as $index => $text) {
                $fields[] = 'Text ' . ($index + 1) . ': ' . (string)$text;
            }
            $locationNumber = 0;
            foreach ((array)($error['locations'] ?? []) as $location) {
                if (trim((string)$location) === '') {
                    continue;
                }
                $locationNumber++;
                $fields[] = 'Location ' . $locationNumber . ': ' . (string)$location;
            }
            $lines[] = implode(' | ', $fields);
        }

        return implode("\n", $lines);
    }

    private function identifierPair(string $transactionId, string $correlationId): string
    {
        $transactionId = trim($transactionId);
        $correlationId = trim($correlationId);

        return \eel_accounts\Support\Utf8::html($transactionId !== '' ? $transactionId : '—')
            . ($correlationId !== ''
                ? '<div class="helper">' . \eel_accounts\Support\Utf8::html($correlationId) . '</div>'
                : '');
    }

    private function submissionReference(string $internal, string $hmrcReference = ''): string
    {
        $internal = trim($internal);
        $hmrcReference = trim($hmrcReference);

        return \eel_accounts\Support\Utf8::html($internal !== '' ? $internal : '—')
            . ($hmrcReference !== ''
                ? '<div class="helper">HMRC ref '
                    . \eel_accounts\Support\Utf8::html($hmrcReference) . '</div>'
                : '');
    }

    protected function timestamp(string $value): string
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

    protected function badge(string $state): string
    {
        return match (strtolower(trim($state))) {
            'success', 'accepted', 'succeeded', 'verified', 'acknowledged' => 'success',
            'danger', 'rejected', 'failed', 'internal_failure', 'evidence_incomplete' => 'danger',
            'warning', 'transport_unknown', 'parked' => 'warning',
            'info', 'prepared', 'sent', 'received', 'pending', 'submitting' => 'info',
            default => 'muted',
        };
    }
}
