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
 * Read-only projection for the Corporation Tax filing page.
 *
 * Deliberately does not initialise schemas, synchronise CT periods, prepare
 * packages, or contact HMRC. Those operations belong to migrations and the
 * submission orchestrator respectively.
 */
final class HmrcCtSubmissionReadModel
{
    private ?\Closure $periodResolver;
    private ?\Closure $readinessResolver;
    private ?\Closure $historyResolver;
    private ?\Closure $eventResolver;
    private ?\Closure $environmentResolver;

    public function __construct(
        ?callable $periodResolver = null,
        ?callable $readinessResolver = null,
        ?callable $historyResolver = null,
        ?callable $eventResolver = null,
        ?callable $environmentResolver = null,
    ) {
        $this->periodResolver = $periodResolver !== null ? \Closure::fromCallable($periodResolver) : null;
        $this->readinessResolver = $readinessResolver !== null ? \Closure::fromCallable($readinessResolver) : null;
        $this->historyResolver = $historyResolver !== null ? \Closure::fromCallable($historyResolver) : null;
        $this->eventResolver = $eventResolver !== null ? \Closure::fromCallable($eventResolver) : null;
        $this->environmentResolver = $environmentResolver !== null ? \Closure::fromCallable($environmentResolver) : null;
    }

    /** @return array<string, mixed> */
    public function pageState(int $companyId, int $accountingPeriodId, int $selectedCtPeriodId = 0): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->emptyState($companyId, $accountingPeriodId, 'Select a company and accounting period.');
        }

        $periods = $this->periods($companyId, $accountingPeriodId);
        $history = $this->history($companyId, $accountingPeriodId);
        $selectedCtPeriodId = $this->selectedCtPeriodId($periods, $history, $selectedCtPeriodId);
        $selectedPeriod = $this->selectedPeriod($periods, $selectedCtPeriodId);
        $environment = $this->environment();

        try {
            $readiness = $selectedCtPeriodId > 0
                ? $this->readiness($companyId, $accountingPeriodId, $selectedCtPeriodId, $environment)
                : $this->unavailableReadiness('No Corporation Tax period is available for this accounting period.');
        } catch (\Throwable $exception) {
            $readiness = $this->unavailableReadiness($exception->getMessage());
        }

        // Lifecycle buttons must bind to the newest package in the current
        // server-controlled environment. A newer TEST/TIL history row must
        // not hide an older open LIVE/TIL conversation (or vice versa).
        $latest = $this->latestForPeriod($history, $selectedCtPeriodId, $environment);
        $events = $this->events((int)($latest['id'] ?? 0));
        $capabilities = $this->capabilities($readiness, $latest, $environment);

        return [
            'available' => $periods !== [],
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'environment' => $environment,
            'environment_label' => $this->environmentLabel($environment),
            'environment_notice' => $this->environmentNotice($environment),
            'ct_periods' => $periods,
            'selected_ct_period_id' => $selectedCtPeriodId,
            'selected_period' => $selectedPeriod,
            'readiness' => $readiness,
            'latest_submission' => $latest,
            'history' => $history,
            'events' => $events,
            'progress' => $this->progress($periods, $history, $selectedCtPeriodId),
            'capabilities' => $capabilities,
            'errors' => (array)($readiness['blockers'] ?? []),
            'warnings' => (array)($readiness['warnings'] ?? []),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function periods(int $companyId, int $accountingPeriodId): array
    {
        if ($this->periodResolver !== null) {
            return $this->arrayRows(($this->periodResolver)($companyId, $accountingPeriodId));
        }

        return $this->arrayRows(
            (new CorporationTaxPeriodService())->fetchExistingForAccountingPeriod($companyId, $accountingPeriodId)
        );
    }

    /** @return array<string, mixed> */
    private function readiness(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $environment
    ): array {
        if ($this->readinessResolver !== null) {
            $result = ($this->readinessResolver)($companyId, $accountingPeriodId, $ctPeriodId, $environment);
            return is_array($result) ? $result : $this->unavailableReadiness('The filing readiness service returned an invalid result.');
        }

        if (!class_exists(Ct600FilingReadinessService::class)) {
            return $this->unavailableReadiness('The CT600 filing readiness service is not installed.');
        }

        return (new Ct600FilingReadinessService())->assess(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $environment
        );
    }

    /** @return list<array<string, mixed>> */
    private function history(int $companyId, int $accountingPeriodId): array
    {
        if ($this->historyResolver !== null) {
            return $this->arrayRows(($this->historyResolver)($companyId, $accountingPeriodId));
        }
        if (!\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            return [];
        }

        return $this->arrayRows(\InterfaceDB::fetchAll(
            'SELECT s.*,
                    c.company_name,
                    ap.label AS accounting_period_label,
                    ctp.sequence_no AS ct_period_sequence_no,
                    ctp.period_start,
                    ctp.period_end
             FROM hmrc_ct600_submissions s
             INNER JOIN companies c ON c.id = s.company_id
             INNER JOIN accounting_periods ap ON ap.id = s.accounting_period_id
             LEFT JOIN corporation_tax_periods ctp ON ctp.id = s.ct_period_id
             WHERE s.company_id = :company_id
               AND s.accounting_period_id = :accounting_period_id
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 100',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ));
    }

    /** @return list<array<string, mixed>> */
    private function events(int $submissionId): array
    {
        if ($submissionId <= 0) {
            return [];
        }
        if ($this->eventResolver !== null) {
            return $this->arrayRows(($this->eventResolver)($submissionId));
        }
        if (!\InterfaceDB::tableExists('hmrc_submission_events')) {
            return [];
        }

        return $this->arrayRows(\InterfaceDB::fetchAll(
            'SELECT id, submission_id, event_level, event_message, event_context_json, created_at
             FROM hmrc_submission_events
             WHERE submission_id = :submission_id
             ORDER BY id ASC
             LIMIT 250',
            ['submission_id' => $submissionId]
        ));
    }

    private function environment(): string
    {
        if ($this->environmentResolver !== null) {
            return $this->normaliseEnvironment(($this->environmentResolver)());
        }

        if (class_exists(HmrcCtSubmissionOrchestrator::class)) {
            try {
                return $this->normaliseEnvironment((new HmrcCtSubmissionOrchestrator())->environment());
            } catch (\Throwable) {
            }
        }

        if (class_exists(HmrcCtConfigurationService::class)) {
            try {
                return $this->normaliseEnvironment((new HmrcCtConfigurationService())->environment());
            } catch (\Throwable) {
            }
        }

        $config = (array)\AppConfigurationStore::get('hmrc.ct600', []);
        return $this->normaliseEnvironment($config['environment'] ?? $config['mode'] ?? 'TEST');
    }

    private function normaliseEnvironment(mixed $value): string
    {
        $environment = strtoupper(trim((string)$value));
        return in_array($environment, ['TEST', 'TIL', 'LIVE'], true) ? $environment : 'TEST';
    }

    /** @param list<array<string, mixed>> $periods @param list<array<string, mixed>> $history */
    private function selectedCtPeriodId(array $periods, array $history, int $requestedId): int
    {
        foreach ($periods as $period) {
            if ((int)($period['id'] ?? 0) === $requestedId) {
                return $requestedId;
            }
        }

        foreach ($periods as $period) {
            $periodId = (int)($period['id'] ?? 0);
            if ($periodId > 0 && !$this->hasLiveAcceptance($history, $periodId)) {
                return $periodId;
            }
        }

        return (int)($periods[0]['id'] ?? 0);
    }

    /** @param list<array<string, mixed>> $periods @return array<string, mixed> */
    private function selectedPeriod(array $periods, int $selectedCtPeriodId): array
    {
        foreach ($periods as $period) {
            if ((int)($period['id'] ?? 0) === $selectedCtPeriodId) {
                return $period;
            }
        }

        return [];
    }

    /** @param list<array<string, mixed>> $history @return array<string, mixed> */
    private function latestForPeriod(array $history, int $ctPeriodId, ?string $environment = null): array
    {
        foreach ($history as $row) {
            if ((int)($row['ct_period_id'] ?? 0) === $ctPeriodId) {
                if ($environment !== null && $this->submissionEnvironment($row) !== $environment) {
                    continue;
                }
                return $row;
            }
        }

        return [];
    }

    /** @param list<array<string, mixed>> $periods @param list<array<string, mixed>> $history @return list<array<string, mixed>> */
    private function progress(array $periods, array $history, int $selectedCtPeriodId): array
    {
        $steps = [];
        foreach ($periods as $period) {
            $periodId = (int)($period['id'] ?? 0);
            $latest = $this->latestForPeriod($history, $periodId);
            $liveAccepted = $this->hasLiveAcceptance($history, $periodId);
            $outcome = $this->businessOutcome($latest);
            $environment = $this->submissionEnvironment($latest);
            $protocol = $this->protocolState($latest);
            $state = $liveAccepted
                ? 'filed'
                : ((in_array($outcome, ['accepted', 'til_validated'], true) && $environment === 'TIL')
                    ? 'til_validated'
                    : ((in_array($outcome, ['accepted', 'test_accepted', 'sandbox_passed'], true) && $environment === 'TEST')
                        ? 'test_passed'
                        : ($protocol !== '' ? $protocol : (string)($period['status'] ?? 'pending'))));

            $steps[] = [
                'ct_period_id' => $periodId,
                'sequence_no' => (int)($period['display_sequence_no'] ?? $period['sequence_no'] ?? 0),
                'label' => (string)($period['display_label'] ?? ('CT Period ' . (int)($period['sequence_no'] ?? 0))),
                'period_start' => (string)($period['period_start'] ?? ''),
                'period_end' => (string)($period['period_end'] ?? ''),
                'selected' => $periodId === $selectedCtPeriodId,
                'state' => $state,
                'live_accepted' => $liveAccepted,
                'latest_environment' => $environment,
                'latest_outcome' => $outcome,
            ];
        }

        return $steps;
    }

    /** @param array<string, mixed> $readiness @param array<string, mixed> $latest @return array<string, bool> */
    private function capabilities(array $readiness, array $latest, string $environment): array
    {
        $protocol = $this->protocolState($latest);
        $outcome = $this->businessOutcome($latest);
        $latestEnvironment = $this->submissionEnvironment($latest);
        $approved = $this->isApproved($latest);
        $prepared = in_array($protocol, ['prepared', 'ready'], true)
            || in_array((string)($latest['status'] ?? ''), ['ready', 'prepared'], true);
        $awaitingPoll = in_array($protocol, ['acknowledged', 'awaiting_poll', 'polling'], true);
        $pollDue = $awaitingPoll && $this->isPollDue((string)($latest['next_poll_at'] ?? ''));
        $recoveryRequired = $protocol === 'transport_uncertain';
        $cleanupRequired = !empty($latest['cleanup_required'])
            || in_array($protocol, ['final_received', 'final_response', 'delete_pending', 'cleanup_pending'], true);
        $sameEnvironmentOpenPackage = $latestEnvironment === $environment
            && in_array($protocol, [
                'prepared',
                'ready',
                'submitting',
                'acknowledged',
                'awaiting_poll',
                'polling',
                'final_received',
                'final_response',
                'delete_pending',
                'cleanup_pending',
                'transport_uncertain',
            ], true);
        $alreadyLiveFiled = $latestEnvironment === 'LIVE'
            && in_array($outcome, ['accepted', 'live_accepted'], true);

        $readinessAllowsSubmit = array_key_exists('can_submit', $readiness)
            ? !empty($readiness['can_submit'])
            : (!empty($readiness['can_prepare']) || !empty($readiness['ok']));

        return [
            'can_prepare' => (!empty($readiness['can_prepare']) || !empty($readiness['ok']))
                && !$sameEnvironmentOpenPackage
                && !$alreadyLiveFiled,
            'can_approve' => $prepared && !$approved,
            'can_submit' => $prepared && $approved && $outcome === '' && $readinessAllowsSubmit,
            'can_poll' => $pollDue && $outcome === '',
            'poll_due' => $pollDue,
            'can_recover' => $recoveryRequired && $outcome === '',
            'can_delete' => $cleanupRequired || $recoveryRequired,
            'approved' => $approved,
        ];
    }

    private function isPollDue(string $nextPollAt): bool
    {
        $nextPollAt = trim($nextPollAt);
        if ($nextPollAt === '') {
            return false;
        }

        try {
            $due = new \DateTimeImmutable($nextPollAt, new \DateTimeZone('UTC'));
            return $due <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<array<string, mixed>> $history */
    private function hasLiveAcceptance(array $history, int $ctPeriodId): bool
    {
        foreach ($history as $row) {
            if ((int)($row['ct_period_id'] ?? 0) !== $ctPeriodId) {
                continue;
            }
            if ($this->submissionEnvironment($row) === 'LIVE'
                && in_array($this->businessOutcome($row), ['accepted', 'live_accepted'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $submission */
    private function submissionEnvironment(array $submission): string
    {
        $value = trim((string)($submission['environment'] ?? $submission['mode'] ?? ''));
        return $value === '' ? '' : $this->normaliseEnvironment($value);
    }

    /** @param array<string, mixed> $submission */
    private function protocolState(array $submission): string
    {
        return strtolower(trim((string)($submission['protocol_state'] ?? $submission['status'] ?? '')));
    }

    /** @param array<string, mixed> $submission */
    private function businessOutcome(array $submission): string
    {
        $outcome = strtolower(trim((string)($submission['business_outcome'] ?? $submission['outcome'] ?? '')));
        if ($outcome === 'none') {
            return '';
        }
        if ($outcome !== '') {
            return $outcome;
        }

        $legacy = strtolower(trim((string)($submission['status'] ?? '')));
        return in_array($legacy, ['accepted', 'rejected'], true) ? $legacy : '';
    }

    /** @param array<string, mixed> $submission */
    private function isApproved(array $submission): bool
    {
        foreach (['approved_at', 'declaration_confirmed_at', 'declaration_approved_at'] as $field) {
            if (trim((string)($submission[$field] ?? '')) !== '') {
                return true;
            }
        }

        return !empty($submission['declaration_confirmed']);
    }

    /** @return array<string, mixed> */
    private function unavailableReadiness(string $message): array
    {
        $message = trim($message) !== '' ? trim($message) : 'CT600 filing readiness could not be evaluated.';
        return [
            'ok' => false,
            'can_prepare' => false,
            'checks' => [[
                'key' => 'readiness_service',
                'label' => 'CT600 filing readiness',
                'complete' => false,
                'blocking' => true,
                'detail' => $message,
            ]],
            'blockers' => [$message],
            'warnings' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyState(int $companyId, int $accountingPeriodId, string $message): array
    {
        return [
            'available' => false,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'environment' => 'TEST',
            'environment_label' => 'External Test Service',
            'environment_notice' => $this->environmentNotice('TEST'),
            'ct_periods' => [],
            'selected_ct_period_id' => 0,
            'selected_period' => [],
            'readiness' => $this->unavailableReadiness($message),
            'latest_submission' => [],
            'history' => [],
            'events' => [],
            'progress' => [],
            'capabilities' => [
                'can_prepare' => false,
                'can_approve' => false,
                'can_submit' => false,
                'can_poll' => false,
                'poll_due' => false,
                'can_recover' => false,
                'can_delete' => false,
                'approved' => false,
            ],
            'errors' => [$message],
            'warnings' => [],
        ];
    }

    private function environmentLabel(string $environment): string
    {
        return match ($environment) {
            'TIL' => 'Test in Live',
            'LIVE' => 'Live filing',
            default => 'External Test Service',
        };
    }

    private function environmentNotice(string $environment): string
    {
        return match ($environment) {
            'TIL' => 'TIL validates this real-company package through the live gateway but does not register a statutory filing.',
            'LIVE' => 'LIVE sends a statutory Company Tax Return. HMRC acceptance will update the CT filing state.',
            default => 'TEST is for deterministic synthetic company data only. Do not send AP79 or other live customer data to ETS.',
        };
    }

    /** @return list<array<string, mixed>> */
    private function arrayRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }
}
