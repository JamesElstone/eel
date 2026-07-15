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
                'reopen_schedule' => (new \eel_accounts\Service\PrepaymentPostingService())->reopenSchedule(
                    $companyId,
                    (int)$request->input('review_id', 0)
                ),
                'recalculate_schedule' => (new \eel_accounts\Service\PrepaymentScheduleService())->syncMissingSchedulesForPeriod(
                    $companyId,
                    $accountingPeriodId
                ),
                'confirm_hmrc_filing_status' => (new \eel_accounts\Service\PrepaymentHistoricalCorrectionService())->confirmHmrcFilingStatus(
                    $companyId,
                    $accountingPeriodId,
                    (string)$request->input('hmrc_filing_status', ''),
                    (string)$request->input('hmrc_filing_reference', ''),
                    (string)$request->input('hmrc_filing_notes', '')
                ),
                'acknowledge_historical_correction' => $this->acknowledgeHistoricalCorrection($request, $companyId, $accountingPeriodId),
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
            match ($intent) {
                'save_review' => 'Prepayment review and schedule saved.',
                'reopen_schedule' => 'Prepayment schedule reopened and posted effects compensated.',
                'recalculate_schedule' => 'Missing prepayment schedules recalculated. No journals were posted.',
                'confirm_hmrc_filing_status' => 'HMRC Corporation Tax filing evidence recorded.',
                'acknowledge_historical_correction' => 'Historical prepayment correction approved.',
                default => 'Prepayments updated.',
            }
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

    private function acknowledgeHistoricalCorrection(
        RequestFramework $request,
        int $companyId,
        int $accountingPeriodId
    ): array {
        if ((string)$request->input('historical_correction_confirmed', '') !== '1') {
            return ['success' => false, 'errors' => ['Confirm that the filed Companies House figures and HMRC filing evidence have been reviewed.']];
        }
        return (new \eel_accounts\Service\PrepaymentHistoricalCorrectionService())->acknowledge(
            $companyId,
            $accountingPeriodId,
            'web_app',
            (string)$request->input('historical_correction_note', '')
        );
    }
}
