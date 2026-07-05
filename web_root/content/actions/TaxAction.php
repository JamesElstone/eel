<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TaxAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = max(0, (int)$request->input('company_id', 0));
        $accountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $ctPeriodId = max(0, (int)$request->input('ct_period_id', 0));
        $intent = trim((string)$request->input('intent', ''));

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return $this->result(false, ['Select a company, accounting period, and CT period first.'], $ctPeriodId);
        }

        if ($intent === 'select_ct_period') {
            return ActionResultFramework::success(['page.reload'], query: ['ct_period_id' => (string)$ctPeriodId]);
        }

        try {
            $result = match ($intent) {
                'post_ct_provision' => (new \eel_accounts\Service\CorporationTaxProvisionService())->postProvision($companyId, $accountingPeriodId, $ctPeriodId),
                default => ['success' => false, 'errors' => ['Unknown tax action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result(!empty($result['success']), (array)($result['errors'] ?? []), $ctPeriodId);
    }

    private function result(bool $success, array $errors, int $ctPeriodId): ActionResultFramework
    {
        $messages = [];
        if ($success) {
            $messages[] = ['type' => 'success', 'message' => 'Corporation Tax provision posted.'];
        } else {
            foreach ($errors !== [] ? $errors : ['The tax action could not be completed.'] as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework(
            $success,
            ['page.context', 'tax.workings', 'trial.balance.state', 'profit.loss', 'year.end.retained.earnings', 'dividend.reserve'],
            $messages,
            $ctPeriodId > 0 ? ['ct_period_id' => (string)$ctPeriodId] : []
        );
    }
}
