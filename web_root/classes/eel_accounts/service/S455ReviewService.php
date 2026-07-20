<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class S455ReviewService
{
    public function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_s455_reviews')) {
            return ['available' => false, 'errors' => ['Run the ownership and CT-period controls migration.'], 'periods' => []];
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND status <> \'superseded\'
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $rows = [];
        foreach ($periods as $period) {
            $rows[] = $this->calculate($companyId, $accountingPeriodId, (int)$period['id']);
        }
        return [
            'available' => true,
            'errors' => [],
            'periods' => $rows,
            'all_close_statuses_calculated' => $rows !== [] && !in_array(false, array_column($rows, 'close_status_calculated'), true),
            'net_tax' => round(array_sum(array_map(static fn(array $row): float => (float)($row['net_tax'] ?? 0), $rows)), 2),
        ];
    }

    public function calculate(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $period = $this->ctPeriod($companyId, $accountingPeriodId, $ctPeriodId);
        if ($period === null) {
            return ['available' => false, 'close_status_calculated' => false, 'errors' => ['The selected CT period could not be found.']];
        }
        $closeCompany = (new OwnershipPartyService())->closeCompanyStatus($companyId, (string)$period['period_end']);
        $closeCompanyStatus = (string)($closeCompany['status'] ?? 'unconfirmed');
        $lock = (new YearEndLockService())->fetchReview($companyId, $accountingPeriodId);
        $lockedAt = !empty($lock['is_locked']) ? trim((string)($lock['locked_at'] ?? '')) : '';
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $cutoff = $lockedAt !== '' ? $lockedAt : $now;
        $deadline = (new \DateTimeImmutable((string)$period['period_end']))->modify('+9 months +1 day')->format('Y-m-d');
        $windowEnd = (new \DateTimeImmutable($deadline))->modify('-1 day')->format('Y-m-d');
        $windowStatus = substr($cutoff, 0, 10) >= $deadline ? 'window_complete' : 'provisional_window_open';

        $evidence = $closeCompanyStatus === 'no'
            ? ['rows' => [], 'errors' => []]
            : $this->cashEvidence($companyId, (string)$period['period_start'], $windowEnd, $cutoff);
        $lotsByParty = [];
        $liabilitiesByParty = [];
        $movements = [];
        $errors = (array)$evidence['errors'];
        $ownership = new OwnershipPartyService();
        $eligibility = [];
        foreach ((array)$evidence['rows'] as $row) {
            $partyId = (int)($row['party_id'] ?? 0);
            $date = (string)$row['txn_date'];
            if ($partyId <= 0) {
                if ($date >= (string)$period['period_start']) {
                    $errors[] = 'Loan transaction #' . (int)($row['transaction_id'] ?? 0) . ' is not linked to a confirmed ownership party.';
                }
                continue;
            }
            $eligibilityKey = $partyId . '|' . $date;
            if (!array_key_exists($eligibilityKey, $eligibility)) {
                $eligibility[$eligibilityKey] = $ownership->isEffectiveParty($companyId, $partyId, $date);
            }
            if (!$eligibility[$eligibilityKey]) {
                $errors[] = 'Loan transaction #' . (int)($row['transaction_id'] ?? 0)
                    . ' is linked to ' . (string)($row['party_name'] ?? 'a party')
                    . ', but that party is not an effective shareholder, participator, or associate on ' . $date . '.';
            }
            $amount = round((float)($row['amount'] ?? 0), 2);
            $kind = (string)$row['cash_direction'];
            $settled = 0.0;
            if ($kind === 'payment') {
                $liability = round((float)($liabilitiesByParty[$partyId] ?? 0), 2);
                $settled = min($liability, $amount);
                $liabilitiesByParty[$partyId] = round($liability - $settled, 2);
                $advance = round($amount - $settled, 2);
                if ($advance >= 0.005) {
                    $rate = $this->rateForDate($date);
                    if ($rate === null) {
                        $errors[] = 'No local s455 rate rule covers loan transaction #' . (int)$row['transaction_id'] . ' dated ' . $date . '.';
                        $rate = 0.0;
                    }
                    $lotsByParty[$partyId][] = [
                        'transaction_id' => (int)$row['transaction_id'],
                        'party_id' => $partyId,
                        'party_name' => (string)$row['party_name'],
                        'origin_date' => $date,
                        'original_amount' => $advance,
                        'remaining_at_period_end' => 0.0,
                        'remaining' => $advance,
                        'rate' => $rate,
                    ];
                }
            } else {
                $remainingReceipt = $amount;
                if (isset($lotsByParty[$partyId])) {
                    foreach ($lotsByParty[$partyId] as &$lot) {
                        if ($remainingReceipt < 0.005 || (float)$lot['remaining'] < 0.005) {
                            continue;
                        }
                        $applied = min((float)$lot['remaining'], $remainingReceipt);
                        $lot['remaining'] = round((float)$lot['remaining'] - $applied, 2);
                        $remainingReceipt = round($remainingReceipt - $applied, 2);
                        $settled += $applied;
                    }
                    unset($lot);
                }
                if ($remainingReceipt >= 0.005) {
                    $liabilitiesByParty[$partyId] = round((float)($liabilitiesByParty[$partyId] ?? 0) + $remainingReceipt, 2);
                }
            }
            $movements[] = $row + ['settled_opposite_balance' => round($settled, 2)];
            if ($date <= (string)$period['period_end']) {
                foreach ($lotsByParty as &$partyLots) {
                    foreach ($partyLots as &$lot) {
                        $lot['remaining_at_period_end'] = (float)$lot['remaining'];
                    }
                    unset($lot);
                }
                unset($partyLots);
            }
        }

        $lots = [];
        $grossPrincipal = 0.0;
        $grossTax = 0.0;
        $qualifyingRepayments = 0.0;
        $reliefTax = 0.0;
        foreach ($lotsByParty as $partyLots) {
            foreach ($partyLots as $lot) {
                if ((string)$lot['origin_date'] < (string)$period['period_start']
                    || (string)$lot['origin_date'] > (string)$period['period_end']) {
                    continue;
                }
                $atEnd = round((float)$lot['remaining_at_period_end'], 2);
                $remaining = round((float)$lot['remaining'], 2);
                $repaid = round(max(0, $atEnd - $remaining), 2);
                $tax = round($atEnd * (float)$lot['rate'], 2);
                $relief = round($repaid * (float)$lot['rate'], 2);
                $lot['remaining_at_period_end'] = $atEnd;
                $lot['qualifying_repayment'] = $repaid;
                $lot['gross_tax'] = $tax;
                $lot['relief_tax'] = $relief;
                $lots[] = $lot;
                $grossPrincipal += $atEnd;
                $grossTax += $tax;
                $qualifyingRepayments += $repaid;
                $reliefTax += $relief;
            }
        }
        $grossPrincipal = round($grossPrincipal, 2);
        $grossTax = round($grossTax, 2);
        $qualifyingRepayments = round($qualifyingRepayments, 2);
        $reliefTax = round($reliefTax, 2);
        $netTax = $closeCompanyStatus === 'yes' ? round(max(0, $grossTax - $reliefTax), 2) : 0.0;
        $basis = [
            'version' => 's455-cash-v1',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'period_start' => (string)$period['period_start'],
            'period_end' => (string)$period['period_end'],
            'repayment_deadline' => $deadline,
            'repayment_window_end' => $windowEnd,
            'evidence_cutoff' => $cutoff,
            'window_status' => $windowStatus,
            'close_company_status' => $closeCompanyStatus,
            'close_company' => $closeCompany,
            'lots' => $lots,
            'movements' => array_map(static fn(array $row): array => [
                'transaction_id' => (int)($row['transaction_id'] ?? 0),
                'party_id' => (int)($row['party_id'] ?? 0),
                'txn_date' => (string)($row['txn_date'] ?? ''),
                'amount' => round((float)($row['amount'] ?? 0), 2),
                'cash_direction' => (string)($row['cash_direction'] ?? ''),
            ], $movements),
            'errors' => array_values(array_unique($errors)),
            'gross_principal' => $grossPrincipal,
            'gross_tax' => $grossTax,
            'qualifying_repayments' => $qualifyingRepayments,
            'relief_tax' => $reliefTax,
            'net_tax' => $netTax,
        ];
        $hashBasis = $basis;
        unset($hashBasis['evidence_cutoff']);
        $basisHash = hash('sha256', json_encode($hashBasis, JSON_UNESCAPED_SLASHES));
        return $basis + [
            'available' => true,
            'errors' => array_values(array_unique($errors)),
            'sequence_no' => (int)$period['sequence_no'],
            'gross_principal' => $grossPrincipal,
            'gross_tax' => $grossTax,
            'qualifying_repayments' => $qualifyingRepayments,
            'relief_tax' => $reliefTax,
            'net_tax' => $netTax,
            'ct600a_required' => $closeCompanyStatus === 'yes' && $grossPrincipal >= 0.005,
            'basis' => $basis,
            'basis_hash' => $basisHash,
            'close_status_calculated' => $closeCompanyStatus !== 'unconfirmed',
        ];
    }

    public function currentNetTax(int $companyId, int $accountingPeriodId, int $ctPeriodId): float
    {
        $review = $this->calculate($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($review['available'])) {
            throw new \RuntimeException((string)(($review['errors'] ?? [])[0] ?? 'The s455 estimate could not be calculated for this CT period.'));
        }
        return round((float)$review['net_tax'], 2);
    }

    public function freezeForYearEndLock(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::inTransaction()) {
            return ['success' => false, 'errors' => ['s455 lock evidence can only be frozen inside the Year End lock transaction.']];
        }
        $lock = (new YearEndLockService())->fetchReview($companyId, $accountingPeriodId);
        if (empty($lock['is_locked']) || trim((string)($lock['locked_at'] ?? '')) === '') {
            return ['success' => false, 'errors' => ['The accounting period must be locked before its s455 evidence cut-off can be frozen.']];
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND status <> \'superseded\'
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $frozen = [];
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            $calculation = $this->calculate($companyId, $accountingPeriodId, $ctPeriodId);
            $this->persistCalculation(
                $calculation,
                (string)$calculation['close_company_status']
            );
            $frozen[] = $ctPeriodId;
        }
        return ['success' => true, 'errors' => [], 'ct_period_ids' => $frozen, 'locked_at' => (string)$lock['locked_at']];
    }

    private function cashEvidence(int $companyId, string $periodStart, string $windowEnd, string $cutoff): array
    {
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $participatorIds = array_values(array_filter([
            (int)($settings['participator_loan_asset_nominal_id'] ?? 0),
            (int)($settings['participator_loan_liability_nominal_id'] ?? 0),
        ]));
        $allIds = $participatorIds;
        if ($allIds === []) {
            return ['rows' => [], 'errors' => ['Configure the Participator Loan control nominals.']];
        }
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $transactionSource = \InterfaceDB::driverName() === 'sqlite'
            ? "'transaction:' || t.id"
            : "CONCAT('transaction:', t.id)";
        $rows = \InterfaceDB::fetchAll(
            'SELECT t.id AS transaction_id, t.txn_date, ABS(t.amount) AS amount,
                    jl.nominal_account_id, jl.debit, jl.credit,
                    COALESCE(t.party_id, cp_director.id) AS party_id,
                    COALESCE(cp.legal_name, cp_director.legal_name, \'Unattributed\') AS party_name,
                    CASE WHEN t.amount < 0 THEN \'payment\' ELSE \'receipt\' END AS cash_direction,
                    t.director_id
             FROM transactions t
             INNER JOIN journals j
               ON j.company_id = t.company_id
              AND j.source_type = \'bank_csv\'
              AND j.source_ref = ' . $transactionSource . '
              AND j.is_posted = 1
             INNER JOIN journal_lines jl ON jl.journal_id = j.id AND jl.nominal_account_id IN (' . $placeholders . ')
             LEFT JOIN company_parties cp ON cp.id = t.party_id AND cp.company_id = t.company_id
             LEFT JOIN company_parties cp_director
               ON cp_director.linked_director_id = t.director_id AND cp_director.company_id = t.company_id
             WHERE t.company_id = ?
               AND t.txn_date <= ?
               AND t.created_at <= ?
               AND t.updated_at <= ?
               AND j.created_at <= ?
               AND j.updated_at <= ?
             ORDER BY t.txn_date, t.id, jl.id',
            array_merge($allIds, [
                $companyId,
                min(substr($cutoff, 0, 10), $windowEnd),
                $cutoff,
                $cutoff,
                $cutoff,
                $cutoff,
            ])
        );
        $errors = [];
        $unsupportedCount = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = ? AND j.is_posted = 1
               AND j.journal_date >= ?
               AND j.journal_date <= ?
               AND j.created_at <= ?
               AND j.updated_at <= ?
               AND jl.nominal_account_id IN (' . $placeholders . ')
               AND NOT (j.source_type = \'bank_csv\' AND j.source_ref LIKE \'transaction:%\')
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entry_metadata jem
                   WHERE jem.journal_id = j.id AND jem.journal_tag = \'director_loan_offset\'
               )',
            array_merge([
                $companyId,
                $periodStart,
                min(substr($cutoff, 0, 10), $windowEnd),
                $cutoff,
                $cutoff,
            ], $allIds)
        );
        if ($unsupportedCount > 0) {
            $errors[] = $unsupportedCount . ' non-cash or unsupported loan movement(s) cannot be used in the v1 s455 calculation.';
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    private function rateForDate(string $date): ?float
    {
        $rate = \InterfaceDB::fetchColumn(
            'SELECT rate FROM s455_rate_rules
             WHERE is_active = 1 AND effective_from <= :loan_date
               AND (effective_to IS NULL OR effective_to >= :loan_date)
             ORDER BY effective_from DESC, id DESC LIMIT 1',
            ['loan_date' => $date]
        );
        return $rate !== false && $rate !== null ? (float)$rate : null;
    }

    private function persistCalculation(array $calculation, string $status): void
    {
        $params = [
            'company_id' => (int)$calculation['company_id'],
            'accounting_period_id' => (int)$calculation['accounting_period_id'],
            'ct_period_id' => (int)$calculation['ct_period_id'],
            'close_company_status' => $status,
            'gross_principal' => (float)$calculation['gross_principal'],
            'gross_tax' => (float)$calculation['gross_tax'],
            'qualifying_repayments' => (float)$calculation['qualifying_repayments'],
            'relief_tax' => (float)$calculation['relief_tax'],
            'net_tax' => (float)$calculation['net_tax'],
            'ct600a_required' => !empty($calculation['ct600a_required']) ? 1 : 0,
            'repayment_deadline' => (string)$calculation['repayment_deadline'],
            'evidence_cutoff' => (string)$calculation['evidence_cutoff'],
            'window_status' => (string)$calculation['window_status'],
            'basis_hash' => (string)$calculation['basis_hash'],
            'basis_json' => json_encode($calculation['basis'], JSON_UNESCAPED_SLASHES),
            'confirmed_at' => null,
            'confirmed_by' => null,
            'confirmation_note' => null,
        ];
        $this->upsert($params);
    }

    private function upsert(array $params): void
    {
        $sql = 'INSERT INTO corporation_tax_s455_reviews (
            company_id, accounting_period_id, ct_period_id, close_company_status,
            gross_principal, gross_tax, qualifying_repayments, relief_tax, net_tax, ct600a_required,
            repayment_deadline, evidence_cutoff, window_status, basis_hash, basis_json,
            confirmed_at, confirmed_by, confirmation_note
        ) VALUES (
            :company_id, :accounting_period_id, :ct_period_id, :close_company_status,
            :gross_principal, :gross_tax, :qualifying_repayments, :relief_tax, :net_tax, :ct600a_required,
            :repayment_deadline, :evidence_cutoff, :window_status, :basis_hash, :basis_json,
            :confirmed_at, :confirmed_by, :confirmation_note
        )';
        if (\InterfaceDB::driverName() === 'sqlite') {
            $sql .= ' ON CONFLICT(ct_period_id) DO UPDATE SET
                close_company_status=excluded.close_company_status, gross_principal=excluded.gross_principal,
                gross_tax=excluded.gross_tax, qualifying_repayments=excluded.qualifying_repayments,
                relief_tax=excluded.relief_tax, net_tax=excluded.net_tax, ct600a_required=excluded.ct600a_required,
                repayment_deadline=excluded.repayment_deadline, evidence_cutoff=excluded.evidence_cutoff,
                window_status=excluded.window_status, basis_hash=excluded.basis_hash, basis_json=excluded.basis_json,
                confirmed_at=excluded.confirmed_at, confirmed_by=excluded.confirmed_by,
                confirmation_note=excluded.confirmation_note, updated_at=CURRENT_TIMESTAMP';
        } else {
            $sql .= ' ON DUPLICATE KEY UPDATE
                close_company_status=VALUES(close_company_status), gross_principal=VALUES(gross_principal),
                gross_tax=VALUES(gross_tax), qualifying_repayments=VALUES(qualifying_repayments),
                relief_tax=VALUES(relief_tax), net_tax=VALUES(net_tax), ct600a_required=VALUES(ct600a_required),
                repayment_deadline=VALUES(repayment_deadline), evidence_cutoff=VALUES(evidence_cutoff),
                window_status=VALUES(window_status), basis_hash=VALUES(basis_hash), basis_json=VALUES(basis_json),
                confirmed_at=VALUES(confirmed_at), confirmed_by=VALUES(confirmed_by),
                confirmation_note=VALUES(confirmation_note), updated_at=CURRENT_TIMESTAMP';
        }
        \InterfaceDB::prepareExecute($sql, $params);
    }

    private function ctPeriod(int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id AS ct_period_id, company_id, accounting_period_id, sequence_no, period_start, period_end
             FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return is_array($row) ? $row : null;
    }
}
