<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class YearEndAction implements ActionInterfaceFramework
{
    public function __construct(
        private readonly ?\eel_accounts\Service\CompanyDirectorEligibilityService $directorEligibilityService = null,
    ) {
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = max(0, (int)$request->input('company_id', 0));
        $accountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $intent = trim((string)$request->input('intent', ''));
        $actor = $this->currentWebActor($request);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->result(false, ['Select a company and accounting period before updating year-end readiness.']);
        }

        try {
            if ($this->requiresVatRegistrationGuard($intent) && $this->companyIsVatRegistered($companyId)) {
                return $this->result(false, ['Year-end processing is blocked while this company is marked as VAT registered.']);
            }

            if ($this->requiresSingleDirectorCheck($intent)) {
                $eligibility = $this->directorEligibilityService()->assertSingleActiveDirector($companyId);
                if (empty($eligibility['success'])) {
                    return $this->result(false, (array)($eligibility['errors'] ?? []));
                }
            }

            $result = match ($intent) {
                'recalculate' => (new \eel_accounts\Service\YearEndChecklistService())->recalculateChecklist($companyId, $accountingPeriodId, $actor),
                'lock_period' => (new \eel_accounts\Service\YearEndChecklistService())->lockPeriod($companyId, $accountingPeriodId, $actor),
                'unlock_period' => (new \eel_accounts\Service\YearEndChecklistService())->unlockPeriod($companyId, $accountingPeriodId, $actor),
                'save_notes' => (new \eel_accounts\Service\YearEndChecklistService())->saveNotes($companyId, $accountingPeriodId, (string)$request->input('review_notes', ''), $actor),
                'confirm_empty_month' => (new \eel_accounts\Service\EmptyMonthConfirmationService())->confirmMonth(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('month_start', ''),
                    (string)$request->input('confirmation_notes', ''),
                    $actor
                ),
                'revoke_empty_month' => (new \eel_accounts\Service\EmptyMonthConfirmationService())->revokeMonth(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('month_start', ''),
                    $actor
                ),
                'save_opening_balance' => (new \eel_accounts\Service\OpeningBalanceService())->saveOpeningBalance(
                    $companyId,
                    $accountingPeriodId,
                    $this->openingBalancePayload($request),
                    $actor
                ),
                'create_adjustment' => (new \eel_accounts\Service\YearEndAdjustmentService())->createAdjustment(
                    $companyId,
                    $accountingPeriodId,
                    $this->adjustmentPayload($request),
                    $actor
                ),
                'save_director_loan_offset_acknowledgement' => (new \eel_accounts\Service\YearEndChecklistService())->saveDirectorLoanClosingAcknowledgement(
                    $companyId,
                    $accountingPeriodId,
                    $this->truthy($request->input('director_loan_offset_acknowledgement', '0')),
                    $actor,
                    (string)$request->input('approval_note', '')
                ),
                'save_tax_readiness_acknowledgement' => (new \eel_accounts\Service\YearEndChecklistService())->saveTaxReadinessAcknowledgement(
                    $companyId,
                    $accountingPeriodId,
                    $this->truthy($request->input('tax_readiness_acknowledgement', '0')),
                    $actor,
                    (string)$request->input('approval_note', '')
                ),
                'save_expense_position_acknowledgement' => (new \eel_accounts\Service\YearEndChecklistService())->saveExpensePositionAcknowledgement(
                    $companyId,
                    $accountingPeriodId,
                    $this->truthy($request->input('expense_position_acknowledgement', '0')),
                    $actor,
                    (string)$request->input('approval_note', '')
                ),
                'save_retained_earnings_close_acknowledgement' => (new \eel_accounts\Service\YearEndChecklistService())->saveRetainedEarningsCloseAcknowledgement(
                    $companyId,
                    $accountingPeriodId,
                    $this->truthy($request->input('retained_earnings_close_acknowledgement', '0')),
                    $actor,
                    (string)$request->input('approval_note', '')
                ),
                'save_transaction_tail_acknowledgement' => (new \eel_accounts\Service\YearEndChecklistService())->saveTransactionTailAcknowledgement(
                    $companyId,
                    $accountingPeriodId,
                    $this->truthy($request->input('transaction_tail_acknowledgement', '0')),
                    (string)$request->input('review_acknowledgement_note', (string)$request->input('transaction_tail_acknowledgement_note', '')),
                    $actor
                ),
                'acknowledge_review_check' => (new \eel_accounts\Service\YearEndChecklistService())->acknowledgeReviewCheck(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('check_code', ''),
                    true,
                    (string)$request->input('review_acknowledgement_note', ''),
                    $actor
                ),
                'reopen_review_check' => (new \eel_accounts\Service\YearEndChecklistService())->acknowledgeReviewCheck(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('check_code', ''),
                    false,
                    (string)$request->input('review_acknowledgement_note', ''),
                    $actor
                ),
                'post_director_loan_offset' => (new \eel_accounts\Service\DirectorLoanReconciliationService())->postOffset(
                    $companyId,
                    $accountingPeriodId,
                    $actor
                ),
                default => ['success' => false, 'errors' => ['Unknown year-end action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return $this->result(
            !empty($result['success']),
            (array)($result['errors'] ?? []),
            $this->successMessage($intent)
        );
    }

    private function currentWebActor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $deviceId !== '' ? $session->authenticatedUserId($deviceId) : 0;

            if ($userId > 0) {
                $user = (new UserAuthenticationService())->userById($userId);
                $displayName = trim((string)($user['display_name'] ?? ''));
                if ($displayName !== '') {
                    return $displayName . ' using the web_app';
                }
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }

    private function result(bool $success, array $errors = [], string $successMessage = ''): ActionResultFramework
    {
        $flashMessages = [];

        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $successMessage !== '' ? $successMessage : 'Year-end readiness updated.',
            ];
        } else {
            foreach ($errors !== [] ? $errors : ['The year-end action could not be completed.'] as $error) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => (string)$error,
                ];
            }
        }

        return new ActionResultFramework($success, ['page.context', 'year.end.state', 'year.end.checklist', 'year.end.director.loan.offset', 'year.end.tax.readiness', 'year.end.expenses.confirmation', 'year.end.retained.earnings', 'year.end.empty.month.confirmations', 'year.end.transaction.tail', 'year.end.notes', 'year.end.audit.log', 'trial.balance.state', 'nominal.opening.balances', 'nominal.closing.balances', 'cut.off.journals', 'prepayments.state'], $flashMessages);
    }

    private function successMessage(string $intent): string
    {
        return match ($intent) {
            'recalculate' => 'Year-end checklist recalculated.',
            'lock_period' => 'Accounting period locked.',
            'unlock_period' => 'Accounting period unlocked.',
            'save_notes' => 'Year-end notes saved.',
            'confirm_empty_month' => 'Empty month confirmation saved.',
            'revoke_empty_month' => 'Empty month confirmation revoked.',
            'save_opening_balance' => 'Opening balance journal saved.',
            'create_adjustment' => 'Year-end adjustment posted.',
            'save_director_loan_offset_acknowledgement' => 'Director loan offset approval saved.',
            'save_tax_readiness_acknowledgement' => 'Tax readiness approval saved.',
            'save_expense_position_acknowledgement' => 'Expense position approval saved.',
            'save_retained_earnings_close_acknowledgement' => 'Retained earnings approval saved.',
            'save_transaction_tail_acknowledgement' => 'Transaction cut-off approval saved.',
            'acknowledge_review_check' => 'Year-end approval saved.',
            'reopen_review_check' => 'Year-end approval revoked.',
            'post_director_loan_offset' => 'Director loan offset journal posted.',
            default => 'Year-end readiness updated.',
        };
    }

    private function requiresSingleDirectorCheck(string $intent): bool
    {
        return in_array($intent, [
            'recalculate',
            'lock_period',
            'save_opening_balance',
            'create_adjustment',
            'save_director_loan_offset_acknowledgement',
            'save_tax_readiness_acknowledgement',
            'save_expense_position_acknowledgement',
            'save_retained_earnings_close_acknowledgement',
            'save_transaction_tail_acknowledgement',
            'post_director_loan_offset',
        ], true);
    }

    private function requiresVatRegistrationGuard(string $intent): bool
    {
        return in_array($intent, [
            'recalculate',
            'lock_period',
            'save_opening_balance',
            'create_adjustment',
            'save_director_loan_offset_acknowledgement',
            'save_tax_readiness_acknowledgement',
            'save_expense_position_acknowledgement',
            'save_retained_earnings_close_acknowledgement',
            'save_transaction_tail_acknowledgement',
            'post_director_loan_offset',
        ], true);
    }

    private function companyIsVatRegistered(int $companyId): bool
    {
        $details = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId);

        return !empty($details['is_vat_registered']);
    }

    private function directorEligibilityService(): \eel_accounts\Service\CompanyDirectorEligibilityService
    {
        return $this->directorEligibilityService ?? new \eel_accounts\Service\CompanyDirectorEligibilityService();
    }

    private function openingBalancePayload(RequestFramework $request): array
    {
        return [
            'description' => (string)$request->input('opening_balance_description', ''),
            'notes' => (string)$request->input('opening_balance_notes', ''),
            'is_system_generated' => $this->truthy($request->input('opening_balance_system_mode', '0')),
            'replace_existing' => $this->truthy($request->input('opening_balance_replace', '0')),
            'lines' => $this->linePayloads($request, 'opening_balance', 8),
        ];
    }

    private function adjustmentPayload(RequestFramework $request): array
    {
        return [
            'template_type' => (string)$request->input('adjustment_template_type', 'custom'),
            'journal_date' => (string)$request->input('adjustment_date', ''),
            'description' => (string)$request->input('adjustment_description', ''),
            'notes' => (string)$request->input('adjustment_notes', ''),
            'primary_nominal_id' => (int)$request->input('adjustment_primary_nominal_id', 0),
            'offset_nominal_id' => (int)$request->input('adjustment_offset_nominal_id', 0),
            'amount' => (string)$request->input('adjustment_amount', ''),
            'auto_reverse' => $this->truthy($request->input('adjustment_auto_reverse', '0')),
            'lines' => $this->linePayloads($request, 'adjustment', 8),
        ];
    }

    private function linePayloads(RequestFramework $request, string $prefix, int $maxRows): array
    {
        $lines = [];

        for ($index = 0; $index < $maxRows; $index++) {
            $nominalId = (int)$request->input($prefix . '_line_' . $index . '_nominal_id', 0);
            $debit = trim((string)$request->input($prefix . '_line_' . $index . '_debit', ''));
            $credit = trim((string)$request->input($prefix . '_line_' . $index . '_credit', ''));
            $description = trim((string)$request->input($prefix . '_line_' . $index . '_description', ''));

            if ($nominalId <= 0 && $debit === '' && $credit === '' && $description === '') {
                continue;
            }

            $lines[] = [
                'nominal_account_id' => $nominalId,
                'debit' => $debit !== '' ? $debit : '0.00',
                'credit' => $credit !== '' ? $credit : '0.00',
                'line_description' => $description,
            ];
        }

        return $lines;
    }

    private function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->truthy($item)) {
                    return true;
                }
            }

            return false;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
