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
        if ($intent !== 'save_director_loan_reporting_presentation') {
            return ActionResultFramework::none();
        }

        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);

        try {
            $result = match ($intent) {
                default => (new \eel_accounts\Service\DirectorLoanReportingPresentationService())->save(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('classification', ''),
                    $this->actor($request),
                    [
                        'set_off_right_confirmed' => $this->checked(
                            $request->input('set_off_right_confirmed', '')
                        ),
                        'set_off_net_settlement_intended' => $this->checked(
                            $request->input('set_off_net_settlement_intended', '')
                        ),
                        'set_off_evidence' => (string)$request->input('set_off_evidence', ''),
                        'deferment_right_confirmed' => $this->checked(
                            $request->input('deferment_right_confirmed', '')
                        ),
                        'deferment_evidence' => (string)$request->input('deferment_evidence', ''),
                        'interest_rate_percent' => (string)$request->input('interest_rate_percent', '0'),
                        'main_terms' => (string)$request->input('main_terms', 'Unsecured.'),
                        'repayment_conditions' => (string)$request->input(
                            'repayment_conditions',
                            'Repayable on demand.'
                        ),
                    ]
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
                    default => !empty($result['changed'])
                        ? 'Director Loan statutory presentation and supporting evidence saved.'
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
                'year.end.director.loan.offset',
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

    private function checked(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

}
