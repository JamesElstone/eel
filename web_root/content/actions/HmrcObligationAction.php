<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class HmrcObligationAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        $companyId = (int)$request->input('company_id', 0);
        $service = new \eel_accounts\Service\HmrcObligationService();
        $actor = $this->actor();

        try {
            $result = match ($intent) {
                'sync_hmrc_obligations' => $service->syncObligationsForCompany($companyId),
                'mark_filed' => $service->markFiled((int)$request->input('obligation_id', 0), (string)$request->input('source_reference', ''), (string)$request->input('notes', '')),
                'link_payment_evidence' => $this->linkPaymentEvidence($service, $request, $companyId),
                'unlink_payment_evidence' => $service->unlinkPaymentEvidence($companyId, (int)$request->input('obligation_id', 0), (int)$request->input('evidence_link_id', 0)),
                'update_status' => $service->updateObligationStatus((int)$request->input('obligation_id', 0), (string)$request->input('status', ''), (string)$request->input('notes', '')),
                'filter_obligations' => ['success' => true, 'filter_only' => true],
                'create_manual_obligation' => $service->createManualObligation([
                    'company_id' => $companyId,
                    'accounting_period_id' => (int)$request->input('accounting_period_id', 0),
                    'obligation_type' => (string)$request->input('obligation_type', 'hmrc_penalty'),
                    'notice_date' => (string)$request->input('notice_date', ''),
                    'due_date' => (string)$request->input('due_date', ''),
                    'amount_due' => (string)$request->input('amount_due', ''),
                    'source_reference' => (string)$request->input('source_reference', ''),
                    'notes' => (string)$request->input('notes', ''),
                    'changed_by' => $actor,
                ]),
                'correct_manual_obligation' => $service->correctManualObligation(
                    $companyId,
                    (int)$request->input('obligation_id', 0),
                    [
                        'correction_mode' => (string)$request->input('correction_mode', 'cancel'),
                        'effective_date' => (string)$request->input('effective_date', ''),
                        'reason' => (string)$request->input('correction_reason', ''),
                        'replacement_due_date' => (string)$request->input('replacement_due_date', ''),
                        'replacement_amount_due' => (string)$request->input('replacement_amount_due', ''),
                        'replacement_source_reference' => (string)$request->input('replacement_source_reference', ''),
                    ],
                    $actor
                ),
                default => ['success' => false, 'errors' => ['Unknown HMRC obligation action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $success = !empty($result['success']);
        $flashMessages = [];
        if ($success && empty($result['filter_only'])) {
            $flashMessages[] = ['type' => 'success', 'message' => 'HMRC obligations updated.'];
            foreach ((array)($result['warnings'] ?? []) as $warning) {
                $flashMessages[] = ['type' => 'warning', 'message' => (string)$warning];
            }
        } elseif (!$success) {
            foreach ((array)($result['errors'] ?? ['HMRC obligation action failed.']) as $error) {
                $flashMessages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework($success, ['hmrc.obligations.summary', 'hmrc.obligations.timeline', 'hmrc.obligations.period.checklist', 'hmrc.obligations.action.panel', 'hmrc.fines.table', 'page.context'], $flashMessages);
    }

    private function linkPaymentEvidence(\eel_accounts\Service\HmrcObligationService $service, RequestFramework $request, int $companyId): array
    {
        $source = explode(':', trim((string)$request->input('evidence_source', '')), 2);
        $sourceType = (string)($source[0] ?? '');
        $sourceId = (int)($source[1] ?? 0);
        $amountInput = trim((string)$request->input('allocated_amount', ''));
        $amount = $amountInput === '' ? $service->defaultEvidenceAllocation($companyId, $sourceType, $sourceId) : (float)$amountInput;

        return $service->linkPaymentEvidence($companyId, (int)$request->input('obligation_id', 0), $sourceType, $sourceId, $amount);
    }

    private function actor(): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }
        return 'web_app';
    }
}
