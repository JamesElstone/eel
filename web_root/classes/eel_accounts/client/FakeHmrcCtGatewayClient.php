<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

/**
 * Deterministic in-memory gateway used by orchestration and lifecycle tests.
 * Script entries are keyed by submit, poll, delete or data_request.
 */
final class FakeHmrcCtGatewayClient implements HmrcCtGatewayClientInterface
{
    private array $script;
    private array $calls = [];
    private int $sequence = 0;

    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    public function configurationStatus(string $environment): array
    {
        $profile = HmrcCtGatewayEnvironment::profile($environment);

        return [
            'ready' => true,
            'environment' => $profile['environment'],
            'credential_environment' => $profile['credential_environment'],
            'class' => $profile['class'],
            'gateway_test' => $profile['gateway_test'],
            'statutory' => $profile['statutory'],
            'submission_url' => $profile['submission_url'],
            'poll_url' => $profile['poll_url'],
            'credentials_present' => true,
            'blockers' => [],
        ];
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = HmrcCtGatewayEnvironment::profile($environment);
        $transactionId = $this->transactionId($transactionId);
        $call = [
            'operation' => 'submit',
            'filing_body_xml' => $filingBodyXml,
            'utr' => $utr,
            'environment' => $profile['environment'],
            'transaction_id' => $transactionId,
        ];
        $this->calls[] = $call;

        return $this->next('submit', $this->result($profile, [
            'success' => true,
            'operation' => 'submit',
            'protocol_state' => 'acknowledged',
            'transaction_id' => $transactionId,
            'correlation_id' => str_repeat('A', 32),
            'response_endpoint' => $profile['poll_url'],
            'poll_interval' => 1,
            'qualifier' => 'acknowledgement',
            'function' => 'submit',
        ]));
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = HmrcCtGatewayEnvironment::profile($environment);
        $transactionId = $this->transactionId($transactionId);
        $call = [
            'operation' => 'poll',
            'correlation_id' => $correlationId,
            'response_endpoint' => $responseEndpoint,
            'environment' => $profile['environment'],
            'transaction_id' => $transactionId,
        ];
        $this->calls[] = $call;

        return $this->next('poll', $this->result($profile, [
            'success' => true,
            'operation' => 'poll',
            'protocol_state' => 'final_response',
            'business_outcome' => 'accepted',
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => $profile['poll_url'],
            'cleanup_required' => true,
            'qualifier' => 'response',
            'function' => 'submit',
        ]));
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = HmrcCtGatewayEnvironment::profile($environment);
        $transactionId = $this->transactionId($transactionId);
        $call = [
            'operation' => 'delete',
            'correlation_id' => $correlationId,
            'response_endpoint' => $responseEndpoint,
            'environment' => $profile['environment'],
            'transaction_id' => $transactionId,
        ];
        $this->calls[] = $call;

        return $this->next('delete', $this->result($profile, [
            'success' => true,
            'operation' => 'delete',
            'protocol_state' => 'deleted',
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'qualifier' => 'response',
            'function' => 'delete',
        ]));
    }

    public function requestData(
        array $criteria,
        string $environment,
        ?string $transactionId = null
    ): array {
        $profile = HmrcCtGatewayEnvironment::profile($environment);
        $transactionId = $this->transactionId($transactionId);
        $call = [
            'operation' => 'data_request',
            'criteria' => $criteria,
            'environment' => $profile['environment'],
            'transaction_id' => $transactionId,
        ];
        $this->calls[] = $call;

        return $this->next('data_request', $this->result($profile, [
            'success' => true,
            'operation' => 'data_request',
            'protocol_state' => 'data_response',
            'transaction_id' => $transactionId,
            'qualifier' => 'response',
            'function' => 'list',
        ]));
    }

    public function calls(): array
    {
        return $this->calls;
    }

    private function next(string $operation, array $default): array
    {
        $queue = is_array($this->script[$operation] ?? null) ? $this->script[$operation] : [];

        if ($queue === []) {
            return $default;
        }

        $next = array_shift($queue);
        $this->script[$operation] = $queue;

        return is_array($next) ? array_replace($default, $next) : $default;
    }

    private function result(array $profile, array $overrides): array
    {
        return array_replace([
            'success' => false,
            'transport_unknown' => false,
            'operation' => '',
            'status_code' => 200,
            'headers' => [],
            'endpoint' => $profile['submission_url'],
            'environment' => $profile['environment'],
            'credential_environment' => $profile['credential_environment'],
            'class' => $profile['class'],
            'gateway_test' => $profile['gateway_test'],
            'statutory' => $profile['statutory'],
            'protocol_state' => 'failed',
            'business_outcome' => null,
            'transaction_id' => '',
            'correlation_id' => '',
            'response_endpoint' => '',
            'poll_interval' => null,
            'gateway_timestamp' => '',
            'cleanup_required' => false,
            'delete_not_found' => false,
            'qualifier' => '',
            'function' => '',
            'errors' => [],
            'request_xml' => '',
            'response_xml' => '',
            'body_xml' => '',
            'status_records' => [],
            'irmark' => '',
            'error' => '',
        ], $overrides);
    }

    private function transactionId(?string $transactionId): string
    {
        if ($transactionId !== null && trim($transactionId) !== '') {
            return strtoupper(trim($transactionId));
        }

        $this->sequence++;

        return strtoupper(str_pad(dechex($this->sequence), 32, '0', STR_PAD_LEFT));
    }
}
