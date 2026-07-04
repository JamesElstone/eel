<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IncorporationAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        $companyId = (int)$request->input('company_id', 0);
        $shareClassId = (int)$request->input('share_class_id', 0);
        $service = new \eel_accounts\Service\IncorporationShareCapitalService();

        $result = match ($intent) {
            'save_incorporation_shares' => $service->saveShareClass([
                'company_id' => $companyId,
                'share_class_id' => $shareClassId,
                'share_class' => (string)$request->input('share_class', 'Ordinary'),
                'currency' => (string)$request->input('currency', 'GBP'),
                'quantity' => (string)$request->input('quantity', '0'),
                'aggregate_nominal_value' => (string)$request->input('aggregate_nominal_value', ''),
                'total_aggregate_unpaid' => (string)$request->input('total_aggregate_unpaid', ''),
                'nominal_value_per_share' => (string)$request->input('nominal_value_per_share', '0'),
                'paid_value_per_share' => (string)$request->input('paid_value_per_share', '0'),
                'unpaid_value_per_share' => (string)$request->input('unpaid_value_per_share', '0'),
                'source_note' => (string)$request->input('source_note', ''),
                'document_reference' => (string)$request->input('document_reference', ''),
            ]),
            'match_share_payment' => $service->matchPayment(
                $companyId,
                $shareClassId,
                (int)$request->input('transaction_id', 0)
            ),
            'mark_shares_unpaid' => $service->markSharesUnpaid($companyId, $shareClassId),
            'clear_share_payment_match' => $service->clearPaymentMatch($companyId, $shareClassId),
            default => ['success' => false, 'errors' => ['Unknown incorporation action.']],
        };

        $success = !empty($result['success']);
        $messages = [];
        foreach ((array)($result['errors'] ?? []) as $error) {
            $messages[] = [
                'type' => 'error',
                'message' => (string)$error,
            ];
        }

        if ($success) {
            $messages[] = [
                'type' => 'success',
                'message' => $this->successMessage($intent),
            ];
        }

        return new ActionResultFramework(
            $success,
            ['page.context', 'incorporation.status', 'incorporation.share.capital', 'incorporation.payment.matching', 'year.end.checklist'],
            $messages
        );
    }

    private function successMessage(string $intent): string
    {
        return match ($intent) {
            'save_incorporation_shares' => 'Incorporation share capital saved.',
            'match_share_payment' => 'Share payment matched and posted to Ordinary Share Capital.',
            'mark_shares_unpaid' => 'Shares marked as not paid up.',
            'clear_share_payment_match' => 'Share payment match cleared.',
            default => 'Incorporation details updated.',
        };
    }
}
