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
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = max(0, (int)$request->input('company_id', 0));
        $accountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $intent = trim((string)$request->input('intent', ''));

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->result(false, ['Select a company and accounting period before updating year-end readiness.']);
        }

        try {
            $result = match ($intent) {
                'recalculate' => (new \eel_accounts\Service\YearEndChecklistService())->recalculateChecklist($companyId, $accountingPeriodId),
                'lock_period' => (new \eel_accounts\Service\YearEndChecklistService())->lockPeriod($companyId, $accountingPeriodId),
                'unlock_period' => (new \eel_accounts\Service\YearEndChecklistService())->unlockPeriod($companyId, $accountingPeriodId),
                'save_notes' => (new \eel_accounts\Service\YearEndChecklistService())->saveNotes($companyId, $accountingPeriodId, (string)$request->input('review_notes', '')),
                'save_opening_balance' => (new \eel_accounts\Service\OpeningBalanceService())->saveOpeningBalance(
                    $companyId,
                    $accountingPeriodId,
                    $this->openingBalancePayload($request)
                ),
                'create_adjustment' => (new \eel_accounts\Service\YearEndAdjustmentService())->createAdjustment(
                    $companyId,
                    $accountingPeriodId,
                    $this->adjustmentPayload($request)
                ),
                'post_director_loan_offset' => (new \eel_accounts\Service\DirectorLoanReconciliationService())->postOffset(
                    $companyId,
                    $accountingPeriodId
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

        return new ActionResultFramework($success, ['year.end.state', 'year.end.audit.log', 'trial.balance.state'], $flashMessages);
    }

    private function successMessage(string $intent): string
    {
        return match ($intent) {
            'recalculate' => 'Year-end checklist recalculated.',
            'lock_period' => 'Accounting period locked.',
            'unlock_period' => 'Accounting period unlocked.',
            'save_notes' => 'Year-end notes saved.',
            'save_opening_balance' => 'Opening balance journal saved.',
            'create_adjustment' => 'Year-end adjustment posted.',
            'post_director_loan_offset' => 'Director loan offset journal posted.',
            default => 'Year-end readiness updated.',
        };
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
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
