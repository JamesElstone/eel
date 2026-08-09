<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds canonical, record-level evidence sections while Year End is locked. */
final class FilingEvidenceSnapshotService
{
    public const VERSION = 'filing-evidence-sections-v1';

    /** @return list<array<string,mixed>> */
    public function prepareForLock(int $companyId, int $accountingPeriodId, ?array $loanSnapshot, array $taxSnapshots): array
    {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('Filing evidence sections can only be captured inside the Year End lock transaction.');
        }
        $sections = [
            'transactions' => $this->transactions($companyId, $accountingPeriodId),
            'charitable_donations' => $this->charitableDonations($companyId, $accountingPeriodId),
            'expense_claims' => $this->expenseClaims($companyId, $accountingPeriodId),
            'loans' => $this->loans($loanSnapshot),
            'assets' => $this->assets($companyId, $accountingPeriodId),
            'prepayments' => $this->prepayments($companyId, $accountingPeriodId),
            'journals' => $this->journals($companyId, $accountingPeriodId),
            'profit_loss' => $this->profitLoss($companyId, $accountingPeriodId),
            'corporation_tax' => $this->corporationTax($taxSnapshots, $loanSnapshot),
            'companies_house' => $this->companiesHouse($companyId, $accountingPeriodId),
        ];
        $prepared = [];
        foreach ($sections as $code => $payload) {
            $json = $this->canonicalJson($payload);
            $prepared[] = [
                'section_code' => $code,
                'section_version' => self::VERSION,
                'snapshot_kind' => 'lock',
                'sequence_no' => 1,
                'record_count' => (int)($payload['record_count'] ?? 0),
                'totals_json' => $this->canonicalJson((array)($payload['totals'] ?? [])),
                'snapshot_json' => $json,
                'snapshot_hash' => hash('sha256', $json),
            ];
        }
        return $prepared;
    }

    /** @param list<array<string,mixed>> $sections */
    public function persist(int $bundleId, array $sections): void
    {
        foreach ($sections as $section) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO filing_evidence_section_snapshots
                    (bundle_id, section_code, section_version, snapshot_kind, sequence_no,
                     record_count, totals_json, snapshot_json, snapshot_hash)
                 VALUES (:bundle_id, :code, :version, :kind, :sequence_no,
                         :record_count, :totals, :snapshot, :hash)',
                [
                    'bundle_id' => $bundleId, 'code' => (string)$section['section_code'],
                    'version' => (string)$section['section_version'], 'kind' => (string)$section['snapshot_kind'],
                    'sequence_no' => (int)$section['sequence_no'], 'record_count' => (int)$section['record_count'],
                    'totals' => (string)$section['totals_json'], 'snapshot' => (string)$section['snapshot_json'],
                    'hash' => (string)$section['snapshot_hash'],
                ]
            );
        }
    }

    /** @return array<string,mixed> */
    public function lifecyclePayload(string $eventType, string $status, string $message, array $context): array
    {
        return [
            'snapshot_version' => self::VERSION,
            'event_type' => $eventType,
            'event_status' => $status,
            'event_message' => $message,
            'event_context' => $context,
            'record_count' => 1,
            'totals' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function transactions(int $companyId, int $periodId): array
    {
        $rows = $this->rows('SELECT t.id, t.txn_date, t.txn_type, t.description, t.reference, t.amount, t.currency,
                    t.source_type, t.source_account_label, t.source_category, t.source_document_url,
                    t.local_document_path, t.document_url_hash, t.document_downloaded_at, t.document_download_status,
                    t.counterparty_name, t.card, t.dedupe_hash, t.nominal_account_id, na.code AS nominal_code,
                    na.name AS nominal_name, t.director_id, t.party_id, t.transfer_account_id, t.is_internal_transfer,
                    t.category_status, t.auto_rule_id, t.is_auto_excluded, t.notes
             FROM transactions t LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id AND t.accounting_period_id = :period_id ORDER BY t.txn_date, t.id', $companyId, $periodId);
        return $this->payload('transactions', $rows, ['amount' => $this->sum($rows, 'amount')]);
    }

    /** @return array<string,mixed> */
    private function expenseClaims(int $companyId, int $periodId): array
    {
        $claims = $this->rows('SELECT id, claimant_id, claim_year, claim_month, period_start, period_end,
                    claim_reference_code, brought_forward_amount, claimed_amount, payments_amount,
                    carried_forward_amount, status, posted_journal_id, no_lines_confirmed_at, no_lines_confirmed_by, notes
             FROM expense_claims WHERE company_id = :company_id AND accounting_period_id = :period_id ORDER BY id', $companyId, $periodId);
        $lines = $this->rows('SELECT ecl.id, ecl.expense_claim_id, ecl.line_number, ecl.expense_date, ecl.description,
                    ecl.amount, ecl.nominal_account_id, na.code AS nominal_code, na.name AS nominal_name,
                    ecl.director_id, ecl.receipt_reference, ecl.notes
             FROM expense_claim_lines ecl INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             LEFT JOIN nominal_accounts na ON na.id = ecl.nominal_account_id
             WHERE ec.company_id = :company_id AND ec.accounting_period_id = :period_id ORDER BY ecl.expense_claim_id, ecl.line_number', $companyId, $periodId);
        return $this->payload('expense_claims', ['claims' => $claims, 'lines' => $lines], ['claimed_amount' => $this->sum($lines, 'amount')], count($claims) + count($lines));
    }

    /** @return array<string,mixed> */
    private function charitableDonations(int $companyId, int $periodId): array
    {
        $rows = [];
        $categorisation = new TransactionCategorisationService();
        foreach ((new CharitableDonationService())->fetchCurrentForPeriod($companyId, $periodId) as $transactionId => $verification) {
            $transaction = $categorisation->fetchTransaction((int)$transactionId);
            if (!is_array($transaction)) {
                continue;
            }
            $rows[] = array_merge($verification, [
                'transaction_id' => (int)$transactionId,
                'txn_date' => (string)($transaction['txn_date'] ?? ''),
                'amount' => round(abs((float)($transaction['amount'] ?? 0)), 2),
                'nominal_account_id' => (int)($transaction['nominal_account_id'] ?? 0),
            ]);
        }
        return $this->payload('charitable_donations', $rows, ['verified_amount' => $this->sum($rows, 'amount')]);
    }

    /** @return array<string,mixed> */
    private function loans(?array $loanSnapshot): array
    {
        $payload = (array)($loanSnapshot['payload'] ?? []);
        return $this->payload('loans', ['loan_snapshot' => $payload, 'loan_snapshot_hash' => (string)($loanSnapshot['snapshot_hash'] ?? '')], [], $payload === [] ? 0 : 1);
    }

    /** @return array<string,mixed> */
    private function assets(int $companyId, int $periodId): array
    {
        $periodEnd = (string)\InterfaceDB::fetchColumn('SELECT period_end FROM accounting_periods WHERE id = :period_id AND company_id = :company_id', ['period_id' => $periodId, 'company_id' => $companyId]);
        $assets = $this->rows('SELECT ar.id, ar.asset_code, ar.description, ar.category, ar.purchase_date, ar.cost,
                    ar.useful_life_years, ar.depreciation_method, ar.residual_value, ar.status, ar.disposal_date,
                    ar.disposal_proceeds, ar.disposal_event_type, ar.disposal_reason, ar.nominal_account_id,
                    ar.accum_dep_nominal_id, ar.linked_journal_id, ar.linked_transaction_id,
                    ar.linked_expense_claim_line_id, ar.linked_transaction_split_line_id, ar.manual_addition_reason,
                    ar.manual_evidence_path, ar.manual_evidence_sha256, ar.manual_evidence_original_filename,
                    ar.manual_evidence_content_type, ar.manual_evidence_size_bytes, ar.manual_legal_warning_version,
                    ar.manual_legal_acknowledged_at
             FROM asset_register ar WHERE ar.company_id = :company_id AND ar.purchase_date <= :period_end
               AND (ar.disposal_date IS NULL OR ar.disposal_date >= :period_end) ORDER BY ar.purchase_date, ar.id', $companyId, $periodId, ['period_end' => $periodEnd]);
        $calculations = \InterfaceDB::tableExists('capital_allowance_asset_calculations')
            ? $this->rows('SELECT * FROM capital_allowance_asset_calculations WHERE company_id = :company_id AND accounting_period_id = :period_id ORDER BY asset_id, id', $companyId, $periodId) : [];
        return $this->payload('assets', ['assets' => $assets, 'capital_allowance_calculations' => $calculations], ['cost' => $this->sum($assets, 'cost')], count($assets) + count($calculations));
    }

    /** @return array<string,mixed> */
    private function prepayments(int $companyId, int $periodId): array
    {
        $reviews = $this->rows('SELECT * FROM prepayment_reviews WHERE company_id = :company_id AND accounting_period_id = :period_id ORDER BY id', $companyId, $periodId);
        $schedules = $this->rows('SELECT ps.* FROM prepayment_schedules ps INNER JOIN prepayment_schedule_periods psp ON psp.schedule_id = ps.id
             WHERE ps.company_id = :company_id AND psp.accounting_period_id = :period_id ORDER BY ps.id, psp.id', $companyId, $periodId);
        $periods = $this->rows('SELECT psp.* FROM prepayment_schedule_periods psp INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id
             WHERE ps.company_id = :company_id AND psp.accounting_period_id = :period_id ORDER BY psp.schedule_id, psp.id', $companyId, $periodId);
        $postings = $this->rows('SELECT p.* FROM prepayment_schedule_postings p INNER JOIN prepayment_schedules ps ON ps.id = p.schedule_id
             WHERE ps.company_id = :company_id AND p.accounting_period_id = :period_id ORDER BY p.id', $companyId, $periodId);
        return $this->payload('prepayments', ['reviews' => $reviews, 'schedules' => $schedules, 'periods' => $periods, 'postings' => $postings], ['expense_pence' => $this->sum($periods, 'expense_pence'), 'closing_deferred_pence' => $this->sum($periods, 'closing_deferred_pence')], count($reviews) + count($schedules) + count($periods) + count($postings));
    }

    /** @return array<string,mixed> */
    private function journals(int $companyId, int $periodId): array
    {
        $rows = $this->rows('SELECT j.id AS journal_id, j.source_type, j.source_ref, j.journal_date, j.description, j.is_posted,
                    jl.id AS journal_line_id, jl.nominal_account_id, na.code AS nominal_code, na.name AS nominal_name,
                    jl.director_id, jl.party_id, jl.company_account_id, jl.debit, jl.credit, jl.line_description
             FROM journals j INNER JOIN journal_lines jl ON jl.journal_id = j.id
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id AND j.accounting_period_id = :period_id AND j.is_posted = 1
             ORDER BY j.journal_date, j.id, jl.id', $companyId, $periodId);
        return $this->payload('journals', $rows, ['debit' => $this->sum($rows, 'debit'), 'credit' => $this->sum($rows, 'credit')]);
    }

    /** @return array<string,mixed> */
    private function profitLoss(int $companyId, int $periodId): array
    {
        $service = new ProfitLossService();
        $summary = $service->getProfitLossSummary($companyId, $periodId);
        $breakdown = $service->getProfitLossBreakdown($companyId, $periodId);
        $trend = $service->getMonthlyProfitLossTrend($companyId, $periodId);
        return $this->payload('profit_loss', ['summary' => $summary, 'breakdown' => $breakdown, 'monthly_trend' => $trend, 'journal_section' => 'journals'], ['net_profit' => (float)($summary['net_profit'] ?? 0)]);
    }

    /** @return array<string,mixed> */
    private function corporationTax(array $snapshots, ?array $loanSnapshot): array
    {
        return $this->payload('corporation_tax', ['ct_snapshots' => $snapshots, 'loan_snapshot_hash' => (string)($loanSnapshot['snapshot_hash'] ?? '')], [], count($snapshots));
    }

    /** @return array<string,mixed> */
    private function companiesHouse(int $companyId, int $periodId): array
    {
        $rows = \InterfaceDB::tableExists('companies_house_accounts_submissions')
            ? $this->rows('SELECT id, environment, filing_type, lifecycle, submission_number, gateway_submission_reference,
                    artifact_path, artifact_sha256, revised_artifact_path, revised_artifact_sha256, filing_metadata_json,
                    basis_hash, gateway_status_summary, rejection_code, rejection_description, examiner_comments,
                    prepared_at, submitted_at, accepted_at, rejected_at
                 FROM companies_house_accounts_submissions WHERE company_id = :company_id AND accounting_period_id = :period_id ORDER BY id', $companyId, $periodId) : [];
        return $this->payload('companies_house', $rows, [], count($rows));
    }

    /** @return array<string,mixed> */
    private function payload(string $section, mixed $records, array $totals = [], ?int $recordCount = null): array
    {
        return ['snapshot_version' => self::VERSION, 'section' => $section, 'records' => $records,
            'record_count' => $recordCount ?? (is_array($records) ? count($records) : 0), 'totals' => $totals];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, int $companyId, int $periodId, array $extra = []): array
    {
        return \InterfaceDB::fetchAll($sql, array_merge(['company_id' => $companyId, 'period_id' => $periodId], $extra)) ?: [];
    }
    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows, string $field): float { return round(array_sum(array_map(static fn(array $row): float => (float)($row[$field] ?? 0), $rows)), 2); }
    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed { if (!is_array($item)) { return $item; } if (array_is_list($item)) { return array_map($normalise, $item); } ksort($item, SORT_STRING); foreach ($item as $key => $child) { $item[$key] = $normalise($child); } return $item; };
        return \eel_accounts\Support\PersistentJson::encode($normalise($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
