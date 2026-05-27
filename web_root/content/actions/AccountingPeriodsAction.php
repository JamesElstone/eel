<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountingPeriodsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));

        return match ($intent) {
            'create_suggested_periods' => $this->createSuggestedPeriods($request),
            'create_required_periods_for_upload' => $this->createRequiredPeriodsForUpload($request),
            default => ActionResultFramework::none(),
        };
    }

    private function createSuggestedPeriods(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before creating suggested accounting periods.',
                ]]
            );
        }

        try {
            $guidance = (new AccountingGuidanceService())->build($companyId);
            $missing = (array)($guidance['missing_suggested_periods'] ?? []);

            if ($missing === []) {
                return new ActionResultFramework(
                    false,
                    ['page.context'],
                    [[
                        'type' => 'error',
                        'message' => 'No suggested accounting periods are currently available to create.',
                    ]]
                );
            }

            $accountingPeriodRepository = new AccountingPeriodRepository();

            foreach ($missing as $period) {
                $accountingPeriodRepository->createPeriod(
                    $companyId,
                    (string)($period['start'] ?? ''),
                    (string)($period['end'] ?? ''),
                    (string)($period['label'] ?? '')
                );
            }

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => count($missing) === 1
                        ? 'Suggested accounting period created.'
                        : 'Suggested accounting periods created.',
                ]]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }

    private function createRequiredPeriodsForUpload(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before creating required accounting periods.',
                ]]
            );
        }

        $requiredPeriodEnd = trim((string)$request->input('required_period_end', ''));

        if ($requiredPeriodEnd === '') {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => 'The required accounting period could not be identified for this upload.',
                ]]
            );
        }

        try {
            $guidance = (new AccountingGuidanceService())->build($companyId);
            $missing = array_values(array_filter(
                (array)($guidance['missing_suggested_periods'] ?? []),
                static function (mixed $period) use ($requiredPeriodEnd): bool {
                    if (!is_array($period)) {
                        return false;
                    }

                    return trim((string)($period['end'] ?? '')) <= $requiredPeriodEnd;
                }
            ));

            if ($missing === []) {
                return new ActionResultFramework(
                    false,
                    ['page.context'],
                    [[
                        'type' => 'error',
                        'message' => 'No required accounting periods are currently available to create for this upload.',
                    ]]
                );
            }

            $requiredPeriodFound = false;
            foreach ($missing as $period) {
                if (trim((string)($period['end'] ?? '')) === $requiredPeriodEnd) {
                    $requiredPeriodFound = true;
                    break;
                }
            }

            if (!$requiredPeriodFound) {
                return new ActionResultFramework(
                    false,
                    ['page.context'],
                    [[
                        'type' => 'error',
                        'message' => 'The required accounting period is not available in the current suggested periods.',
                    ]]
                );
            }

            $accountingPeriodRepository = new AccountingPeriodRepository();

            foreach ($missing as $period) {
                $accountingPeriodRepository->createPeriod(
                    $companyId,
                    (string)($period['start'] ?? ''),
                    (string)($period['end'] ?? ''),
                    (string)($period['label'] ?? '')
                );
            }

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => count($missing) === 1
                        ? 'Required accounting period created for this upload.'
                        : 'Required accounting periods created for this upload.',
                ]]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['page.context'],
                [[
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]]
            );
        }
    }
}
