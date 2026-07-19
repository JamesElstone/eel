<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Read-only CT600 filing-stage readiness signals. */
final class Ct600FilingReadinessService
{
    /**
     * @param null|\Closure(string, string): array<string, mixed> $rimResolver
     * @param null|\Closure(int, int): array<string, mixed> $accountsLocator
     * @param null|\Closure(string): array<string, mixed> $credentialChecker
     */
    public function __construct(
        private readonly ?\Closure $rimResolver = null,
        private readonly ?\Closure $accountsLocator = null,
        private readonly ?\Closure $credentialChecker = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $ctPeriods
     * @param array<string, mixed> $company
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function fetch(
        int $companyId,
        int $accountingPeriodId,
        array $ctPeriods,
        array $company,
        array $settings
    ): array {
        $rimPeriods = [];
        foreach ($ctPeriods as $period) {
            $start = (string)($period['period_start'] ?? '');
            $end = (string)($period['period_end'] ?? '');
            $result = $this->resolveRim($start, $end);
            $rimPeriods[] = [
                'ct_period_id' => (int)($period['ct_period_id'] ?? $period['id'] ?? 0),
                'period_start' => $start,
                'period_end' => $end,
                'ok' => !empty($result['ok']),
                'form_version' => (string)($result['form_version'] ?? ''),
                'artifact_version' => (string)($result['artifact_version'] ?? ''),
                'errors' => array_values(array_map('strval', (array)($result['errors'] ?? []))),
            ];
        }
        $rimReady = $rimPeriods !== [] && !in_array(false, array_column($rimPeriods, 'ok'), true);

        $companyNumber = trim((string)($company['company_number'] ?? ''));
        $utr = trim((string)($settings['utr'] ?? ''));
        $identityMissing = [];
        if ($companyNumber === '') {
            $identityMissing[] = 'company number';
        }
        if ($utr === '') {
            $identityMissing[] = 'Corporation Tax UTR';
        }

        $accounts = $this->locateAccounts($companyId, $accountingPeriodId);
        $credentials = $this->checkCredentials('TEST');

        return [
            'rim' => [
                'label' => 'HMRC CT600 RIM availability',
                'ready' => $rimReady,
                'periods' => $rimPeriods,
                'detail' => $rimReady
                    ? 'A live HMRC CT600 RIM package resolves for every CT period.'
                    : $this->firstPeriodError($rimPeriods, 'No live HMRC CT600 RIM package resolves for every CT period.'),
            ],
            'identity' => [
                'label' => 'CT600 submission identity',
                'ready' => $identityMissing === [],
                'missing' => $identityMissing,
                'detail' => $identityMissing === []
                    ? 'The company number and Corporation Tax UTR are present. Filing-stage format and cross-document validation still apply.'
                    : 'Missing submission identity data: ' . implode(', ', $identityMissing) . '.',
            ],
            'ixbrl' => [
                'label' => 'Accounts and computations iXBRL artifacts',
                'ready' => false,
                'accounts_ready' => !empty($accounts['ok']),
                'accounts' => $accounts,
                'computations_ready' => false,
                'detail' => (!empty($accounts['ok'])
                    ? 'The accounts iXBRL artifact is ready. '
                    : (string)(($accounts['errors'] ?? [])[0] ?? 'The accounts iXBRL artifact is not ready.') . ' ')
                    . 'Computations iXBRL generation is not yet configured.',
            ],
            'attachments' => [
                'label' => 'CT600 attachment choices',
                'ready' => false,
                'detail' => 'CT600 accounts and computations attachment choices are not yet configured.',
            ],
            'approval_transport' => [
                'label' => 'CT600 approval and transport',
                'ready' => false,
                'credentials_ready' => !empty($credentials['ok']),
                'credentials' => $credentials,
                'detail' => (!empty($credentials['ok'])
                    ? 'TEST filing credentials are available. '
                    : 'TEST filing credentials are not available. ')
                    . 'Declaration and repayment instructions are not yet configured.',
            ],
        ];
    }

    private function resolveRim(string $periodStart, string $periodEnd): array
    {
        if ($this->rimResolver !== null) {
            return ($this->rimResolver)($periodStart, $periodEnd);
        }
        return (new HmrcCt600VersionService())->resolveForCtPeriod($periodStart, $periodEnd);
    }

    private function locateAccounts(int $companyId, int $accountingPeriodId): array
    {
        if ($this->accountsLocator !== null) {
            return ($this->accountsLocator)($companyId, $accountingPeriodId);
        }
        return (new IxbrlFilingArtifactService())->locate($companyId, $accountingPeriodId);
    }

    private function checkCredentials(string $mode): array
    {
        if ($this->credentialChecker !== null) {
            return ($this->credentialChecker)($mode);
        }
        return (new \eel_accounts\Client\HmrcApiClient())->credentialsConfigured($mode);
    }

    /** @param list<array<string, mixed>> $periods */
    private function firstPeriodError(array $periods, string $fallback): string
    {
        foreach ($periods as $period) {
            $error = trim((string)(($period['errors'] ?? [])[0] ?? ''));
            if ($error !== '') {
                return $error;
            }
        }
        return $fallback;
    }
}
