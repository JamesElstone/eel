<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_submission extends PageContextFramework
{
    public function id(): string { return 'hmrc_submission'; }

    public function title(): string { return 'HMRC Submission'; }

    public function subtitle(): string { return 'Validate, audit, and submit Corporation Tax packages with a visible diagnostic log.'; }

    public function cards(): array
    {
        return ['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log', 'hmrc_submission_history'];
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'Submit', 'cards' => ['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log']],
            ['tab' => 'History', 'cards' => ['hmrc_submission_history']],
        ];
    }

    protected function moduleContext(RequestFramework $request, PageServiceFramework $services, ActionResultFramework $actionResult, array $baseContext): array
    {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $service = new HmrcCorporationTaxSubmissionService();
        $service->ensureSchema();
        $mode = $companyId > 0 ? (new hmrcService())->resolveHmrcMode($companyId) : 'TEST';
        $package = new HmrcSubmissionPackageService();
        $settings = $companyId > 0 ? (new CompanySettingsStore($companyId))->all() : [];
        $ctPeriodService = new CorporationTaxPeriodService();
        $sync = $companyId > 0 && $accountingPeriodId > 0
            ? $ctPeriodService->syncForAccountingPeriod($companyId, $accountingPeriodId)
            : ['periods' => []];
        $ctPeriods = (array)($sync['periods'] ?? []);
        $selectedCtPeriodId = $ctPeriodService->defaultCtPeriodId($companyId, $accountingPeriodId);

        return [
            'hmrc_submission' => [
                'mode' => $mode,
                'settings' => $settings,
                'ct_periods' => $ctPeriods,
                'selected_ct_period_id' => $selectedCtPeriodId,
                'accounts_ixbrl' => $package->locateAccountsIxbrl($companyId, $accountingPeriodId),
                'computations_ixbrl' => $selectedCtPeriodId > 0
                    ? $package->locateComputationsIxbrlForCtPeriod($companyId, $selectedCtPeriodId)
                    : ['ok' => false, 'path' => null, 'filename' => null, 'warnings' => [], 'errors' => ['Select a CT period.']],
                'latest_submission' => $selectedCtPeriodId > 0
                    ? $service->getLatestSubmissionForCtPeriod($companyId, $selectedCtPeriodId)
                    : null,
                'history' => $service->getSubmissionHistory($companyId, null),
            ],
        ];
    }
}
