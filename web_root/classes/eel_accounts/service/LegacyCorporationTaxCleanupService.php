<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class LegacyCorporationTaxCleanupService
{
    public function preflight(): array
    {
        $requiredTables = [
            'corporation_tax_periods',
            'corporation_tax_computation_runs',
            'hmrc_ct600_submissions',
            'hmrc_submission_events',
            'year_end_reviews',
            'tax_loss_carryforwards',
            'tax_loss_movement_history',
        ];
        $missing = array_values(array_filter(
            $requiredTables,
            static fn(string $table): bool => !\InterfaceDB::tableExists($table)
        ));
        if ($missing !== []) {
            return [
                'success' => false,
                'errors' => ['Required cleanup tables are missing: ' . implode(', ', $missing) . '.'],
            ];
        }

        $lockedRunCount = (int)(\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM corporation_tax_computation_runs cr
             INNER JOIN year_end_reviews yer
               ON yer.company_id = cr.company_id
              AND yer.accounting_period_id = cr.accounting_period_id
              AND yer.is_locked = 1'
        ) ?: 0);
        if ($lockedRunCount > 0) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because ' . $lockedRunCount . ' computation run(s) belong to locked accounting periods.'],
            ];
        }

        $submissions = \InterfaceDB::fetchAll('SELECT * FROM hmrc_ct600_submissions ORDER BY id');
        $submissionIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $submissions);
        if ($submissionIds !== [] && $submissionIds !== [1, 2]) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because the HMRC submission rows are not exactly the confirmed legacy artefacts 1 and 2.'],
            ];
        }
        foreach ($submissions as $submission) {
            if (!$this->isValidationOnlyArtifact((array)$submission)) {
                return [
                    'success' => false,
                    'errors' => ['Cleanup refused because HMRC submission row ' . (int)($submission['id'] ?? 0) . ' contains evidence beyond a local TEST validation failure.'],
                ];
            }
        }

        $eventCount = (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_submission_events') ?: 0);
        $orphanEventCount = (int)(\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM hmrc_submission_events hse
             LEFT JOIN hmrc_ct600_submissions submission ON submission.id = hse.submission_id
             WHERE submission.id IS NULL'
        ) ?: 0);
        if ($orphanEventCount > 0) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because HMRC submission event history exists outside the validated TEST artefacts.'],
            ];
        }
        $events = \InterfaceDB::fetchAll('SELECT * FROM hmrc_submission_events ORDER BY id');
        $eventIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $events);
        if ($eventIds !== [] && $eventIds !== [1, 2, 3, 4]) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because the HMRC event rows are not exactly the four confirmed local validation events.'],
            ];
        }
        foreach ($events as $event) {
            if (!$this->isValidationOnlyEvent((array)$event)) {
                return [
                    'success' => false,
                    'errors' => ['Cleanup refused because HMRC submission event ' . (int)($event['id'] ?? 0) . ' is not a confirmed local validation event.'],
                ];
            }
        }

        $runCount = (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_computation_runs') ?: 0);
        if (!in_array($runCount, [0, 78], true)) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because the computation-run count no longer matches the confirmed legacy set of 78 rows.'],
            ];
        }
        if ($runCount === 78) {
            $runDistribution = \InterfaceDB::fetchAll(
                'SELECT company_id, accounting_period_id, COUNT(*) AS run_count
                 FROM corporation_tax_computation_runs
                 GROUP BY company_id, accounting_period_id
                 ORDER BY company_id, accounting_period_id'
            );
            $runDistribution = array_map(static fn(array $row): array => [
                'company_id' => (int)($row['company_id'] ?? 0),
                'accounting_period_id' => (int)($row['accounting_period_id'] ?? 0),
                'run_count' => (int)($row['run_count'] ?? 0),
            ], $runDistribution);
            if ($runDistribution !== [
                ['company_id' => 49, 'accounting_period_id' => 79, 'run_count' => 76],
                ['company_id' => 49, 'accounting_period_id' => 80, 'run_count' => 2],
            ]) {
                return [
                    'success' => false,
                    'errors' => ['Cleanup refused because the computation runs no longer match the confirmed AP79/AP80 distribution.'],
                ];
            }
        }

        $carryforwards = \InterfaceDB::fetchAll('SELECT * FROM tax_loss_carryforwards ORDER BY id');
        $movements = \InterfaceDB::fetchAll('SELECT * FROM tax_loss_movement_history ORDER BY id');
        if (($carryforwards !== [] || $movements !== [])
            && !$this->isExpectedLegacyLossData($carryforwards, $movements)) {
            return [
                'success' => false,
                'errors' => ['Cleanup refused because the persisted Corporation Tax loss rows no longer match the authorised legacy set.'],
            ];
        }

        foreach ([79, 80, 81, 82] as $accountingPeriodId) {
            if ((new \eel_accounts\Service\YearEndLockService())->isLocked(49, $accountingPeriodId)) {
                return [
                    'success' => false,
                    'errors' => ['Cleanup refused because accounting period ' . $accountingPeriodId . ' represented in the authorised tax-loss history is locked.'],
                ];
            }
        }

        return [
            'success' => true,
            'errors' => [],
            'run_count' => $runCount,
            'submission_artifact_count' => count($submissions),
            'submission_event_count' => $eventCount,
            'tax_loss_carryforward_count' => count($carryforwards),
            'tax_loss_movement_count' => count($movements),
        ];
    }

    public function cleanup(): array
    {
        if (\InterfaceDB::inTransaction()) {
            return [
                'success' => false,
                'errors' => ['Legacy Corporation Tax cleanup requires a dedicated database transaction.'],
            ];
        }

        \InterfaceDB::beginTransaction();
        try {
            $preflight = $this->preflight();
            if (empty($preflight['success'])) {
                \InterfaceDB::rollBack();
                return $preflight;
            }

            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET latest_submission_id = NULL
                 WHERE latest_submission_id IS NOT NULL'
            );
            \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct600_submissions');
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET latest_computation_run_id = NULL
                 WHERE latest_computation_run_id IS NOT NULL'
            );
            \InterfaceDB::prepareExecute('DELETE FROM tax_loss_movement_history');
            \InterfaceDB::prepareExecute('DELETE FROM tax_loss_carryforwards');
            \InterfaceDB::prepareExecute('DELETE FROM corporation_tax_computation_runs');
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET status = :pending
                 WHERE status = :computed
                   AND latest_computation_run_id IS NULL',
                ['pending' => 'pending', 'computed' => 'computed']
            );

            $verification = $this->verification();
            if (empty($verification['success'])) {
                throw new \RuntimeException(implode(' ', (array)($verification['errors'] ?? ['Cleanup verification failed.'])));
            }

            \InterfaceDB::commit();

            return [
                'success' => true,
                'errors' => [],
                'deleted_computation_runs' => (int)($preflight['run_count'] ?? 0),
                'deleted_submission_artifacts' => (int)($preflight['submission_artifact_count'] ?? 0),
                'deleted_submission_events' => (int)($preflight['submission_event_count'] ?? 0),
                'deleted_tax_loss_carryforwards' => (int)($preflight['tax_loss_carryforward_count'] ?? 0),
                'deleted_tax_loss_movements' => (int)($preflight['tax_loss_movement_count'] ?? 0),
                'verification' => $verification,
            ];
        } catch (\Throwable $exception) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    public function isValidationOnlyArtifact(array $submission): bool
    {
        if ((string)($submission['mode'] ?? '') !== 'TEST'
            || (string)($submission['status'] ?? '') !== 'validation_failed') {
            return false;
        }

        foreach ([
            'ct600_xml_path',
            'accounts_ixbrl_path',
            'computations_ixbrl_path',
            'package_hash',
            'hmrc_submission_reference',
            'hmrc_correlation_id',
            'hmrc_response_code',
            'hmrc_response_summary',
            'response_headers_json',
            'request_body_path',
            'response_body_path',
            'submitted_by',
            'submitted_at',
        ] as $field) {
            if (trim((string)($submission[$field] ?? '')) !== '') {
                return false;
            }
        }

        $requestHeaders = trim((string)($submission['request_headers_json'] ?? ''));
        if ($requestHeaders !== '') {
            $headers = json_decode($requestHeaders, true);
            if (!is_array($headers)
                || (string)($headers['X-EEL-HMRC-Mode'] ?? '') !== 'TEST'
                || array_key_exists('Authorization', $headers)) {
                return false;
            }
        }

        $validation = json_decode((string)($submission['validation_json'] ?? ''), true);
        if (!is_array($validation)
            || (string)($validation['mode'] ?? '') !== 'TEST'
            || !array_key_exists('ok', $validation)
            || $validation['ok'] !== false) {
            return false;
        }

        return true;
    }

    public function isValidationOnlyEvent(array $event): bool
    {
        $message = (string)($event['event_message'] ?? '');
        if (!in_array($message, ['Submission draft created.', 'Package validation failed.'], true)) {
            return false;
        }

        $context = json_decode((string)($event['event_context_json'] ?? ''), true);
        return is_array($context) && (string)($context['mode'] ?? '') === 'TEST';
    }

    public function isExpectedLegacyLossData(array $carryforwards, array $movements): bool
    {
        if (count($carryforwards) !== 1 || count($movements) !== 23) {
            return false;
        }

        $carryforward = (array)$carryforwards[0];
        if ((int)($carryforward['id'] ?? 0) !== 248
            || (int)($carryforward['company_id'] ?? 0) !== 49
            || (int)($carryforward['origin_accounting_period_id'] ?? 0) !== 79
            || (int)($carryforward['origin_ct_period_id'] ?? 0) !== 0
            || number_format((float)($carryforward['amount_originated'] ?? 0), 2, '.', '') !== '697.58'
            || number_format((float)($carryforward['amount_used'] ?? 0), 2, '.', '') !== '0.00'
            || number_format((float)($carryforward['amount_remaining'] ?? 0), 2, '.', '') !== '697.58'
            || (string)($carryforward['status'] ?? '') !== 'open') {
            return false;
        }

        $expected = [
            1397 => [79, 0], 1405 => [79, 0], 1406 => [80, 0], 1407 => [81, 0],
            1408 => [82, 0], 1413 => [79, 0], 1414 => [80, 0], 1415 => [81, 0],
            1416 => [82, 0], 1421 => [79, 0], 1422 => [80, 0], 1423 => [81, 0],
            1424 => [82, 0], 2297 => [79, 0], 2301 => [79, 0], 2356 => [80, 8],
            2387 => [79, 0], 2388 => [80, 0], 2389 => [81, 0], 2390 => [82, 0],
            2432 => [79, 7], 2434 => [79, 6], 2436 => [79, 7],
        ];
        foreach ($movements as $movement) {
            $movement = (array)$movement;
            $id = (int)($movement['id'] ?? 0);
            if (!isset($expected[$id])
                || (int)($movement['company_id'] ?? 0) !== 49
                || (int)($movement['accounting_period_id'] ?? 0) !== $expected[$id][0]
                || (int)($movement['ct_period_id'] ?? 0) !== $expected[$id][1]
                || trim((string)($movement['computation_hash'] ?? '')) === '') {
                return false;
            }
            unset($expected[$id]);
        }

        return $expected === [];
    }

    private function verification(): array
    {
        $counts = [
            'computation_runs' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_computation_runs') ?: 0),
            'submission_artifacts' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_ct600_submissions') ?: 0),
            'submission_events' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_submission_events') ?: 0),
            'run_pointers' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_periods WHERE latest_computation_run_id IS NOT NULL') ?: 0),
            'submission_pointers' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_periods WHERE latest_submission_id IS NOT NULL') ?: 0),
            'computed_without_run' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM corporation_tax_periods WHERE status = :status AND latest_computation_run_id IS NULL', ['status' => 'computed']) ?: 0),
            'tax_loss_carryforwards' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM tax_loss_carryforwards') ?: 0),
            'tax_loss_movements' => (int)(\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM tax_loss_movement_history') ?: 0),
        ];
        $failed = array_filter($counts, static fn(int $count): bool => $count !== 0);

        $periodRows = \InterfaceDB::fetchAll(
            'SELECT id, status
             FROM corporation_tax_periods
             WHERE id IN (6, 7, 8, 9)
             ORDER BY id'
        );
        $expectedStatuses = [6 => 'pending', 7 => 'pending', 8 => 'pending', 9 => 'pending'];
        foreach ($periodRows as $periodRow) {
            $id = (int)($periodRow['id'] ?? 0);
            if (!isset($expectedStatuses[$id]) || (string)($periodRow['status'] ?? '') !== $expectedStatuses[$id]) {
                $failed['ct_period_' . $id . '_status'] = 1;
            }
            unset($expectedStatuses[$id]);
        }
        if ($expectedStatuses !== []) {
            $failed['missing_ct_periods'] = count($expectedStatuses);
        }

        return [
            'success' => $failed === [],
            'errors' => $failed === [] ? [] : ['Cleanup left legacy Corporation Tax rows or pointers behind.'],
            'counts' => $counts,
        ];
    }
}
