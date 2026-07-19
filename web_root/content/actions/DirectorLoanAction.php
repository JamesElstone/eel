<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class DirectorLoanAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        if (!in_array($intent, ['save_director_loan_reporting_presentation', 'mark_participator_loan_transaction', 'save_s455_review'], true)) {
            return ActionResultFramework::none();
        }

        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);

        try {
            $result = match ($intent) {
                'mark_participator_loan_transaction' => (new \eel_accounts\Service\ParticipatorLoanService())->assignTransaction(
                    $companyId,
                    $accountingPeriodId,
                    (int)$request->input('transaction_id', 0),
                    (int)$request->input('party_id', 0),
                    $this->actor($request)
                ),
                'save_s455_review' => (new \eel_accounts\Service\S455ReviewService())->saveReview(
                    $companyId,
                    $accountingPeriodId,
                    (int)$request->input('ct_period_id', 0),
                    (string)$request->input('close_company_status', ''),
                    $this->truthy($request->input('confirmed', '0')),
                    $this->actor($request),
                    (string)$request->input('confirmation_note', '')
                ),
                default => (new \eel_accounts\Service\DirectorLoanReportingPresentationService())->save(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('classification', ''),
                    $this->actor($request)
                ),
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $success = !empty($result['success']);
        $messages = [];
        if ($success) {
            $messages[] = [
                'type' => 'success',
                'message' => match ($intent) {
                    'mark_participator_loan_transaction' => 'Participator-loan source payment saved.',
                    'save_s455_review' => 's455 review saved.',
                    default => !empty($result['changed'])
                        ? 'Director Loan reporting presentation saved. Companies House and iXBRL figures will use the new repayment horizon.'
                        : 'No change was needed.',
                },
            ];
        } else {
            foreach ((array)($result['errors'] ?? ['Director Loan reporting presentation could not be saved.']) as $error) {
                $messages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework(
            $success,
            [
                'director.loan.state',
                'tax.s455',
                'tax.workings',
                'companies.house.snapshot',
                'year.end.companies.house.comparison',
                'year.end.checklist',
                'ixbrl.readiness',
                'ixbrl.accounts.mapping',
                'ixbrl.facts.preview',
                'ixbrl.generation',
                'page.context',
            ],
            $messages,
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function actor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
