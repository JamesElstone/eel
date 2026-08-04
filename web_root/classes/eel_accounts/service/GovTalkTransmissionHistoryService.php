<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Support\Utf8;

final class GovTalkTransmissionHistoryService
{
    public function __construct(
        private readonly ?GovTalkProtocolConversationService $conversation = null,
        private readonly ?CompaniesHouseAccountsSubmissionService $companiesHouse = null,
        private readonly ?HmrcReceiptReferenceService $receiptReferences = null
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function submissionHistory(
        int $companyId,
        int $accountingPeriodId,
        string $authority = '',
        string $environment = ''
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }
        $rows = [];
        $companiesHouseSubmissions = $this->companiesHouse()->submissionHistory(
            $companyId,
            $accountingPeriodId
        );
        $accountingPeriod = $companiesHouseSubmissions !== []
            ? \InterfaceDB::fetchOne(
                'SELECT period_start, period_end
                 FROM accounting_periods
                 WHERE id = :accounting_period_id
                   AND company_id = :company_id
                 LIMIT 1',
                [
                    'accounting_period_id' => $accountingPeriodId,
                    'company_id' => $companyId,
                ]
            )
            : null;
        $accountsPeriod = $this->periodRange((array)$accountingPeriod);
        foreach ($companiesHouseSubmissions as $submission) {
            $submissionId = (int)($submission['id'] ?? 0);
            $rows[] = [
                'authority' => 'companies_house',
                'authority_label' => 'Companies House',
                'conversation_id' => $submissionId,
                'ct_period_id' => 0,
                'submission_reference' => trim((string)(
                    $submission['submission_number'] ?? ''
                )) ?: 'Not sent',
                'filing_context' => 'Company accounts'
                    . ($accountsPeriod !== '' ? ' - ' . $accountsPeriod : ''),
                'filing_type' => ucfirst((string)($submission['filing_kind'] ?? 'accounts')),
                'environment' => (string)($submission['environment'] ?? ''),
                'transaction_id' => (string)($submission['transaction_id'] ?? ''),
                'correlation_id' => '',
                'prepared_at' => $submission['prepared_at'] ?? null,
                'submitted_at' => $submission['submitted_at'] ?? null,
                'latest_status' => $this->companiesHouseStatus($submission),
                'status_key' => (string)($submission['lifecycle'] ?? ''),
                'response_reprocess_available' => false,
                'response_reprocess_exchange_id' => 0,
                'sort_at' => (string)(
                    $submission['submitted_at']
                        ?? $submission['prepared_at']
                        ?? $submission['created_at']
                        ?? ''
                ),
            ];
        }
        if (\InterfaceDB::tableExists('hmrc_ct600_submissions')
            && \InterfaceDB::tableExists('govtalk_protocol_exchanges')) {
            foreach (\InterfaceDB::fetchAll(
                'SELECT h.*,
                        ctp.period_start AS ct_period_start,
                        ctp.period_end AS ct_period_end
                 FROM hmrc_ct600_submissions h
                 LEFT JOIN corporation_tax_periods ctp ON ctp.id = h.ct_period_id
                 WHERE h.company_id = :company_id
                   AND h.accounting_period_id = :accounting_period_id
                   AND (
                     h.submitted_at IS NOT NULL
                     OR h.transaction_id IS NOT NULL
                     OR EXISTS (
                       SELECT 1
                       FROM govtalk_protocol_exchanges e
                       WHERE e.authority = :authority
                         AND e.hmrc_submission_id = h.id
                     )
                   )
                 ORDER BY h.id DESC
                 LIMIT 100',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'authority' => 'hmrc',
                ]
            ) as $submission) {
                $submissionId = (int)$submission['id'];
                $remoteReference = $this->receiptReferences()->normalise(
                    $submission['hmrc_submission_reference'] ?? null
                );
                $period = implode(' to ', array_filter([
                    trim((string)($submission['ct_period_start'] ?? '')),
                    trim((string)($submission['ct_period_end'] ?? '')),
                ], static fn(string $value): bool => $value !== ''));
                $reprocessExchangeId = $this->hmrcResponseReprocessExchangeId($submission);
                $rows[] = [
                    'authority' => 'hmrc',
                    'authority_label' => 'HMRC',
                    'conversation_id' => $submissionId,
                    'ct_period_id' => (int)($submission['ct_period_id'] ?? 0),
                    'submission_reference' => $this->submissionCounter($submissionId),
                    'hmrc_document_reference' => $remoteReference ?? '',
                    'filing_context' => 'CT600' . ($period !== '' ? ' — ' . $period : ''),
                    'filing_type' => ucfirst((string)(
                        $submission['submission_type'] ?? 'original'
                    )),
                    'environment' => (string)($submission['environment'] ?? ''),
                    'transaction_id' => (string)($submission['transaction_id'] ?? ''),
                    'correlation_id' => (string)($submission['hmrc_correlation_id'] ?? ''),
                    'prepared_at' => $submission['created_at'] ?? null,
                    'submitted_at' => $submission['submitted_at'] ?? null,
                    'latest_status' => $this->hmrcStatus($submission),
                    'status_tone' => $this->hmrcStatusTone($submission),
                    'status_key' => (string)($submission['protocol_state'] ?? ''),
                    'response_reprocess_available' => $reprocessExchangeId > 0,
                    'response_reprocess_exchange_id' => $reprocessExchangeId,
                    'sort_at' => (string)(
                        $submission['submitted_at']
                            ?? $submission['created_at']
                            ?? ''
                    ),
                ];
            }
        }
        $authority = $this->filterAuthority($authority);
        $environment = $this->filterEnvironment($environment);
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool =>
                ($authority === '' || (string)$row['authority'] === $authority)
                && ($environment === ''
                    || (string)$row['environment'] === $environment)
        ));
        usort(
            $rows,
            static fn(array $left, array $right): int =>
                strcmp((string)$right['sort_at'], (string)$left['sort_at'])
                ?: ((int)$right['conversation_id'] <=> (int)$left['conversation_id'])
        );

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function exchangeHistory(
        int $companyId,
        string $authority = '',
        string $environment = '',
        string $conversationAuthority = '',
        int $conversationId = 0
    ): array {
        if ($companyId <= 0 || !\InterfaceDB::tableExists('govtalk_protocol_exchanges')) {
            return [];
        }
        $authority = $this->filterAuthority($authority);
        $environment = $this->filterEnvironment($environment);
        $conversationAuthority = $this->filterAuthority($conversationAuthority);
        $where = ['a.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        if ($authority !== '') {
            $where[] = 'e.authority = :authority';
            $params['authority'] = $authority;
        }
        if ($environment !== '') {
            $where[] = 'e.environment = :environment';
            $params['environment'] = $environment;
        }
        if ($conversationId > 0 && $conversationAuthority === 'companies_house') {
            $where[] = 'e.authority = :conversation_authority';
            $where[] = 'e.submission_id = :conversation_id';
            $params['conversation_authority'] = 'companies_house';
            $params['conversation_id'] = $conversationId;
        } elseif ($conversationId > 0 && $conversationAuthority === 'hmrc') {
            $where[] = 'e.authority = :conversation_authority';
            $where[] = 'e.hmrc_submission_id = :conversation_id';
            $params['conversation_authority'] = 'hmrc';
            $params['conversation_id'] = $conversationId;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT e.*,
                    a.company_id,
                    a.accounting_period_id AS evidence_accounting_period_id,
                    a.submission_reference AS archive_reference,
                    s.submission_number AS companies_house_submission_number,
                    s.lifecycle AS companies_house_lifecycle,
                    p.outcome AS preflight_outcome,
                    cycle.normalized_status,
                    cycle.acknowledgement_state,
                    h.hmrc_submission_reference,
                    h.protocol_state AS hmrc_protocol_state,
                    h.business_outcome AS hmrc_business_outcome,
                    h.created_at AS hmrc_created_at
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             LEFT JOIN companies_house_accounts_submissions s ON s.id = e.submission_id
             LEFT JOIN companies_house_company_auth_preflights p ON p.id = e.preflight_id
             LEFT JOIN companies_house_accounts_status_cycles cycle ON cycle.id = e.status_cycle_id
             LEFT JOIN hmrc_ct600_submissions h ON h.id = e.hmrc_submission_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY CASE WHEN e.sent_at IS NULL THEN 1 ELSE 0 END ASC,
                      e.sent_at DESC,
                      e.id DESC
             LIMIT 1000',
            $params
        );
        $metadata = new GovTalkProtocolMetadataService();
        foreach ($rows as &$row) {
            $row['authority_label'] = (string)$row['authority'] === 'hmrc'
                ? 'HMRC'
                : 'Companies House';
            $row['submission_reference'] = $this->exchangeSubmissionReference($row);
            $row['hmrc_document_reference'] = (string)$row['authority'] === 'hmrc'
                ? ($this->receiptReferences()->normalise(
                    $row['hmrc_submission_reference'] ?? null
                ) ?? '')
                : '';
            $messageClass = trim((string)($row['request_message_class'] ?? ''));
            $row['request_message_class'] = $messageClass !== ''
                ? $messageClass
                : $metadata->messageClassForOperation((string)$row['operation']);
            $row['display_http_status'] = $metadata->httpStatusLabel(
                $row['response_status_code'] !== null
                    ? (int)$row['response_status_code']
                    : null
            );
            $row['govtalk_errors'] = $this->decodedErrors(
                $row['govtalk_errors_json'] ?? null
            );
            if ($row['govtalk_errors'] === []
                && trim((string)($row['response_path'] ?? '')) !== '') {
                $row['govtalk_errors'] = $this->errorsFromEvidence(
                    (string)$row['response_path'],
                    (string)$row['response_sha256'],
                    $metadata
                );
            }
            $row['request_available'] = $this->available(
                $row['request_path'] ?? null,
                $row['request_sha256'] ?? null
            );
            $row['response_available'] = $this->available(
                $row['response_path'] ?? null,
                $row['response_sha256'] ?? null
            );
            $row['display_outcome'] = $this->exchangeOutcome($row);
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed> */
    public function evidenceFileForCompany(
        int $companyId,
        int $exchangeId,
        string $direction
    ): array {
        return $this->conversation()->evidenceFileForCompany(
            $companyId,
            $exchangeId,
            $direction
        );
    }

    public function recordEvidenceDownload(
        int $companyId,
        int $exchangeId,
        string $direction,
        string $actor
    ): void {
        $row = \InterfaceDB::fetchOne(
            'SELECT e.authority, e.submission_id, e.hmrc_submission_id,
                    e.operation, e.transaction_id, a.accounting_period_id
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.id = :exchange_id
               AND a.company_id = :company_id
             LIMIT 1',
            ['exchange_id' => $exchangeId, 'company_id' => $companyId]
        );
        if (!is_array($row)) {
            return;
        }
        $details = [
            'exchange_id' => $exchangeId,
            'authority' => (string)$row['authority'],
            'operation' => (string)$row['operation'],
            'transaction_id' => (string)$row['transaction_id'],
            'direction' => strtolower(trim($direction)),
        ];
        if ((string)$row['authority'] === 'hmrc'
            && (int)($row['hmrc_submission_id'] ?? 0) > 0
            && \InterfaceDB::tableExists('hmrc_submission_events')) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_submission_events (
                    submission_id, event_level, event_message,
                    event_context_json, created_at
                 ) VALUES (
                    :submission_id, :level, :message, :context, :created_at
                 )',
                [
                    'submission_id' => (int)$row['hmrc_submission_id'],
                    'level' => 'info',
                    'message' => 'An administrator downloaded exact HMRC GovTalk evidence.',
                    'context' => Utf8::json($details, JSON_THROW_ON_ERROR),
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            return;
        }
        if ((string)$row['authority'] === 'companies_house') {
            $this->companiesHouse()->recordProtocolEvidenceDownload(
                $exchangeId,
                $direction,
                $actor
            );
            return;
        }
        if (\InterfaceDB::tableExists('year_end_audit_log')
            && (int)($row['accounting_period_id'] ?? 0) > 0) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO year_end_audit_log (
                    company_id, accounting_period_id, action, action_by,
                    action_at, new_value_json, notes
                 ) VALUES (
                    :company_id, :accounting_period_id, :action, :actor,
                    :action_at, :details, :notes
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => (int)$row['accounting_period_id'],
                    'action' => 'govtalk_protocol_evidence_downloaded',
                    'actor' => trim($actor) ?: 'system',
                    'action_at' => gmdate('Y-m-d H:i:s'),
                    'details' => Utf8::json($details, JSON_THROW_ON_ERROR),
                    'notes' => 'An administrator downloaded exact private GovTalk evidence.',
                ]
            );
        }
    }

    private function companiesHouseStatus(array $submission): string
    {
        $ack = strtolower(trim((string)(
            $submission['pending_acknowledgement_state'] ?? ''
        )));
        $pending = strtolower(trim((string)(
            $submission['pending_normalized_status'] ?? ''
        )));
        if ($pending !== ''
            && in_array($ack, ['required', 'sending', 'failed', 'transport_unknown'], true)) {
            return $this->label($pending) . ' — acknowledgement ' . $this->label($ack);
        }

        return $this->label((string)($submission['lifecycle'] ?? 'unknown'));
    }

    private function hmrcStatus(array $submission): string
    {
        $business = strtolower(trim((string)($submission['business_outcome'] ?? '')));
        $protocol = strtolower(trim((string)($submission['protocol_state'] ?? '')));
        if ($this->successfulHmrcOutcome($business)) {
            return $protocol === 'delete_pending' ? 'Submitted — cleanup required' : 'Submitted';
        }
        if ($business === 'rejected' && $protocol === 'delete_pending') {
            return 'Rejected — cleanup required';
        }
        if (!in_array($business, ['', 'none'], true)) {
            return $this->label($business);
        }

        return $this->label($protocol !== '' ? $protocol : 'unknown');
    }

    private function hmrcStatusTone(array $submission): string
    {
        $business = strtolower(trim((string)($submission['business_outcome'] ?? '')));
        if ($this->successfulHmrcOutcome($business)) {
            return 'success';
        }
        if (in_array($business, ['rejected', 'error'], true)) {
            return 'danger';
        }

        return (string)($submission['protocol_state'] ?? '');
    }

    private function successfulHmrcOutcome(string $outcome): bool
    {
        return in_array($outcome, [
            'sandbox_passed', 'til_validated', 'live_accepted', 'accepted',
        ], true);
    }

    private function hmrcResponseReprocessExchangeId(array $submission): int
    {
        $contract = $this->hmrcResponseReprocessContract($submission);
        if ($contract === null) {
            return 0;
        }
        $submissionId = (int)($submission['id'] ?? 0);
        $environment = strtoupper(trim((string)($submission['environment'] ?? '')));
        if ($submissionId <= 0 || $environment === '') {
            return 0;
        }
        $exchanges = \InterfaceDB::fetchAll(
            'SELECT e.*,
                    a.authority AS archive_authority,
                    a.environment AS archive_environment,
                    a.company_id AS archive_company_id,
                    a.accounting_period_id AS archive_accounting_period_id,
                    a.submission_reference AS archive_submission_reference
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.authority = :authority
               AND e.hmrc_submission_id = :submission_id
               AND e.operation = :operation
               AND e.environment = :environment
             ORDER BY e.id DESC
             LIMIT 20',
            [
                'authority' => 'hmrc',
                'submission_id' => $submissionId,
                'operation' => $contract['operation'],
                'environment' => $environment,
            ]
        );
        foreach ($exchanges as $exchange) {
            if ($this->hmrcResponseReprocessEligible($submission, $exchange, $contract)) {
                return (int)($exchange['id'] ?? 0);
            }
        }

        return 0;
    }

    /** @return array{operation:string,path_column:string,hash_column:string,metadata_only?:bool}|null */
    private function hmrcResponseReprocessContract(array $submission): ?array
    {
        $business = strtolower(trim((string)($submission['business_outcome'] ?? '')));
        $protocol = strtolower(trim((string)($submission['protocol_state'] ?? '')));
        $storedReference = $this->receiptReferences()->normalise(
            $submission['hmrc_submission_reference'] ?? null
        );
        if ($this->successfulHmrcOutcome($business)
            && in_array($protocol, ['delete_pending', 'closed'], true)
            && $storedReference === null) {
            return [
                'operation' => 'poll',
                'path_column' => 'response_body_path',
                'hash_column' => 'response_sha256',
                'metadata_only' => true,
            ];
        }

        return match (strtolower(trim((string)($submission['protocol_state'] ?? '')))) {
            'transport_uncertain' => [
                'operation' => 'submit',
                'path_column' => 'response_body_path',
                'hash_column' => 'response_sha256',
            ],
            'awaiting_poll' => [
                'operation' => 'poll',
                'path_column' => 'response_body_path',
                'hash_column' => 'response_sha256',
            ],
            'delete_pending' => [
                'operation' => 'delete',
                'path_column' => 'cleanup_response_path',
                'hash_column' => 'cleanup_response_sha256',
            ],
            default => null,
        };
    }

    /** @param array{operation:string,path_column:string,hash_column:string,metadata_only?:bool} $contract */
    private function hmrcResponseReprocessEligible(
        array $submission,
        array $exchange,
        array $contract
    ): bool {
        $submissionId = (int)($submission['id'] ?? 0);
        $environment = strtoupper(trim((string)($submission['environment'] ?? '')));
        $transactionId = trim((string)($submission['transaction_id'] ?? ''));
        $metadataOnly = !empty($contract['metadata_only']);
        $submissionPath = trim((string)($submission[$contract['path_column']] ?? ''));
        $submissionHash = strtolower(trim((string)(
            $submission[$contract['hash_column']] ?? ''
        )));
        $exchangePath = trim((string)($exchange['response_path'] ?? ''));
        $exchangeHash = strtolower(trim((string)($exchange['response_sha256'] ?? '')));
        $statusCode = (int)($exchange['response_status_code'] ?? 0);
        if ($submissionId <= 0
            || (int)($exchange['id'] ?? 0) <= 0
            || (string)($exchange['authority'] ?? '') !== 'hmrc'
            || (int)($exchange['hmrc_submission_id'] ?? 0) !== $submissionId
            || (string)($exchange['operation'] ?? '') !== $contract['operation']
            || strtoupper(trim((string)($exchange['environment'] ?? ''))) !== $environment
            || (!$metadataOnly
                && ($transactionId === ''
                    || trim((string)($exchange['transaction_id'] ?? '')) !== $transactionId))
            || ($metadataOnly
                ? ((string)($exchange['exchange_state'] ?? '') !== 'succeeded'
                    || (string)($exchange['outcome_code'] ?? '') !== 'accepted')
                : !in_array((string)($exchange['exchange_state'] ?? ''), [
                    'failed', 'transport_unknown', 'received',
                ], true))
            || $statusCode < 200
            || $statusCode >= 300
            || $submissionPath === ''
            || $exchangePath === ''
            || preg_match('/^[a-f0-9]{64}$/D', $submissionHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $exchangeHash) !== 1
            || !hash_equals($submissionHash, $exchangeHash)
            || !$this->sameEvidencePath($submissionPath, $exchangePath)
            || !$this->available($exchangePath, $exchangeHash)) {
            return false;
        }

        if ($metadataOnly) {
            $xml = file_get_contents($exchangePath);
            if (!is_string($xml) || $this->receiptReferences()->extract($xml) === null) {
                return false;
            }
        }

        return (string)($exchange['archive_authority'] ?? '') === 'hmrc'
            && strtoupper(trim((string)($exchange['archive_environment'] ?? ''))) === $environment
            && (int)($exchange['archive_company_id'] ?? 0) === (int)($submission['company_id'] ?? 0)
            && (int)($exchange['archive_accounting_period_id'] ?? 0)
                === (int)($submission['accounting_period_id'] ?? 0)
            && (string)($exchange['archive_submission_reference'] ?? '')
                === 'submission-' . $this->submissionCounter($submissionId);
    }

    private function sameEvidencePath(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        $leftReal = realpath($left);
        $rightReal = realpath($right);

        return is_string($leftReal)
            && is_string($rightReal)
            && strcasecmp($leftReal, $rightReal) === 0;
    }

    private function exchangeSubmissionReference(array $row): string
    {
        if ((string)$row['authority'] === 'hmrc') {
            return $this->submissionCounter((int)($row['hmrc_submission_id'] ?? 0));
        }
        $number = trim((string)($row['companies_house_submission_number'] ?? ''));

        return $number !== '' ? $number : 'Not allocated';
    }

    private function submissionCounter(int $submissionId): string
    {
        return sprintf('%06d', max(0, $submissionId));
    }

    private function receiptReferences(): HmrcReceiptReferenceService
    {
        return $this->receiptReferences ?? new HmrcReceiptReferenceService();
    }

    private function periodRange(array $period): string
    {
        return implode(' to ', array_filter([
            trim((string)($period['period_start'] ?? '')),
            trim((string)($period['period_end'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
    }

    private function exchangeOutcome(array $row): string
    {
        $state = strtolower(trim((string)($row['exchange_state'] ?? '')));
        if ($state === 'evidence_incomplete') {
            return 'Evidence incomplete';
        }
        if ($state === 'transport_unknown') {
            return 'Transport unknown';
        }
        if ((string)($row['preflight_outcome'] ?? '') === 'presenter_authorisation_failed') {
            return 'Presenter authorisation failed';
        }
        foreach ((array)($row['govtalk_errors'] ?? []) as $error) {
            if (is_array($error)
                && trim((string)($error['number'] ?? '')) === '502') {
                return 'Presenter authorisation failed';
            }
        }
        $summary = trim((string)($row['outcome_summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }
        $outcome = strtolower(trim((string)($row['outcome_code'] ?? '')));
        if ($outcome !== '') {
            return $this->label($outcome);
        }
        if ($state === 'succeeded') {
            return match ((string)($row['operation'] ?? '')) {
                'company_data' => 'Verified',
                'delete', 'status_ack' => 'Acknowledged',
                default => 'Succeeded',
            };
        }
        if ($state === 'rejected') {
            return 'Rejected';
        }
        if ($state === 'received') {
            return 'Received';
        }
        if ($state === 'sent') {
            return 'Pending';
        }
        if ($state === 'prepared') {
            return 'Not sent';
        }
        $error = trim((string)($row['error_summary'] ?? ''));

        return $error !== '' ? $error : $this->label($state ?: 'unknown');
    }

    /** @return list<array<string,mixed>> */
    private function decodedErrors(mixed $json): array
    {
        $json = trim((string)$json);
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return list<array<string,mixed>> */
    private function errorsFromEvidence(
        string $path,
        string $expectedSha,
        GovTalkProtocolMetadataService $metadata
    ): array {
        if (!$this->available($path, $expectedSha)) {
            return [];
        }
        $xml = file_get_contents($path);

        return is_string($xml) ? $metadata->govTalkErrors($xml) : [];
    }

    private function available(mixed $pathValue, mixed $shaValue): bool
    {
        $path = trim((string)$pathValue);
        $sha = strtolower(trim((string)$shaValue));
        if ($path === '' || $sha === '' || !is_file($path)) {
            return false;
        }
        $actual = hash_file('sha256', $path);

        return is_string($actual) && hash_equals($sha, strtolower($actual));
    }

    private function filterAuthority(string $authority): string
    {
        $authority = strtolower(trim($authority));

        return in_array($authority, ['companies_house', 'hmrc'], true)
            ? $authority
            : '';
    }

    private function filterEnvironment(string $environment): string
    {
        $environment = strtoupper(trim($environment));

        return in_array($environment, ['TEST', 'TIL', 'LIVE'], true)
            ? $environment
            : '';
    }

    private function label(string $value): string
    {
        $value = trim(str_replace('_', ' ', $value));

        return $value !== '' ? ucfirst($value) : 'Unknown';
    }

    private function conversation(): GovTalkProtocolConversationService
    {
        return $this->conversation ?? new GovTalkProtocolConversationService();
    }

    private function companiesHouse(): CompaniesHouseAccountsSubmissionService
    {
        return $this->companiesHouse ?? new CompaniesHouseAccountsSubmissionService();
    }
}
