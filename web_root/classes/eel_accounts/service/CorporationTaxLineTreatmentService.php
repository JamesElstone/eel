<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Applies explicit Corporation Tax decisions to individual posted journal
 * lines. Decisions are append-only and are current only while their recorded
 * source/rule basis still matches the live line.
 */
final class CorporationTaxLineTreatmentService
{
    private const TREATMENTS = ['allowable', 'disallowable', 'capital'];
    private ?CorporationTaxTreatmentRuleService $rules = null;
    /** @var array<int, array<string,mixed>|false> */
    private array $decisionCache = [];

    public function resolve(array $line, string $periodStart = '', string $periodEnd = ''): array
    {
        $base = $this->rules()->resolveTaxTreatment($line, $periodStart, $periodEnd);
        if (str_ends_with((string)($base['source'] ?? ''), '_invariant')) {
            return $base;
        }

        $lineId = (int)($line['journal_line_id'] ?? 0);
        if ($lineId <= 0 || !\InterfaceDB::tableExists('corporation_tax_line_treatment_decisions')) {
            return $base;
        }
        $decision = $this->latestDecision($lineId);
        if ($decision === null || !hash_equals((string)$decision['basis_hash'], $this->basisHash($line, $base))) {
            return $base + ['decision' => $decision, 'decision_current' => false];
        }

        return [
            'tax_treatment' => (string)$decision['tax_treatment'],
            'source' => 'corporation_tax_line_treatment_decisions',
            'rule' => $base['rule'] ?? null,
            'decision' => $decision,
            'decision_current' => true,
            'underlying' => $base,
        ];
    }

    public function fetchReview(int $companyId, int $accountingPeriodId, int $ctPeriodId = 0): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'errors' => ['Select a company and accounting period first.'], 'items' => []];
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT period_start, period_end FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return ['available' => false, 'errors' => ['The accounting period could not be found.'], 'items' => []];
        }
        $scope = (new PeriodLedgerReadService())->scope(
            $companyId,
            $accountingPeriodId,
            (string)$period['period_end'],
            (string)$period['period_start']
        );
        $ledgerLines = (new DatedTaxTreatmentLedgerService())->fetch($scope);
        $this->primeDecisions($ledgerLines);
        $items = [];
        foreach ($ledgerLines as $line) {
            $date = (string)($line['journal_date'] ?? '');
            $base = $this->rules()->resolveTaxTreatment($line, $date, $date);
            $latest = $this->latestDecision((int)($line['journal_line_id'] ?? 0));
            if ((string)($base['tax_treatment'] ?? '') !== 'other' && $latest === null) {
                continue;
            }
            $resolved = $this->resolve($line, $date, $date);
            $amount = round((float)($line['total_debit'] ?? 0) - (float)($line['total_credit'] ?? 0), 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $rule = (array)($base['rule'] ?? []);
            $source = $this->source($line);
            $items[] = [
                'journal_id' => (int)($line['journal_id'] ?? 0),
                'journal_line_id' => (int)($line['journal_line_id'] ?? 0),
                'journal_date' => $date,
                'description' => trim((string)($line['line_description'] ?? '')) ?: (string)($line['journal_description'] ?? ''),
                'nominal_code' => (string)($line['code'] ?? ''),
                'nominal_name' => (string)($line['name'] ?? ''),
                'amount' => $amount,
                'tax_treatment' => !empty($resolved['decision_current']) ? (string)$resolved['tax_treatment'] : '',
                'state' => !empty($resolved['decision_current']) ? 'resolved' : ($latest !== null ? 'stale' : 'requires_review'),
                'rule_code' => (string)($rule['rule_code'] ?? ''),
                'rationale' => (string)($rule['rationale'] ?? ''),
                'guidance_url' => (string)($rule['source_url'] ?? ''),
                'source_label' => $source['label'],
                'source_url' => $source['url'],
            ];
        }

        return [
            'available' => true,
            'errors' => [],
            'items' => $items,
            'unresolved_count' => count(array_filter($items, static fn(array $item): bool => $item['state'] !== 'resolved')),
            'resolved_count' => count(array_filter($items, static fn(array $item): bool => $item['state'] === 'resolved')),
        ];
    }

    public function save(int $companyId, int $accountingPeriodId, int $journalLineId, string $treatment, string $actor): array
    {
        $treatment = strtolower(trim($treatment));
        if (!in_array($treatment, self::TREATMENTS, true)) {
            return ['success' => false, 'errors' => ['Choose Allowable, Disallowable, or Capital.']];
        }
        if (!\InterfaceDB::tableExists('corporation_tax_line_treatment_decisions')) {
            return ['success' => false, 'errors' => ['Run the Corporation Tax line-treatment migration first.']];
        }
        if ((new YearEndLockService())->isLocked($companyId, $accountingPeriodId)) {
            return ['success' => false, 'errors' => ['The accounting period is locked and its tax treatments cannot be changed.']];
        }
        $line = $this->fetchLine($companyId, $accountingPeriodId, $journalLineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['The posted journal line could not be found or has been superseded.']];
        }
        $filed = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
               AND :journal_date BETWEEN period_start AND period_end
               AND status IN (\'submitted\', \'accepted\')',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'journal_date' => (string)$line['journal_date']]
        );
        if ($filed > 0) {
            return ['success' => false, 'errors' => ['The Corporation Tax period has been submitted or accepted and cannot be changed.']];
        }
        $date = (string)$line['journal_date'];
        $base = $this->rules()->resolveTaxTreatment($line, $date, $date);
        if ((string)($base['tax_treatment'] ?? '') !== 'other' && $this->latestDecision($journalLineId) === null) {
            return ['success' => false, 'errors' => ['This line is not a Corporation Tax treatment item requiring review.']];
        }
        $rule = (array)($base['rule'] ?? []);
        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_line_treatment_decisions (
                company_id, accounting_period_id, journal_id, journal_line_id,
                tax_treatment, basis_hash, rule_id, rule_code, rule_version, decided_by
             ) VALUES (
                :company_id, :accounting_period_id, :journal_id, :journal_line_id,
                :tax_treatment, :basis_hash, :rule_id, :rule_code, :rule_version, :decided_by
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_id' => (int)$line['journal_id'],
                'journal_line_id' => $journalLineId,
                'tax_treatment' => $treatment,
                'basis_hash' => $this->basisHash($line, $base),
                'rule_id' => (int)($rule['id'] ?? 0) ?: null,
                'rule_code' => (string)($rule['rule_code'] ?? ''),
                'rule_version' => (string)($rule['rule_version'] ?? ''),
                'decided_by' => trim($actor) !== '' ? trim($actor) : 'web_app',
            ]
        );
        \eel_accounts\Support\RequestCache::clear();
        unset($this->decisionCache[$journalLineId]);

        return ['success' => true, 'errors' => [], 'tax_treatment' => $treatment];
    }

    private function fetchLine(int $companyId, int $accountingPeriodId, int $journalLineId): ?array
    {
        $correctionJoins = '';
        $correctionWhere = '';
        if (\InterfaceDB::tableExists('journal_reversals')) {
            $correctionJoins = ' LEFT JOIN journal_reversals jr_source ON jr_source.source_journal_id = j.id
                LEFT JOIN journal_reversals jr_reversal ON jr_reversal.reversal_journal_id = j.id';
            $correctionWhere = ' AND jr_source.source_journal_id IS NULL AND jr_reversal.reversal_journal_id IS NULL';
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT j.id AS journal_id, jl.id AS journal_line_id, j.journal_date,
                    COALESCE(j.source_type, \'\') AS source_type, COALESCE(j.source_ref, \'\') AS source_ref,
                    COALESCE(j.description, \'\') AS journal_description,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    jl.debit AS total_debit, jl.credit AS total_credit,
                    na.id AS nominal_account_id, na.id, COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name, na.account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    COALESCE(nas.code, \'\') AS account_subtype_code,
                    CASE WHEN COALESCE(j.source_type, \'\') = \'asset_disposal\' THEN \'asset_disposal\' ELSE \'\' END AS journal_source_type
             FROM journal_lines jl INNER JOIN journals j ON j.id = jl.journal_id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id'
             . $correctionJoins . '
             WHERE jl.id = :journal_line_id AND j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id AND j.is_posted = 1'
             . $correctionWhere . ' LIMIT 1',
            ['journal_line_id' => $journalLineId, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        return is_array($row) ? $row : null;
    }

    private function latestDecision(int $journalLineId): ?array
    {
        if ($journalLineId <= 0 || !\InterfaceDB::tableExists('corporation_tax_line_treatment_decisions')) {
            return null;
        }
        if (array_key_exists($journalLineId, $this->decisionCache)) {
            return is_array($this->decisionCache[$journalLineId]) ? $this->decisionCache[$journalLineId] : null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_line_treatment_decisions
             WHERE journal_line_id = :journal_line_id ORDER BY id DESC LIMIT 1',
            ['journal_line_id' => $journalLineId]
        );
        $this->decisionCache[$journalLineId] = is_array($row) ? $row : false;
        return is_array($row) ? $row : null;
    }

    /** @param list<array<string,mixed>> $lines */
    public function primeDecisions(array $lines): void
    {
        if (!\InterfaceDB::tableExists('corporation_tax_line_treatment_decisions')) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $line): int => (int)($line['journal_line_id'] ?? 0),
            $lines
        ))));
        $missing = array_values(array_filter($ids, fn(int $id): bool => !array_key_exists($id, $this->decisionCache)));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->decisionCache[$id] = false;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM corporation_tax_line_treatment_decisions
             WHERE journal_line_id IN (' . implode(',', array_fill(0, count($missing), '?')) . ')
             ORDER BY journal_line_id ASC, id DESC',
            $missing
        );
        foreach ($rows as $row) {
            $id = (int)($row['journal_line_id'] ?? 0);
            if ($id > 0 && $this->decisionCache[$id] === false) {
                $this->decisionCache[$id] = $row;
            }
        }
    }

    private function rules(): CorporationTaxTreatmentRuleService
    {
        return $this->rules ??= new CorporationTaxTreatmentRuleService();
    }

    private function basisHash(array $line, array $base): string
    {
        $rule = (array)($base['rule'] ?? []);
        return hash('sha256', json_encode([
            'journal_id' => (int)($line['journal_id'] ?? 0),
            'journal_line_id' => (int)($line['journal_line_id'] ?? 0),
            'journal_date' => (string)($line['journal_date'] ?? ''),
            'nominal_account_id' => (int)($line['nominal_account_id'] ?? $line['id'] ?? 0),
            'debit' => number_format((float)($line['total_debit'] ?? $line['debit'] ?? 0), 2, '.', ''),
            'credit' => number_format((float)($line['total_credit'] ?? $line['credit'] ?? 0), 2, '.', ''),
            'source_type' => (string)($line['source_type'] ?? $line['journal_source_type'] ?? ''),
            'source_ref' => (string)($line['source_ref'] ?? ''),
            'rule_id' => (int)($rule['id'] ?? 0),
            'rule_code' => (string)($rule['rule_code'] ?? ''),
            'rule_version' => (string)($rule['rule_version'] ?? ''),
            'rule_source_url' => (string)($rule['source_url'] ?? ''),
            'rule_rationale' => (string)($rule['rationale'] ?? ''),
            'rule_active' => (int)($rule['is_active'] ?? 0),
            'rule_review_status' => (string)($rule['review_status'] ?? ''),
            'rule_source_checked_at' => (string)($rule['source_checked_at'] ?? ''),
            'rule_effective_from' => (string)($rule['effective_from'] ?? ''),
            'rule_effective_to' => (string)($rule['effective_to'] ?? ''),
            'base_treatment' => (string)($base['tax_treatment'] ?? ''),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function source(array $line): array
    {
        $journalId = (int)($line['journal_id'] ?? 0);
        if ((string)($line['source_type'] ?? '') === 'bank_csv'
            && preg_match('/^transaction:(\d+)/', (string)($line['source_ref'] ?? ''), $matches) === 1) {
            return ['label' => 'Transaction #' . $matches[1], 'url' => '?page=transactions&show_card=transactions_imported&transaction_id=' . $matches[1]];
        }
        $claim = \InterfaceDB::tableExists('expense_claims')
            ? \InterfaceDB::fetchOne('SELECT id, claim_reference_code FROM expense_claims WHERE posted_journal_id = :journal_id LIMIT 1', ['journal_id' => $journalId])
            : null;
        if (is_array($claim)) {
            return ['label' => 'Expense claim ' . (string)($claim['claim_reference_code'] ?? ('#' . $claim['id'])), 'url' => '?page=expense_claims&show_card=expense_claim_editor&claim_id=' . (int)$claim['id']];
        }
        return ['label' => 'Journal #' . $journalId, 'url' => '?page=journal&show_card=journal_entries&journal_id=' . $journalId];
    }
}
