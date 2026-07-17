<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Projects a preserved final HMRC LIVE acceptance into local statutory state.
 *
 * HMRC's business outcome is immutable input to this service. The projection
 * is separately retryable so a local database problem can never turn an
 * accepted return into a rejection or invite a duplicate remote submission.
 */
final class Ct600StatutoryAcceptanceService
{
    private ?HmrcCtSubmissionRepository $repository;
    private ?\Closure $clock;

    public function __construct(
        ?HmrcCtSubmissionRepository $repository = null,
        ?\Closure $clock = null,
    ) {
        $this->repository = $repository;
        $this->clock = $clock;
    }

    /**
     * Applies a newly pending statutory projection. Repeated calls after a
     * successful application are idempotent.
     *
     * @return array<string, mixed> Updated submission record.
     */
    public function apply(int $submissionId): array
    {
        return $this->project($submissionId, false);
    }

    /**
     * Retries a failed local projection without changing or resubmitting the
     * preserved HMRC return. Repeated calls after success are idempotent.
     *
     * @return array<string, mixed> Updated submission record.
     */
    public function retry(int $submissionId): array
    {
        return $this->project($submissionId, true);
    }

    /** @return array<string, mixed> */
    private function project(int $submissionId, bool $retry): array
    {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('A valid CT600 submission ID is required.');
        }
        $this->requireSchema();
        $scope = $this->repository()->fetchById($submissionId);
        if (!is_array($scope)) {
            throw new \DomainException('The accepted CT600 submission could not be found.');
        }

        try {
            return $this->projectionTransaction(function () use ($submissionId, $scope, $retry): array {
                $companyId = (int)($scope['company_id'] ?? 0);
                $accountingPeriodId = (int)($scope['accounting_period_id'] ?? 0);

                // The accounting-period row is the projection coordinator. It
                // serialises two CT-period acceptances completing together.
                $accountingPeriod = \InterfaceDB::fetchOne(
                    'SELECT id, company_id FROM accounting_periods
                     WHERE id = :accounting_period_id AND company_id = :company_id
                     LIMIT 1' . $this->forUpdateSuffix(),
                    [
                        'accounting_period_id' => $accountingPeriodId,
                        'company_id' => $companyId,
                    ]
                );
                if (!is_array($accountingPeriod)) {
                    throw new \DomainException('The accepted return no longer belongs to its accounting period.');
                }

                $submission = \InterfaceDB::fetchOne(
                    'SELECT * FROM hmrc_ct600_submissions
                     WHERE id = :id AND company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1' . $this->forUpdateSuffix(),
                    [
                        'id' => $submissionId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                    ]
                );
                if (!is_array($submission)) {
                    throw new \DomainException('The accepted CT600 submission changed filing scope.');
                }
                $this->assertFinalLiveOriginal($submission);

                $syncState = (string)($submission['statutory_sync_state'] ?? '');
                if ($syncState === 'applied') {
                    return $submission;
                }
                if ($retry && $syncState !== 'failed') {
                    throw new \DomainException('Only a failed statutory projection can be retried.');
                }
                if (!$retry && $syncState !== 'pending') {
                    if ($syncState === 'failed') {
                        throw new \DomainException(
                            'The statutory projection previously failed; use the retry operation after resolving its error.'
                        );
                    }
                    throw new \DomainException('This LIVE acceptance has no statutory projection pending.');
                }

                $ctPeriodId = (int)($submission['ct_period_id'] ?? 0);
                $selectedPeriod = \InterfaceDB::fetchOne(
                    'SELECT id, company_id, accounting_period_id FROM corporation_tax_periods
                     WHERE id = :ct_period_id AND company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1' . $this->forUpdateSuffix(),
                    [
                        'ct_period_id' => $ctPeriodId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                    ]
                );
                if (!is_array($selectedPeriod)) {
                    throw new \DomainException(
                        'The accepted Corporation Tax period no longer belongs to the filing scope.'
                    );
                }

                $periods = $this->fetchAll(
                    'SELECT id FROM corporation_tax_periods
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                     ORDER BY sequence_no ASC, id ASC' . $this->forUpdateSuffix(),
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                );
                if ($periods === []) {
                    throw new \RuntimeException('The accounting period has no Corporation Tax periods to project.');
                }
                $periodIds = array_map(static fn(array $row): int => (int)$row['id'], $periods);
                if (!in_array($ctPeriodId, $periodIds, true)) {
                    throw new \DomainException('The selected Corporation Tax period left the filing scope.');
                }

                \InterfaceDB::prepareExecute(
                    'UPDATE corporation_tax_periods
                     SET status = :status, latest_submission_id = :submission_id
                     WHERE id = :ct_period_id AND company_id = :company_id
                       AND accounting_period_id = :accounting_period_id',
                    [
                        'status' => 'accepted',
                        'submission_id' => $submissionId,
                        'ct_period_id' => $ctPeriodId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                    ]
                );

                $acceptedReceipts = $this->acceptedOriginalReceipts($companyId, $accountingPeriodId);
                $receiptByPeriod = [];
                foreach ($acceptedReceipts as $receipt) {
                    $receiptPeriodId = (int)($receipt['ct_period_id'] ?? 0);
                    if (!in_array($receiptPeriodId, $periodIds, true)) {
                        throw new \DomainException('A LIVE receipt is bound outside the accounting-period CT scope.');
                    }
                    if (isset($receiptByPeriod[$receiptPeriodId])) {
                        throw new \DomainException(
                            'More than one final LIVE original exists for Corporation Tax period ' . $receiptPeriodId . '.'
                        );
                    }
                    $receiptByPeriod[$receiptPeriodId] = $receipt;
                }

                if (count($receiptByPeriod) === count($periodIds)) {
                    $this->completeAccountingPeriodObligation(
                        $companyId,
                        $accountingPeriodId,
                        $periodIds,
                        $receiptByPeriod
                    );
                }

                return $this->repository()->markStatutorySyncApplied(
                    $submissionId,
                    $companyId,
                    $this->dbDate($this->now())
                );
            });
        } catch (\Throwable $exception) {
            $this->preserveFailureState($submissionId, $exception);
            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function acceptedOriginalReceipts(int $companyId, int $accountingPeriodId): array
    {
        $receipts = $this->fetchAll(
            'SELECT id, ct_period_id, response_body_path, response_sha256, final_response_at
             FROM hmrc_ct600_submissions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND environment = :environment
               AND submission_type = :submission_type
               AND status = :status
               AND business_outcome = :business_outcome
               AND protocol_state IN (\'final_received\', \'delete_pending\', \'closed\')
             ORDER BY ct_period_id ASC, id ASC' . $this->forUpdateSuffix(),
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'environment' => 'LIVE',
                'submission_type' => 'original',
                'status' => 'accepted',
                'business_outcome' => 'live_accepted',
            ]
        );
        foreach ($receipts as $receipt) {
            if ((int)($receipt['ct_period_id'] ?? 0) <= 0
                || trim((string)($receipt['final_response_at'] ?? '')) === ''
                || trim((string)($receipt['response_body_path'] ?? '')) === ''
                || preg_match(
                    '/^[a-f0-9]{64}$/D',
                    strtolower(trim((string)($receipt['response_sha256'] ?? '')))
                ) !== 1
            ) {
                throw new \DomainException('A purported LIVE acceptance has no valid preserved final receipt.');
            }
        }

        return $receipts;
    }

    /**
     * @param list<int> $periodIds
     * @param array<int, array<string, mixed>> $receiptByPeriod
     */
    private function completeAccountingPeriodObligation(
        int $companyId,
        int $accountingPeriodId,
        array $periodIds,
        array $receiptByPeriod,
    ): void {
        // Repair every CT-period projection from the complete, locked receipt
        // set before marking the accounting-period obligation filed.
        foreach ($periodIds as $periodId) {
            $receiptId = (int)$receiptByPeriod[$periodId]['id'];
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET status = :status, latest_submission_id = :submission_id
                 WHERE id = :ct_period_id AND company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                [
                    'status' => 'accepted',
                    'submission_id' => $receiptId,
                    'ct_period_id' => $periodId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
        }

        $obligations = $this->fetchAll(
            'SELECT id FROM hmrc_obligations
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
               AND obligation_type = :obligation_type
             ORDER BY id ASC' . $this->forUpdateSuffix(),
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'obligation_type' => 'ct600_filing',
            ]
        );
        if (count($obligations) !== 1) {
            throw new \RuntimeException(
                count($obligations) === 0
                    ? 'The accounting-period CT600 filing obligation is missing.'
                    : 'More than one accounting-period CT600 filing obligation exists; resolve the duplicate before retrying.'
            );
        }

        $obligationId = (int)$obligations[0]['id'];
        $receiptIds = [];
        foreach ($periodIds as $periodId) {
            $receiptIds[] = (int)$receiptByPeriod[$periodId]['id'];
        }
        sort($receiptIds, SORT_NUMERIC);
        $now = $this->dbDate($this->now());
        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_obligations
             SET status = :status, checked_at = :checked_at, source_reference = :source_reference
             WHERE id = :id AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'status' => 'filed',
                'checked_at' => $now,
                'source_reference' => 'hmrc_ct600_live:' . implode(',', $receiptIds),
                'id' => $obligationId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        foreach ($receiptIds as $receiptId) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO hmrc_obligation_submission_links (hmrc_obligation_id, submission_id)
                 SELECT :hmrc_obligation_id, :submission_id
                 WHERE NOT EXISTS (
                    SELECT 1 FROM hmrc_obligation_submission_links
                    WHERE hmrc_obligation_id = :hmrc_obligation_id
                      AND submission_id = :submission_id
                 )',
                ['hmrc_obligation_id' => $obligationId, 'submission_id' => $receiptId]
            );
        }
    }

    /** @param array<string, mixed> $submission */
    private function assertFinalLiveOriginal(array $submission): void
    {
        if ((string)($submission['environment'] ?? '') !== 'LIVE'
            || (string)($submission['submission_type'] ?? '') !== 'original'
            || (string)($submission['status'] ?? '') !== 'accepted'
            || (string)($submission['business_outcome'] ?? '') !== 'live_accepted'
            || !in_array(
                (string)($submission['protocol_state'] ?? ''),
                ['final_received', 'delete_pending', 'closed'],
                true
            )
            || (int)($submission['ct_period_id'] ?? 0) <= 0
            || trim((string)($submission['final_response_at'] ?? '')) === ''
            || trim((string)($submission['response_body_path'] ?? '')) === ''
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                strtolower(trim((string)($submission['response_sha256'] ?? '')))
            ) !== 1
        ) {
            throw new \DomainException(
                'Only a final LIVE accepted original with a preserved HMRC receipt can update statutory filing state.'
            );
        }
    }

    private function preserveFailureState(int $submissionId, \Throwable $exception): void
    {
        try {
            $row = $this->repository()->fetchById($submissionId);
            if (!is_array($row)
                || (string)($row['environment'] ?? '') !== 'LIVE'
                || (string)($row['submission_type'] ?? '') !== 'original'
                || (string)($row['business_outcome'] ?? '') !== 'live_accepted'
                || (string)($row['statutory_sync_state'] ?? '') === 'applied'
            ) {
                return;
            }
            $this->repository()->markStatutorySyncFailed(
                $submissionId,
                (int)$row['company_id'],
                $exception->getMessage()
            );
        } catch (\Throwable) {
            // Preserve the original projection exception. The immutable LIVE
            // outcome and final receipt remain available for operator repair.
        }
    }

    private function requireSchema(): void
    {
        $this->repository()->requireSchema();
        if (!\InterfaceDB::tableExists('hmrc_obligation_submission_links')
            || !\InterfaceDB::columnsExists(
                'hmrc_obligation_submission_links',
                ['hmrc_obligation_id', 'submission_id']
            )
        ) {
            throw new \RuntimeException(
                'The HMRC CT600 statutory projection migration has not been applied.'
            );
        }
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $params): array
    {
        $rows = \InterfaceDB::prepareExecute($sql, $params)->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function repository(): HmrcCtSubmissionRepository
    {
        return $this->repository ??= new HmrcCtSubmissionRepository();
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->clock instanceof \Closure
            ? ($this->clock)()
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (!$now instanceof \DateTimeImmutable) {
            throw new \RuntimeException('The statutory projection clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new \DateTimeZone('UTC'));
    }

    private function dbDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function forUpdateSuffix(): string
    {
        return \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    /**
     * InterfaceDB deliberately joins an existing transaction. Use a savepoint
     * in that case so projection failures still roll back every CT/obligation
     * mutation before the separately recorded retry state is written.
     */
    private function projectionTransaction(callable $callback): mixed
    {
        if (!\InterfaceDB::inTransaction()) {
            return \InterfaceDB::transaction($callback);
        }

        $savepoint = 'ct600_statutory_projection';
        \InterfaceDB::prepareExecute('SAVEPOINT ' . $savepoint);
        try {
            $result = $callback();
            \InterfaceDB::prepareExecute('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $exception) {
            \InterfaceDB::prepareExecute('ROLLBACK TO SAVEPOINT ' . $savepoint);
            \InterfaceDB::prepareExecute('RELEASE SAVEPOINT ' . $savepoint);
            throw $exception;
        }
    }
}
