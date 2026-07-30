<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

use eel_accounts\Service\GovTalkProtocolMetadataService;

/**
 * The single write-before-send and capture-before-parse pathway for GovTalk.
 */
final class GovTalkExchangeHandler
{
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private readonly \Closure $transport;
    /** @var null|\Closure(string):string */
    private readonly ?\Closure $errorSanitizer;

    public function __construct(
        callable $transport,
        private readonly ?GovTalkProtocolMetadataService $metadata = null,
        ?callable $errorSanitizer = null
    ) {
        $this->transport = \Closure::fromCallable($transport);
        $this->errorSanitizer = $errorSanitizer === null
            ? null
            : \Closure::fromCallable($errorSanitizer);
    }

    /**
     * @param GovTalkResponseInterpreterInterface|callable(GovTalkPreparedRequest,GovTalkRawResponse):array<string,mixed> $interpreter
     */
    public function execute(
        GovTalkPreparedRequest $request,
        GovTalkConversationContext $context,
        GovTalkResponseInterpreterInterface|callable $interpreter
    ): GovTalkExchangeResult {
        try {
            $receipt = $context->captureRequest($request->evidence());
            $this->assertReceipt($receipt, $request, 'request');
            $context->markSendStarted($request->evidence());
        } catch (\Throwable $exception) {
            $error = $this->safeError($exception->getMessage());
            $payload = [
                'success' => false,
                'pre_send_failure' => true,
                'transport_unknown' => false,
                'evidence_incomplete' => false,
                'transaction_id' => $request->transactionId,
                'error' => $error,
            ];
            $context->completeExchange('failed', $error, $payload);

            return new GovTalkExchangeResult($payload, $request, null);
        }

        try {
            $transportResponse = ($this->transport)($request->transportRequest);
            if (!is_array($transportResponse)) {
                throw new \RuntimeException('The GovTalk HTTP transport returned an invalid response.');
            }
        } catch (\Throwable $exception) {
            $error = $this->safeError($exception->getMessage());
            $payload = [
                'success' => false,
                'pre_send_failure' => false,
                'transport_unknown' => true,
                'evidence_incomplete' => false,
                'transaction_id' => $request->transactionId,
                'error' => $error,
            ];
            $context->completeExchange('transport_unknown', $error, $payload);

            return new GovTalkExchangeResult($payload, $request, null);
        }

        $metadata = $this->metadata ?? new GovTalkProtocolMetadataService();
        try {
            $headers = $metadata->sanitizeResponseHeaders(
                is_array($transportResponse['headers'] ?? null)
                    ? $transportResponse['headers']
                    : []
            );
            $headersJson = $metadata->responseHeadersJson($headers);
            $response = new GovTalkRawResponse(
                (int)($transportResponse['status_code'] ?? 0),
                $headers,
                (string)($transportResponse['body'] ?? '')
            );
            $responseEvidence = [
                'authority' => $request->authority,
                'operation' => $request->operation,
                'environment' => $request->environment,
                'endpoint' => $request->endpoint,
                'transaction_id' => $request->transactionId,
                'correlation_id' => $request->correlationId,
                'status_code' => $response->statusCode,
                'response_headers' => $headers,
                'response_headers_sha256' => hash('sha256', $headersJson),
                'response_xml' => $response->body,
                'response_sha256' => $response->sha256,
                'response_bytes' => $response->bytes,
            ];
            $receipt = $context->captureResponse($responseEvidence);
            $this->assertResponseReceipt($receipt, $request, $response, hash('sha256', $headersJson));
        } catch (\Throwable $exception) {
            $message = 'The exact GovTalk response evidence could not be persisted: '
                . $this->safeError($exception->getMessage());
            $context->markEvidenceIncomplete($request->transactionId, $message);
            $payload = [
                'success' => false,
                'pre_send_failure' => false,
                'transport_unknown' => true,
                'evidence_incomplete' => true,
                'transaction_id' => $request->transactionId,
                'error' => $message,
            ];

            return new GovTalkExchangeResult($payload, $request, $response ?? null);
        }

        try {
            $payload = $interpreter instanceof GovTalkResponseInterpreterInterface
                ? $interpreter->interpret($request, $response)
                : $interpreter($request, $response);
        } catch (\Throwable $exception) {
            $error = $this->safeError($exception->getMessage());
            $payload = [
                'success' => false,
                'pre_send_failure' => false,
                'transport_unknown' => false,
                'evidence_incomplete' => false,
                'transaction_id' => $request->transactionId,
                'status_code' => $response->statusCode,
                'headers' => $response->headers,
                'response_xml' => $response->body,
                'error' => $error,
            ];
        }
        $payload['transaction_id'] ??= $request->transactionId;
        $payload['status_code'] ??= $response->statusCode;
        $payload['headers'] ??= $response->headers;
        $payload['response_xml'] ??= $response->body;
        $errors = $metadata->govTalkErrors($response->body);
        $payload['govtalk_errors'] ??= $errors;
        $state = $this->state($payload);
        $context->completeExchange(
            $state,
            trim((string)($payload['error'] ?? '')),
            $payload
        );

        return new GovTalkExchangeResult($payload, $request, $response);
    }

    /** @param array<string,mixed> $receipt */
    private function assertReceipt(
        array $receipt,
        GovTalkPreparedRequest $request,
        string $direction
    ): void {
        $transactionId = strtoupper(trim((string)($receipt['transaction_id'] ?? '')));
        $sha = strtolower(trim((string)($receipt[$direction . '_sha256'] ?? '')));
        if ($transactionId === ''
            || !hash_equals(strtoupper($request->transactionId), $transactionId)
            || !hash_equals($request->sha256, $sha)
            || (int)($receipt[$direction . '_bytes'] ?? -1) !== $request->bytes) {
            throw new \RuntimeException(
                'The GovTalk request evidence receipt did not match the exact transport bytes.'
            );
        }
    }

    /** @param array<string,mixed> $receipt */
    private function assertResponseReceipt(
        array $receipt,
        GovTalkPreparedRequest $request,
        GovTalkRawResponse $response,
        string $headersSha256
    ): void {
        $transactionId = strtoupper(trim((string)($receipt['transaction_id'] ?? '')));
        $receiptSha = $receipt['response_sha256'] ?? null;
        if ($transactionId === ''
            || !hash_equals(strtoupper($request->transactionId), $transactionId)
            || (int)($receipt['response_bytes'] ?? -1) !== $response->bytes
            || ($response->sha256 !== null
                && (!is_string($receiptSha)
                    || !hash_equals($response->sha256, strtolower($receiptSha))))
            || ($response->sha256 === null && $receiptSha !== null)
            || !hash_equals(
                $headersSha256,
                strtolower(trim((string)($receipt['response_headers_sha256'] ?? '')))
            )) {
            throw new \RuntimeException(
                'The GovTalk response evidence receipt did not match the exact response evidence.'
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private function state(array $payload): string
    {
        if (!empty($payload['evidence_incomplete'])) {
            return 'evidence_incomplete';
        }
        if (!empty($payload['transport_unknown'])) {
            return 'transport_unknown';
        }
        if (!empty($payload['success'])) {
            return 'succeeded';
        }
        $outcome = strtolower(trim((string)(
            $payload['business_outcome']
                ?? $payload['normalized_status']
                ?? $payload['outcome']
                ?? ''
        )));

        return in_array($outcome, ['rejected', 'reject'], true) ? 'rejected' : 'failed';
    }

    private function safeError(string $message): string
    {
        return $this->errorSanitizer instanceof \Closure
            ? (string)($this->errorSanitizer)($message)
            : $message;
    }
}
