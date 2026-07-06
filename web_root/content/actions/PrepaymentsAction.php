<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class PrepaymentsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = max(0, (int)$request->input('company_id', 0));
        $accountingPeriodId = max(0, (int)$request->input('accounting_period_id', 0));
        $intent = trim((string)$request->input('intent', ''));

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->result(false, ['Select a company and accounting period before updating prepayments.']);
        }

        try {
            $result = match ($intent) {
                'save_review' => (new \eel_accounts\Service\PrepaymentReviewService())->saveReview(
                    $companyId,
                    $accountingPeriodId,
                    [
                        'source_type' => (string)$request->input('source_type', ''),
                        'source_id' => (int)$request->input('source_id', 0),
                        'status' => (string)$request->input('prepayment_status', 'pending'),
                        'service_start_date' => (string)$request->input('service_start_date', ''),
                        'service_end_date' => (string)$request->input('service_end_date', ''),
                        'notes' => (string)$request->input('prepayment_notes', ''),
                    ]
                ),
                default => [
                    'success' => false,
                    'errors' => ['The selected prepayment action is not recognised.'],
                ],
            };
        } catch (Throwable $exception) {
            return $this->result(false, [$exception->getMessage()]);
        }

        return $this->result(
            !empty($result['success']),
            (array)($result['errors'] ?? []),
            $intent === 'save_review' ? 'Prepayment review saved.' : 'Prepayments updated.'
        );
    }

    private function result(bool $success, array $errors = [], string $successMessage = ''): ActionResultFramework
    {
        $flashMessages = [];
        if ($success) {
            $flashMessages[] = [
                'type' => 'success',
                'message' => $successMessage !== '' ? $successMessage : 'Prepayments updated.',
            ];
        } else {
            foreach ($errors !== [] ? $errors : ['The prepayment action could not be completed.'] as $error) {
                $flashMessages[] = [
                    'type' => 'error',
                    'message' => (string)$error,
                ];
            }
        }

        return new ActionResultFramework($success, ['page.context', 'prepayments.state', 'year.end.state', 'year.end.checklist'], $flashMessages);
    }
}
