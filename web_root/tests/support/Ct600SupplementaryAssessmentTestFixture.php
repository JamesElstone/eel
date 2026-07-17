<?php
declare(strict_types=1);

use eel_accounts\Service\Ct600SupplementaryAssessmentContract;

/** @return array<string, int|string> */
function ct600_supplement_seed(): array
{
    $ids = [
        'company_id' => 98249,
        'accounting_period_id' => 98279,
        'ct_period_id' => 98206,
        'computation_run_id' => 98283,
        'locked_at' => '2026-07-16 22:35:08',
    ];
    \InterfaceDB::beginTransaction();
    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (
            id, company_name, company_number, company_status, companies_house_type,
            has_insolvency_history, has_been_liquidated
         ) VALUES (
            :id, :company_name, :company_number, :company_status, :companies_house_type,
            0, 0
         )',
        [
            'id' => $ids['company_id'],
            'company_name' => 'Synthetic Supplement Assessment Ltd',
            'company_number' => '09999998',
            'company_status' => 'active',
            'companies_house_type' => 'ltd',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $ids['accounting_period_id'],
            'company_id' => $ids['company_id'],
            'label' => 'AP79-shaped synthetic period',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-30',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (
            company_id, accounting_period_id, is_locked, locked_at, locked_by,
            created_at, updated_at
         ) VALUES (
            :company_id, :accounting_period_id, 1, :locked_at, :locked_by,
            :created_at, :updated_at
         )',
        [
            'company_id' => $ids['company_id'],
            'accounting_period_id' => $ids['accounting_period_id'],
            'locked_at' => $ids['locked_at'],
            'locked_by' => 'user:1',
            'created_at' => $ids['locked_at'],
            'updated_at' => $ids['locked_at'],
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO corporation_tax_periods (
            id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
         ) VALUES (
            :id, :company_id, :accounting_period_id, 1, :period_start, :period_end, :status
         )',
        [
            'id' => $ids['ct_period_id'],
            'company_id' => $ids['company_id'],
            'accounting_period_id' => $ids['accounting_period_id'],
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
            'status' => 'ready',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO corporation_tax_computation_runs (
            id, company_id, accounting_period_id, ct_period_id, period_start, period_end,
            status, computation_hash, summary_json
         ) VALUES (
            :id, :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
            :status, :computation_hash, :summary_json
         )',
        [
            'id' => $ids['computation_run_id'],
            'company_id' => $ids['company_id'],
            'accounting_period_id' => $ids['accounting_period_id'],
            'ct_period_id' => $ids['ct_period_id'],
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
            'status' => 'generated',
            'computation_hash' => str_repeat('a', 64),
            'summary_json' => '{}',
        ]
    );
    \InterfaceDB::prepareExecute(
        'UPDATE corporation_tax_periods
         SET latest_computation_run_id = :computation_run_id
         WHERE id = :ct_period_id',
        [
            'computation_run_id' => $ids['computation_run_id'],
            'ct_period_id' => $ids['ct_period_id'],
        ]
    );

    return $ids;
}

/** @param array<string, string|array<string, string>> $overrides @return array<string, array<string, string>> */
function ct600_supplement_admin_answers(array $overrides = []): array
{
    $answers = [];
    foreach (Ct600SupplementaryAssessmentContract::definitions() as $definition) {
        $key = (string)$definition['contract_key'];
        if ($key === 'ct600a') {
            continue;
        }
        $answer = [
            'contract_key' => $key,
            'status' => Ct600SupplementaryAssessmentContract::NOT_REQUIRED,
            'evidence_source' => 'admin_scope_review',
            'evidence_ref' => 'synthetic-checklist:' . $key,
            'detail' => (string)$definition['label'] . ' was reviewed and is not required.',
        ];
        if (array_key_exists($key, $overrides)) {
            $override = $overrides[$key];
            $answer = array_merge(
                $answer,
                is_array($override) ? $override : ['status' => (string)$override]
            );
        }
        $answers[$key] = $answer;
    }

    return $answers;
}

/** @return list<array<string, mixed>> */
function ct600_supplement_complete_rows(): array
{
    $rows = [];
    foreach (Ct600SupplementaryAssessmentContract::unknownRows() as $row) {
        $rows[] = array_merge($row, [
            'status' => Ct600SupplementaryAssessmentContract::NOT_REQUIRED,
            'evidence_source' => (string)$row['contract_key'] === 'ct600a'
                ? 'director_loan_service'
                : 'admin_scope_review',
            'evidence_ref' => 'synthetic:' . (string)$row['contract_key'],
            'detail' => (string)$row['label'] . ' is not required.',
        ]);
    }
    return $rows;
}

/** @return array<string, mixed> */
function ct600_supplement_no_director_exposure(): array
{
    return [
        'success' => true,
        'available' => true,
        'status' => 'no_director_receivable',
        'review_required' => false,
        'director_owes_company' => false,
        'exposure_amount' => 0.0,
    ];
}

/** @return array<string, mixed> */
function ct600_supplement_director_exposure(): array
{
    return [
        'success' => true,
        'available' => true,
        'status' => 'review_required',
        'review_required' => true,
        'director_owes_company' => true,
        'exposure_amount' => 1250.0,
    ];
}
