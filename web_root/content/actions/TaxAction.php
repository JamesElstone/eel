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
        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        $intent = trim((string)$request->input('intent', ''));

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId === 0) {
            return $this->result(false, ['Select a company, accounting period, and CT period first.'], $ctPeriodId);
        }

        if ($intent === 'select_ct_period') {
            return ActionResultFramework::success(['page.reload'], query: ['ct_period_id' => (string)$ctPeriodId]);
        }

        try {
            $result = match ($intent) {
                'save_ct_period_facts' => (new \eel_accounts\Service\CorporationTaxPeriodFactService())->save(
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    (int)$request->input('associated_company_count', 0)
                ),
                'save_line_tax_treatment' => (new \eel_accounts\Service\CorporationTaxLineTreatmentService())->save(
                    $companyId,
                    $accountingPeriodId,
                    (int)$request->input('journal_line_id', 0),
                    (string)$request->input('tax_treatment', ''),
                    $this->actor($request)
                ),
                default => ['success' => false, 'errors' => ['Unknown tax action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result(!empty($result['success']), (array)($result['errors'] ?? []), $ctPeriodId, $intent);
    }

    private function result(bool $success, array $errors, int $ctPeriodId, string $intent = ''): ActionResultFramework
    {
        $messages = [];
        if ($success) {
            $messages[] = ['type' => 'success', 'message' => $intent === 'save_line_tax_treatment'
                ? 'Corporation Tax treatment saved.'
                : 'CT-period facts saved.'];
        } else {
            foreach ($errors !== [] ? $errors : ['The tax action could not be completed.'] as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework(
            $success,
            ['page.context', 'tax.period.facts', 'tax.s455', 'tax.workings', 'tax.review', 'trial.balance.state', 'profit.loss', 'year.end.retained.earnings', 'dividend.reserve', 'year.end.checklist', 'ixbrl.readiness', 'ixbrl.facts.preview', 'ixbrl.generation'],
            $messages,
            $ctPeriodId !== 0 ? ['ct_period_id' => (string)$ctPeriodId] : []
        );
    }

    private function actor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            return $userId > 0 ? 'user:' . $userId : 'web_app';
        } catch (Throwable) {
            return 'web_app';
        }
    }

}
