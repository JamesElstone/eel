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

        try {
            $result = match ($intent) {
                'sync_hmrc_obligations' => $service->syncObligationsForCompany($companyId),
                'mark_filed' => $service->markFiled((int)$request->input('obligation_id', 0), (string)$request->input('source_reference', ''), (string)$request->input('notes', '')),
                'mark_paid' => $service->markPaid((int)$request->input('obligation_id', 0), (float)$request->input('amount_paid', 0), (string)$request->input('source_reference', ''), (string)$request->input('notes', '')),
                'update_status' => $service->updateObligationStatus((int)$request->input('obligation_id', 0), (string)$request->input('status', ''), (string)$request->input('notes', '')),
                'create_manual_obligation' => $service->createManualObligation([
                    'company_id' => $companyId,
                    'accounting_period_id' => (int)$request->input('accounting_period_id', 0),
                    'obligation_type' => (string)$request->input('obligation_type', 'hmrc_penalty'),
                    'due_date' => (string)$request->input('due_date', ''),
                    'amount_due' => (string)$request->input('amount_due', ''),
                    'source_reference' => (string)$request->input('source_reference', ''),
                    'notes' => (string)$request->input('notes', ''),
                ]),
                default => ['success' => false, 'errors' => ['Unknown HMRC obligation action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $success = !empty($result['success']);
        $flashMessages = [];
        if ($success) {
            $flashMessages[] = ['type' => 'success', 'message' => 'HMRC obligations updated.'];
            foreach ((array)($result['warnings'] ?? []) as $warning) {
                $flashMessages[] = ['type' => 'warning', 'message' => (string)$warning];
            }
        } else {
            foreach ((array)($result['errors'] ?? ['HMRC obligation action failed.']) as $error) {
                $flashMessages[] = ['type' => 'error', 'message' => (string)$error];
            }
        }

        return new ActionResultFramework($success, ['hmrc.obligations.summary', 'hmrc.obligations.timeline', 'hmrc.obligations.period.checklist', 'hmrc.obligations.action.panel', 'hmrc.fines.table', 'page.context'], $flashMessages);
    }
}
