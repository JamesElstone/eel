<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ParticipatorLoanTestFixture
{
    public static function configureNominals(int $companyId, int $assetNominalId, int $liabilityNominalId): void
    {
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('participator_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();
    }

    public static function createPartyForDirector(
        int $companyId,
        int $directorId,
        string $legalName,
        string $effectiveFrom = '1900-01-01'
    ): int {
        $partyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_parties
             WHERE company_id = :company_id AND linked_director_id = :director_id
             LIMIT 1',
            ['company_id' => $companyId, 'director_id' => $directorId]
        );
        if ($partyId <= 0) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (
                    company_id, party_type, legal_name, linked_director_id, source_note
                 ) VALUES (
                    :company_id, :party_type, :legal_name, :linked_director_id, :source_note
                 )',
                [
                    'company_id' => $companyId,
                    'party_type' => 'individual',
                    'legal_name' => $legalName,
                    'linked_director_id' => $directorId,
                    'source_note' => 'Participator loan test fixture',
                ]
            );
            $partyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_parties
                 WHERE company_id = :company_id AND linked_director_id = :director_id
                 LIMIT 1',
                ['company_id' => $companyId, 'director_id' => $directorId]
            );
        }

        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM company_party_roles
             WHERE company_id = :company_id AND party_id = :party_id
               AND role_type = :role_type AND effective_from = :effective_from',
            [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'role_type' => 'participator',
                'effective_from' => $effectiveFrom,
            ]
        ) === 0) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_party_roles (
                    company_id, party_id, role_type, effective_from, source_note
                 ) VALUES (
                    :company_id, :party_id, :role_type, :effective_from, :source_note
                 )',
                [
                    'company_id' => $companyId,
                    'party_id' => $partyId,
                    'role_type' => 'participator',
                    'effective_from' => $effectiveFrom,
                    'source_note' => 'Participator loan test fixture',
                ]
            );
        }

        return $partyId;
    }
}
