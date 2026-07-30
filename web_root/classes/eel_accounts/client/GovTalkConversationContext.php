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
 * Immutable authority/conversation identity plus the mandatory evidence
 * boundary used by GovTalkExchangeHandler.
 */
final class GovTalkConversationContext
{
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private readonly \Closure $captureRequest;
    /** @var \Closure(array<string,mixed>):void */
    private readonly \Closure $markSendStarted;
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private readonly \Closure $captureResponse;
    /** @var \Closure(string,string,array<string,mixed>):void */
    private readonly \Closure $completeExchange;
    /** @var \Closure(string,string):void */
    private readonly \Closure $markEvidenceIncomplete;

    public function __construct(
        public readonly string $authority,
        public readonly int $companyId,
        public readonly int $accountingPeriodId,
        public readonly string $environment,
        public readonly string $archiveReference,
        callable $captureRequest,
        callable $markSendStarted,
        callable $captureResponse,
        callable $completeExchange,
        callable $markEvidenceIncomplete
    ) {
        if ($companyId < 0 || $accountingPeriodId < 0) {
            throw new \InvalidArgumentException(
                'A GovTalk conversation requires a company and accounting period.'
            );
        }
        $this->captureRequest = \Closure::fromCallable($captureRequest);
        $this->markSendStarted = \Closure::fromCallable($markSendStarted);
        $this->captureResponse = \Closure::fromCallable($captureResponse);
        $this->completeExchange = \Closure::fromCallable($completeExchange);
        $this->markEvidenceIncomplete = \Closure::fromCallable($markEvidenceIncomplete);
    }

    public static function fromCallbacks(
        string $authority,
        string $environment,
        callable $beforeSend,
        callable $afterReceive
    ): self {
        return new self(
            $authority,
            0,
            0,
            strtoupper(trim($environment)),
            'callback-boundary',
            $beforeSend,
            static function (array $unused): void {
            },
            $afterReceive,
            static function (string $unusedState, string $unusedError, array $unusedResult): void {
            },
            static function (string $unusedTransactionId, string $unusedError): void {
            }
        );
    }

    /** @return array<string,mixed> */
    public function captureRequest(array $evidence): array
    {
        return ($this->captureRequest)($evidence);
    }

    public function markSendStarted(array $evidence): void
    {
        ($this->markSendStarted)($evidence);
    }

    /** @return array<string,mixed> */
    public function captureResponse(array $evidence): array
    {
        return ($this->captureResponse)($evidence);
    }

    /** @param array<string,mixed> $result */
    public function completeExchange(string $state, string $error, array $result): void
    {
        ($this->completeExchange)($state, $error, $result);
    }

    public function markEvidenceIncomplete(string $transactionId, string $error): void
    {
        ($this->markEvidenceIncomplete)($transactionId, $error);
    }
}
