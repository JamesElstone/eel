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

        if ($intent === 'populate_incorporation_shares_from_newinc') {
            return $this->populateShareDraftFromNewinc($companyId);
        }

        $service = new \eel_accounts\Service\IncorporationShareCapitalService();
        $ownership = new \eel_accounts\Service\OwnershipPartyService();

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
            'clear_share_payment_match' => $service->clearPaymentMatch($companyId, $shareClassId),
            'save_ownership_party' => $ownership->saveParty($this->ownershipPartyInput($request, $companyId)),
            'save_ownership_role' => $ownership->saveRole([
                'company_id' => $companyId,
                'party_id' => (int)$request->input('party_id', 0),
                'role_type' => (string)$request->input('role_type', ''),
                'effective_from' => (string)$request->input('effective_from', ''),
                'effective_to' => (string)$request->input('effective_to', ''),
                'source_note' => (string)$request->input('source_note', ''),
            ]),
            'end_ownership_role' => $ownership->endRole(
                $companyId,
                (int)$request->input('role_id', 0),
                (string)$request->input('effective_to', '')
            ),
            'save_shareholding' => $ownership->saveHolding([
                'company_id' => $companyId,
                'party_id' => (int)$request->input('party_id', 0),
                'share_class_id' => (int)$request->input('share_class_id', 0),
                'quantity' => (int)$request->input('quantity', 0),
                'effective_from' => (string)$request->input('effective_from', ''),
                'effective_to' => (string)$request->input('effective_to', ''),
                'source_note' => (string)$request->input('source_note', ''),
            ]),
            'save_director_shareholdings' => $ownership->saveDirectorShareholdings(
                $companyId,
                $shareClassId,
                (array)$request->input('director_shareholdings', [])
            ),
            'end_shareholding' => $ownership->endHolding(
                $companyId,
                (int)$request->input('holding_id', 0),
                (string)$request->input('effective_to', '')
            ),
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
            ['page.context', 'incorporation.status', 'incorporation.share.capital', 'incorporation.payment.matching', 'ownership.parties', 'tax.s455', 'year.end.checklist'],
            $messages
        );
    }

    private function successMessage(string $intent): string
    {
        return match ($intent) {
            'save_incorporation_shares' => 'Incorporation share capital saved.',
            'match_share_payment' => 'Share payment matched and posted to Ordinary Share Capital.',
            'clear_share_payment_match' => 'Share payment match cleared.',
            'save_ownership_party' => 'Ownership party saved.',
            'save_ownership_role' => 'Effective ownership role saved.',
            'end_ownership_role' => 'Ownership role end date saved.',
            'save_shareholding' => 'Shareholding saved.',
            'save_director_shareholdings' => 'Directors’ shareholdings saved.',
            'end_shareholding' => 'Shareholding end date saved.',
            default => 'Incorporation details updated.',
        };
    }

    private function ownershipPartyInput(RequestFramework $request, int $companyId): array
    {
        $directorStatus = (string)$request->input('director_status', 'non_director');
        $linkedDirectorId = (int)$request->input('linked_director_id', 0);
        $legalName = (string)$request->input('legal_name', '');
        $partyType = (string)$request->input('party_type', 'individual');

        if ($directorStatus === 'director') {
            $director = (new \eel_accounts\Service\CompanyDirectorService())->requireForCompany($companyId, $linkedDirectorId);
            $legalName = (string)($director['full_name'] ?? '');
            $partyType = 'individual';
        } else {
            $linkedDirectorId = 0;
        }

        return [
            'company_id' => $companyId,
            'party_id' => (int)$request->input('party_id', 0),
            'legal_name' => $legalName,
            'party_type' => $partyType,
            'linked_director_id' => $linkedDirectorId,
            'source_note' => (string)$request->input('source_note', ''),
        ];
    }

    private function populateShareDraftFromNewinc(int $companyId): ActionResultFramework
    {
        $result = (new \eel_accounts\Service\CompaniesHouseInitialShareholdingExtractionService())->draftForCompany($companyId);
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
                'message' => 'Initial shareholdings loaded from the downloaded incorporation PDF. Review the values, then press Add Share Class to save them.',
            ];
        }

        return new ActionResultFramework(
            $success,
            ['page.context', 'incorporation.share.capital'],
            $messages,
            [],
            $success ? [
                'incorporation_shares' => [
                    'draft_share_class' => (array)($result['draft'] ?? []),
                ],
            ] : []
        );
    }
}
