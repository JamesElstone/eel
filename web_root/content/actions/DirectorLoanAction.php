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
        if ($intent !== 'save_participator_loan_party_terms') {
            return ActionResultFramework::none();
        }

        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);

        try {
            $result = match ($intent) {
                'save_participator_loan_party_terms' => (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
                    $companyId,
                    (int)$request->input('party_id', 0),
                    [
                        'interest_rate_percent' => (string)$request->input('interest_rate_percent', '0'),
                        'security_type' => (string)$request->input('security_type', 'unsecured'),
                        'repayable_on_demand' => $this->checked($request->input('repayable_on_demand', '')),
                        'repayment_timing' => (string)$request->input('repayment_timing', 'within_12_months'),
                        'deferment_right_confirmed' => $this->checked($request->input('deferment_right_confirmed', '')),
                        'set_off_right_confirmed' => $this->checked($request->input('set_off_right_confirmed', '')),
                        'settlement_intention' => (string)$request->input('settlement_intention', 'independently'),
                    ],
                    $this->actor($request)
                ),
                default => throw new \LogicException('Unsupported participator loan terms action.'),
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
                    'save_participator_loan_party_terms' => !empty($result['changed'])
                        ? 'Participator loan party terms saved.'
                        : 'No change was needed.',
                    default => 'Participator loan party terms saved.',
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
