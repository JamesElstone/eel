<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class Ct600aService
{
    public const REVIEW_VERSION = 'ct600a-review-v2';
    public const MODEL_VERSION = 'ct600a-model-v2';
    public const RETURN_PAYMENT_RELIEF_CUTOFF = '2024-10-30';
    private const ANSWER_KEYS = [
        'missing_parties',
        'unrecorded_value',
        'indirect_benefit',
        'noncommercial_value',
        'tax_avoidance_arrangement',
        'replacement_extraction',
    ];
    private const EVENT_KINDS = [
        'opening_outstanding', 'release', 'write_off', 'later_repayment',
        's464a_benefit', 's464a_return_payment',
    ];

    /** @return array<string,string> */
    public function reviewQuestions(): array
    {
        return [
            'missing_parties' => 'Are any participators or associates missing from the ownership and relationship records?',
            'unrecorded_value' => 'Was any value transferred to a participator or associate outside the recorded loan accounts?',
            'indirect_benefit' => 'Was any benefit routed through a partnership, trust, connected company, fiduciary or representative?',
            'noncommercial_value' => 'Were company assets, services, expenses or other value supplied outside a valid commercial, remuneration, dividend or loan treatment?',
            'tax_avoidance_arrangement' => 'Was the company party to arrangements whose main purpose included avoiding section 455 or obtaining a tax advantage?',
            'replacement_extraction' => 'Was a repayment or return payment connected with a replacement loan, benefit or other extraction under section 464C?',
        ];
    }

    /** @return array<string,mixed> */
    public function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $key = \eel_accounts\Support\RequestCache::key('fetch', $companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            fn(): array => $this->fetchForAccountingPeriodUncached($companyId, $accountingPeriodId)
        );
    }

    /** @return array<string,mixed> */
    private function fetchForAccountingPeriodUncached(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->schemaReady()) {
            return ['available' => false, 'errors' => ['Apply database migration 2026_07_21_005_ct600a_accounting_period_review.sql.'], 'periods' => []];
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end, status FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        $review = $this->reviewStatus($companyId, $accountingPeriodId);
        return [
            'available' => true,
            'errors' => [],
            'questions' => $this->reviewQuestions(),
            'review' => $review,
            'parties' => (new OwnershipPartyService())->fetchSummary($companyId)['parties'] ?? [],
            'periods' => array_map(fn(array $period): array => $this->displayModelForPeriod(
                $companyId,
                $accountingPeriodId,
                $period,
                $review
            ), $periods),
        ];
    }

    /**
     * Lightweight accounting-period declaration state for cards that do not
     * need the complete CT600A period models.
     *
     * @return array<string,mixed>
     */
    public function fetchReviewForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $key = \eel_accounts\Support\RequestCache::key('review-fetch', $companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            function () use ($companyId, $accountingPeriodId): array {
                if (!$this->schemaReady()) {
                    return [
                        'available' => false,
                        'errors' => ['Apply database migration 2026_07_21_005_ct600a_accounting_period_review.sql.'],
                        'questions' => $this->reviewQuestions(),
                        'review' => [],
                    ];
                }
                return [
                    'available' => true,
                    'errors' => [],
                    'questions' => $this->reviewQuestions(),
                    'review' => $this->reviewStatus($companyId, $accountingPeriodId),
                ];
            }
        );
    }

    /**
     * Transaction-derived relief claims on already-filed CT600A returns whose
     * relief due date belongs to the selected accounting period.
     *
     * @return array<string,mixed>
     */
    public function fetchL2pReliefForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        ?string $asOf = null
    ): array {
        $asOfKey = trim((string)$asOf);
        $key = \eel_accounts\Support\RequestCache::key(
            'l2p-relief',
            $companyId,
            $accountingPeriodId,
            $asOfKey === '' ? '@today' : $asOfKey
        );
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            fn(): array => $this->fetchL2pReliefForAccountingPeriodUncached($companyId, $accountingPeriodId, $asOf)
        );
    }

    /** @return array<string,mixed> */
    private function fetchL2pReliefForAccountingPeriodUncached(
        int $companyId,
        int $accountingPeriodId,
        ?string $asOf = null
    ): array {
        $accountingPeriod = \InterfaceDB::fetchOne(
            'SELECT period_start, period_end FROM accounting_periods
             WHERE id = :period_id AND company_id = :company_id',
            ['period_id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($accountingPeriod)) {
            return ['available' => false, 'errors' => ['The accounting period could not be found.'], 'claims' => []];
        }
        $asOfDate = $this->validDate((string)$asOf)
            ? (string)$asOf
            : (new \DateTimeImmutable('now'))->format('Y-m-d');
        $originPeriods = \InterfaceDB::fetchAll(
            'SELECT id, accounting_period_id, sequence_no, period_start, period_end, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id AND status IN (:submitted, :accepted)
             ORDER BY period_start, sequence_no, id',
            ['company_id' => $companyId, 'submitted' => 'submitted', 'accepted' => 'accepted']
        );
        $claims = [];
        $errors = [];
        foreach ($originPeriods as $originPeriod) {
            $originAccountingPeriodId = (int)($originPeriod['accounting_period_id'] ?? 0);
            $originCtPeriodId = (int)($originPeriod['id'] ?? 0);
            if ($originAccountingPeriodId <= 0 || $originCtPeriodId <= 0) {
                continue;
            }
            $model = $this->build($companyId, $originAccountingPeriodId, $originCtPeriodId, $asOfDate);
            if (empty($model['available'])) {
                foreach ((array)($model['errors'] ?? []) as $error) {
                    $errors[] = (string)$error;
                }
                continue;
            }
            foreach ((array)($model['separate_l2p_claim_events'] ?? []) as $claim) {
                if (!is_array($claim)) {
                    continue;
                }
                if ((string)($claim['claim_type'] ?? '') !== 'later_l2p') {
                    continue;
                }
                $claimDate = (string)($claim['date'] ?? '');
                $recognitionDate = $this->validDate((string)($claim['relief_due_date'] ?? ''))
                    ? (string)$claim['relief_due_date']
                    : $claimDate;
                if ($recognitionDate < (string)$accountingPeriod['period_start']
                    || $recognitionDate > (string)$accountingPeriod['period_end']) {
                    continue;
                }
                $claim['recognition_date'] = $recognitionDate;
                $claim['originating_ct_period_id'] = $originCtPeriodId;
                $claim['originating_accounting_period_id'] = $originAccountingPeriodId;
                $key = $originCtPeriodId . '|'
                    . (int)($claim['repayment_transaction_id'] ?? 0) . '|'
                    . (int)($claim['loan_transaction_id'] ?? 0) . '|'
                    . (int)($claim['party_id'] ?? 0) . '|'
                    . (string)($claim['source'] ?? '') . '|' . $claimDate . '|'
                    . number_format((float)($claim['amount_repaid'] ?? 0), 2, '.', '') . '|'
                    . number_format((float)($claim['amount_released_or_written_off'] ?? 0), 2, '.', '') . '|'
                    . number_format((float)($claim['relief_tax'] ?? 0), 2, '.', '');
                $claims[$key] = $claim;
            }
        }
        $claims = array_values($claims);

        return [
            'available' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'claims' => $claims,
            'relief_receivable' => round(array_sum(array_map(
                static fn(array $claim): float => (float)($claim['relief_tax'] ?? 0),
                $claims
            )), 2),
            'basis_hash' => hash('sha256', $this->canonicalJson($claims)),
        ];
    }

    /** @return array<string,mixed> */
    private function displayModelForPeriod(
        int $companyId,
        int $accountingPeriodId,
        array $period,
        array $review
    ): array
    {
        $ctPeriodId = (int)($period['id'] ?? 0);
        return $this->buildUncached($companyId, $accountingPeriodId, $ctPeriodId, null, $review);
    }

    /** @return array<string,mixed> */
    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId, ?string $asOf = null): array
    {
        $asOfKey = trim((string)$asOf);
        $key = \eel_accounts\Support\RequestCache::key(
            'build',
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $asOfKey === '' ? '@today' : $asOfKey
        );
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            fn(): array => $this->buildUncached($companyId, $accountingPeriodId, $ctPeriodId, $asOf)
        );
    }

    /** @return array<string,mixed> */
    private function buildUncached(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?string $asOf = null,
        ?array $resolvedReview = null
    ): array
    {
        if (!$this->schemaReady()) {
            return ['available' => false, 'errors' => ['Apply database migration 2026_07_21_005_ct600a_accounting_period_review.sql.']];
        }
        $period = $this->period($companyId, $accountingPeriodId, $ctPeriodId);
        if ($period === null) {
            return ['available' => false, 'errors' => ['The CT period could not be found.']];
        }
        $asOfDate = $this->validDate((string)$asOf)
            ? (string)$asOf
            : (new \DateTimeImmutable('now'))->format('Y-m-d');
        $evidenceCutoff = $asOfDate . ' 23:59:59';
        $s455Service = new S455ReviewService();
        $s455 = $s455Service->calculate(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $evidenceCutoff
        );
        $transactionLedger = $s455Service->transactionLedgerThrough(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $asOfDate,
            $evidenceCutoff
        );
        if (!empty($transactionLedger['available'])) {
            $s455['all_lots'] = (array)($transactionLedger['all_lots'] ?? []);
            $s455['all_repayment_allocations'] = $this->withReliefDueDates(
                $companyId,
                (array)($transactionLedger['repayment_allocations'] ?? [])
            );
            $s455['transaction_ledger_basis_hash'] = (string)($transactionLedger['basis_hash'] ?? '');
        }
        $events = $this->events($companyId, $accountingPeriodId, $ctPeriodId);
        $events = $this->withReliefDueDates($companyId, $events, 'event_date');
        $review = $resolvedReview ?? $this->reviewStatus($companyId, $accountingPeriodId);
        $model = $this->buildFromEvidence($period, $s455, $events, $review, $asOfDate);
        $result = $model + [
            'available' => true,
            'sequence_no' => (int)$period['sequence_no'],
            'ct_period_id' => $ctPeriodId,
            'period_start' => (string)$period['period_start'],
            'period_end' => (string)$period['period_end'],
            'review' => $review,
            'events' => $events,
            's455' => $s455,
        ];
        if (!in_array((string)($period['status'] ?? ''), ['submitted', 'accepted'], true)) {
            return $result;
        }

        // The return liability is immutable once filed.  Continue deriving
        // post-filing repayment evidence only for a separate L2P position;
        // it must never reopen A45/A70/A80 on the submitted return.
        $frozenCt600a = $this->frozenCt600aForFiledPeriod($companyId, $accountingPeriodId, $ctPeriodId);
        if ($frozenCt600a === null) {
            // Accepted records predating the immutable filing-basis feature
            // remain readable through the legacy calculation.  Once a basis
            // exists, however, a damaged or unverifiable one must fail closed.
            if ($this->filedBasisExists($companyId, $accountingPeriodId, $ctPeriodId)) {
                $result['available'] = false;
                $result['errors'] = ['The immutable CT600A filing basis could not be verified.'];
            }
            return $result;
        }
        $liveClaims = (array)($result['separate_l2p_claim_events'] ?? []);
        $liveL2pRelief = (float)($result['separate_l2p_relief_due'] ?? 0);
        $liveWarnings = (array)($result['evidence_warnings'] ?? []);
        $frozenWarnings = (array)($frozenCt600a['evidence_warnings'] ?? []);
        $result = array_replace($result, $frozenCt600a);
        $result['separate_l2p_claim_events'] = $liveClaims;
        $result['separate_l2p_relief_due'] = $liveL2pRelief;
        $result['evidence_warnings'] = array_values(array_unique(array_merge($frozenWarnings, $liveWarnings)));
        $result['immutable'] = true;
        return $result;
    }

    /** @return null|array<string,mixed> */
    private function frozenCt600aForFiledPeriod(int $companyId, int $accountingPeriodId, int $ctPeriodId): ?array
    {
        if (!\InterfaceDB::tableExists('ct_period_filing_bases')
            || !\InterfaceDB::tableExists('ixbrl_accounts_filing_approvals')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT b.basis_version, b.basis_hash, b.basis_json, b.calculation_basis_hash,
                    a.basis_hash AS approval_basis_hash
             FROM ct_period_filing_bases b
             INNER JOIN ixbrl_accounts_filing_approvals a ON a.id = b.filing_approval_id
             WHERE b.company_id = :company_id
               AND b.accounting_period_id = :period_id
               AND b.ct_period_id = :ct_period_id
             ORDER BY b.id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        );
        if (!is_array($row)) {
            return null;
        }
        $model = json_decode((string)($row['basis_json'] ?? ''), true);
        if (!is_array($model)
            || (int)($model['ct_period']['id'] ?? 0) !== $ctPeriodId
            || !is_array($model['ct600a'] ?? null)) {
            return null;
        }
        $canonical = $this->filingBasisCanonicalJson($model);
        $hash = hash(
            'sha256',
            CtPeriodFilingModelService::BASIS_VERSION . '|'
            . (string)($row['approval_basis_hash'] ?? '') . '|'
            . (string)($row['calculation_basis_hash'] ?? '') . '|'
            . $canonical
        );
        if ((string)($row['basis_version'] ?? '') !== CtPeriodFilingModelService::BASIS_VERSION
            || !hash_equals((string)($row['basis_hash'] ?? ''), $hash)) {
            return null;
        }
        return (array)$model['ct600a'];
    }

    private function filedBasisExists(int $companyId, int $accountingPeriodId, int $ctPeriodId): bool
    {
        if (!\InterfaceDB::tableExists('ct_period_filing_bases')) {
            return false;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM ct_period_filing_bases
             WHERE company_id = :company_id
               AND accounting_period_id = :period_id
               AND ct_period_id = :ct_period_id',
            [
                'company_id' => $companyId,
                'period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        ) > 0;
    }

    /**
     * Pure adapter used by tests and by the immutable filing model.
     * @return array<string,mixed>
     */
    public function buildFromEvidence(array $period, array $s455, array $events, array $review, string $asOf): array
    {
        $start = (string)($period['period_start'] ?? '');
        $end = (string)($period['period_end'] ?? '');
        $deadline = (new \DateTimeImmutable($end))->modify('+9 months +1 day')->format('Y-m-d');
        $part1ByParty = [];
        $reliefEarly = [];
        $reliefLater = [];
        $openingOutstanding = 0.0;
        $derivedPriorOutstanding = 0.0;
        $openingReductionsAtPeriodEnd = 0.0;
        $eventBeforeEnd = false;
        $returnAlreadyFiled = in_array((string)($period['status'] ?? ''), ['submitted', 'accepted'], true);
        $separateL2pClaims = [];
        $blocking = [];
        $evidenceWarnings = [];
        $unattributedLoanMovementCount = 0;
        foreach ((array)($s455['errors'] ?? []) as $s455Error) {
            $message = (string)$s455Error;
            if (str_starts_with($message, 'Loan transaction #')
                && str_contains($message, 'is not linked to a confirmed ownership party.')) {
                $unattributedLoanMovementCount++;
                continue;
            }
            $reviewResolvesNonCash = str_contains($message, 'non-cash or unsupported loan movement')
                && (float)($s455['gross_principal'] ?? 0) < 0.005
                && !empty($review['current']) && !empty($review['complete'])
                && trim((string)($review['confirmation_note'] ?? '')) !== '';
            if ($reviewResolvesNonCash) {
                $evidenceWarnings[] = $message . ' The approved section 464A conclusion records why these movements do not represent company-to-participator extraction.';
            } else {
                $blocking[] = $message;
            }
        }
        foreach ((array)($review['errors'] ?? []) as $error) {
            $error = (string)$error;
            if ($error !== 'Complete and approve the section 464A review.') {
                $blocking[] = $error;
            }
        }

        foreach ((array)($s455['lots'] ?? $s455['basis']['lots'] ?? []) as $lot) {
            if (!is_array($lot) || (string)($lot['origin_date'] ?? '') < $start || (string)($lot['origin_date'] ?? '') > $end) { continue; }
            $amount = round((float)($lot['remaining_at_period_end'] ?? 0), 2);
            if ($amount < 0.005) { continue; }
            $partyId = (int)($lot['party_id'] ?? 0);
            $this->addPart1($part1ByParty, $partyId, (string)($lot['party_name'] ?? 'Participator'), $amount, (float)($lot['rate'] ?? 0));
        }
        foreach ((array)($s455['all_lots'] ?? $s455['basis']['all_lots'] ?? []) as $lot) {
            if (!is_array($lot) || (string)($lot['origin_date'] ?? '') >= $start) {
                continue;
            }
            $derivedPriorOutstanding += round((float)($lot['remaining_at_period_end'] ?? 0), 2);
        }
        $allRepaymentAllocations = (array)($s455['all_repayment_allocations']
            ?? $s455['basis']['all_repayment_allocations']
            ?? $s455['repayment_allocations']
            ?? $s455['basis']['repayment_allocations']
            ?? []);
        foreach ($allRepaymentAllocations as $allocation) {
            if (!is_array($allocation)) { continue; }
            $date = (string)($allocation['repayment_date'] ?? '');
            if ($date <= $end) { continue; }
            $loanDate = (string)($allocation['loan_date'] ?? '');
            if ($loanDate !== '' && ($loanDate < $start || $loanDate > $end)) { continue; }
            $row = [
                'name' => (string)($allocation['party_name'] ?? 'Participator'),
                'party_id' => (int)($allocation['party_id'] ?? 0),
                'loan_transaction_id' => (int)($allocation['loan_transaction_id'] ?? 0),
                'repayment_transaction_id' => (int)($allocation['repayment_transaction_id'] ?? 0),
                'amount_repaid' => round((float)($allocation['amount'] ?? 0), 2),
                'amount_released_or_written_off' => 0.0,
                'date' => $date,
                'rate' => (float)($allocation['rate'] ?? 0),
                'source' => 'bank_transaction',
                'relief_due_date' => (string)($allocation['relief_due_date'] ?? ''),
            ];
            if ($returnAlreadyFiled) {
                if ($date < $deadline || $this->laterReliefIsDue($row, $asOf)) {
                    $row['relief_tax'] = round((float)$row['amount_repaid'] * (float)$row['rate'], 2);
                    $row['claim_type'] = $date < $deadline ? 'early_post_filing_claim' : 'later_l2p';
                    $separateL2pClaims[] = $row;
                } else {
                    $evidenceWarnings[] = 'A later repayment dated ' . $date
                        . ' is not yet available for a separate L2P relief claim.';
                }
                continue;
            }
            if ($date < $deadline) {
                $reliefEarly[] = $row;
            } elseif ($this->laterReliefIsDue($row, $asOf)) {
                $reliefLater[] = $row;
            } else {
                $evidenceWarnings[] = 'A later repayment dated ' . $date
                    . ' has not reached, or cannot be linked to, its repayment-period Corporation Tax due date.';
            }
        }

        $benefitBalances = [];
        foreach ($events as $event) {
            if (!is_array($event)) { continue; }
            $kind = (string)($event['event_kind'] ?? '');
            $amount = round((float)($event['amount'] ?? 0), 2);
            $date = (string)($event['event_date'] ?? '');
            $originDate = (string)($event['origin_date'] ?? $date);
            $partyId = (int)($event['party_id'] ?? 0);
            $name = (string)($event['party_name'] ?? 'Participator');
            $rate = (float)($event['rate'] ?? $this->rateForDate($originDate) ?? 0);
            if ((string)($event['matching_status'] ?? 'clear') === 'potential_464c') {
                $blocking[] = 'CT600A event #' . (int)($event['id'] ?? 0) . ' has an unresolved potential section 464C match.';
            }
            if ($kind === 'opening_outstanding') { $openingOutstanding += $amount; continue; }
            if ($kind === 's464a_benefit' && $date >= $start && $date <= $end) {
                $benefitBalances[$partyId] = round((float)($benefitBalances[$partyId] ?? 0) + $amount, 2);
                $this->addPart1($part1ByParty, $partyId, $name, $amount, $rate);
                continue;
            }
            if ($kind === 's464a_return_payment') {
                if ($date >= self::RETURN_PAYMENT_RELIEF_CUTOFF) { continue; }
                if ($date <= $end) {
                    $eventBeforeEnd = true;
                    if ($originDate < $start) { $openingReductionsAtPeriodEnd += $amount; }
                    else { $this->reducePart1($part1ByParty, $partyId, $amount, $rate); }
                    continue;
                }
                $row = ['name' => $name, 'party_id' => $partyId, 'amount_repaid' => $amount,
                    'amount_released_or_written_off' => 0.0, 'date' => $date, 'rate' => $rate, 'source' => $kind,
                    'relief_due_date' => (string)($event['relief_due_date'] ?? ''),
                    'repayment_accounting_period_id' => (int)($event['repayment_accounting_period_id'] ?? 0)];
                if ($returnAlreadyFiled) {
                    if ($date < $deadline || $this->laterReliefIsDue($event, $asOf)) {
                        $row['relief_tax'] = round($amount * $rate, 2);
                        $row['claim_type'] = $date < $deadline ? 'early_post_filing_claim' : 'later_l2p';
                        $separateL2pClaims[] = $row;
                    }
                    continue;
                }
                if ($date > $end && $date < $deadline) { $reliefEarly[] = $row; }
                elseif ($date >= $deadline && $this->laterReliefIsDue($event, $asOf)) { $reliefLater[] = $row; }
                continue;
            }
            if (in_array($kind, ['release', 'write_off', 'later_repayment'], true)) {
                if ((string)($event['matching_status'] ?? 'clear') !== 'clear') { continue; }
                if ($date <= $end) {
                    $eventBeforeEnd = true;
                    if ($originDate < $start) { $openingReductionsAtPeriodEnd += $amount; }
                    else { $this->reducePart1($part1ByParty, $partyId, $amount, $rate); }
                    continue;
                }
                $row = ['name' => $name, 'party_id' => $partyId,
                    'amount_repaid' => $kind === 'later_repayment' ? $amount : 0.0,
                    'amount_released_or_written_off' => $kind !== 'later_repayment' ? $amount : 0.0,
                    'date' => $date, 'rate' => $rate, 'source' => $kind,
                    'relief_due_date' => (string)($event['relief_due_date'] ?? ''),
                    'repayment_accounting_period_id' => (int)($event['repayment_accounting_period_id'] ?? 0)];
                if ($returnAlreadyFiled) {
                    if ($date < $deadline || $this->laterReliefIsDue($event, $asOf)) {
                        $row['relief_tax'] = round(
                            ((float)$row['amount_repaid'] + (float)$row['amount_released_or_written_off']) * $rate,
                            2
                        );
                        $row['claim_type'] = $date < $deadline ? 'early_post_filing_claim' : 'later_l2p';
                        $separateL2pClaims[] = $row;
                    }
                    continue;
                }
                if ($date > $end && $date < $deadline) { $reliefEarly[] = $row; }
                elseif ($date >= $deadline && $this->laterReliefIsDue($event, $asOf)) { $reliefLater[] = $row; }
            }
        }

        $part1 = array_values($part1ByParty);
        $a15 = round(array_sum(array_column($part1, 'amount')), 2);
        $a20 = round(array_sum(array_column($part1, 'tax')), 2);
        $a45 = $this->reliefTax($reliefEarly);
        $a70 = $this->reliefTax($reliefLater);
        $a75 = round($derivedPriorOutstanding + $openingOutstanding - $openingReductionsAtPeriodEnd + $a15, 2);
        $a75 = max(0.0, $a75);
        $a80 = round(max(0.0, $a20 - $a45 - $a70), 2);
        $required = $a15 >= 0.005 || $a45 >= 0.005 || $a70 >= 0.005;
        if ($required && (string)($s455['window_status'] ?? $s455['basis']['window_status'] ?? '') === 'provisional_window_open') {
            $blocking[] = 'The nine-month CT600A evidence window remains open until ' . $deadline . '.';
        }
        $reviewComplete = !empty($review['current']) && !empty($review['complete']);
        if ($unattributedLoanMovementCount > 0) {
            $blocking[] = $unattributedLoanMovementCount . ' participator-loan transaction(s) require party attribution.';
        }
        if (!$reviewComplete) {
            $blocking[] = !empty($review['stored']) && empty($review['current'])
                ? 'The section 464A review is stale because its underlying evidence changed.'
                : 'Complete and approve the section 464A review.';
        }
        $model = [
            'model_version' => self::MODEL_VERSION,
            'required' => $required,
            'before_end_period' => $eventBeforeEnd || $this->hasBeforeEndRepayment($s455, $end),
            'part1' => ['rows' => $part1, 'total_loans' => $a15, 'tax_chargeable' => $a20],
            'part2' => $this->reliefSection($reliefEarly, $a45),
            'part3' => $this->reliefSection($reliefLater, $a70),
            'total_loans_outstanding' => $a75,
            'tax_payable' => $a80,
            'relief_due' => $a70 >= 0.005,
            'separate_l2p_claim_events' => $separateL2pClaims,
            'separate_l2p_relief_due' => round(array_sum(array_map(
                static fn(array $claim): float => (string)($claim['claim_type'] ?? '') === 'later_l2p'
                    ? (float)($claim['relief_tax'] ?? 0)
                    : 0.0,
                $separateL2pClaims
            )), 2),
            'blocking_errors' => array_values(array_unique(array_filter(array_map('strval', $blocking)))),
            'evidence_warnings' => array_values(array_unique($evidenceWarnings)),
            'unattributed_loan_movement_count' => $unattributedLoanMovementCount,
            'derived_prior_period_outstanding' => round($derivedPriorOutstanding, 2),
            'review_complete' => $reviewComplete,
        ];
        $json = $this->canonicalJson($model);
        return $model + [
            'basis_hash' => hash('sha256', $json),
            'complete' => $model['blocking_errors'] === []
                && $unattributedLoanMovementCount === 0
                && $reviewComplete,
        ];
    }

    /** @return array<string,mixed> */
    public function saveReview(int $companyId, int $accountingPeriodId, array $answers, string $role, string $approver, string $note): array
    {
        (new YearEndLockService())->assertUnlocked(
            $companyId,
            $accountingPeriodId,
            'change the Section 464A and 464C declaration'
        );

        $role = trim($role); $approver = trim($approver);
        if (!in_array($role, ['director', 'adviser'], true) || $approver === '') {
            return ['success' => false, 'errors' => ['Select the approver role and enter the approver name.']];
        }
        $normalised = [];
        foreach (self::ANSWER_KEYS as $key) {
            $value = (string)($answers[$key] ?? 'yes');
            if (!in_array($value, ['yes', 'no'], true)) { $value = 'yes'; }
            $normalised[$key] = $value;
        }
        $manifest = $this->accountingPeriodEvidenceManifest($companyId, $accountingPeriodId);
        $basis = ['review_version' => self::REVIEW_VERSION, 'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'answers' => $normalised, 'approver_role' => $role, 'approved_by' => $approver,
            'confirmation_note' => trim($note), 'evidence_manifest' => $manifest];
        $basisHash = hash('sha256', $this->canonicalJson($basis));
        $sql = 'INSERT INTO corporation_tax_ct600a_accounting_reviews
              (company_id, accounting_period_id, review_version, answers_json, approver_role,
               approved_by, confirmation_note, evidence_manifest_json, basis_hash, confirmed_at)
             VALUES (:company_id,:period_id,:review_version,:answers_json,:role,:approved_by,
               :note,:manifest,:basis_hash,CURRENT_TIMESTAMP)';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(company_id, accounting_period_id) DO UPDATE SET review_version=excluded.review_version, answers_json=excluded.answers_json,
               approver_role=excluded.approver_role, approved_by=excluded.approved_by, confirmation_note=excluded.confirmation_note,
               evidence_manifest_json=excluded.evidence_manifest_json, basis_hash=excluded.basis_hash, confirmed_at=CURRENT_TIMESTAMP'
            : ' ON DUPLICATE KEY UPDATE review_version=VALUES(review_version), answers_json=VALUES(answers_json),
               approver_role=VALUES(approver_role), approved_by=VALUES(approved_by), confirmation_note=VALUES(confirmation_note),
               evidence_manifest_json=VALUES(evidence_manifest_json), basis_hash=VALUES(basis_hash), confirmed_at=CURRENT_TIMESTAMP';
        \InterfaceDB::prepareExecute(
            $sql,
            ['company_id'=>$companyId,'period_id'=>$accountingPeriodId,
             'review_version'=>self::REVIEW_VERSION,'answers_json'=>$this->canonicalJson($normalised),'role'=>$role,
             'approved_by'=>$approver,'note'=>trim($note) !== '' ? trim($note) : null,
             'manifest'=>$this->canonicalJson($manifest),'basis_hash'=>$basisHash]
        );
        $this->invalidateReadCache();
        $status = $this->reviewStatus($companyId, $accountingPeriodId);
        return ['success' => true, 'errors' => [], 'review' => $status];
    }

    /** @return array<string,mixed> */
    public function saveEvent(array $input): array
    {
        $companyId=(int)($input['company_id']??0); $periodId=(int)($input['accounting_period_id']??0);
        $ctPeriodId=(int)($input['ct_period_id']??0); $partyId=(int)($input['party_id']??0);
        $kind=trim((string)($input['event_kind']??'')); $date=trim((string)($input['event_date']??''));
        $originDate=trim((string)($input['origin_date']??''));
        $amount=round((float)($input['amount']??0),2); $sourceType=trim((string)($input['source_type']??''));
        $sourceId=(int)($input['source_id']??0); $reference=trim((string)($input['evidence_reference']??''));
        $explanation=trim((string)($input['explanation']??'')); $matching=trim((string)($input['matching_status']??'clear'));
        $role=trim((string)($input['approval_role']??'')); $approver=trim((string)($input['approved_by']??''));
        $errors=[];
        if ($this->period($companyId,$periodId,$ctPeriodId)===null) { $errors[]='Select a valid CT period.'; }
        if (!in_array($kind,self::EVENT_KINDS,true)) { $errors[]='Select a valid CT600A event type.'; }
        if (!$this->validDate($date) || $amount<0.005) { $errors[]='Enter a valid event date and positive amount.'; }
        if (in_array($kind,['release','write_off','later_repayment','s464a_return_payment'],true)
            && (!$this->validDate($originDate) || $originDate>$date)) { $errors[]='Enter the original loan or benefit date; it must not be after the event date.'; }
        if ($originDate==='' && in_array($kind,['opening_outstanding','s464a_benefit'],true)) { $originDate=$date; }
        if (!(new OwnershipPartyService())->isEffectiveParty($companyId,$partyId,$date)) { $errors[]='Select a participator or associate effective on the event date.'; }
        if (!in_array($sourceType,['bank_transaction','journal','prior_return','manual_evidence'],true)) { $errors[]='Select the evidence source.'; }
        if (in_array($kind,['release','write_off'],true) && ($sourceType!=='journal' || $sourceId<=0 || !$this->postedJournalExists($companyId,$sourceId))) { $errors[]='A release or write-off must link to a posted company journal.'; }
        if (in_array($sourceType,['bank_transaction','journal'],true) && $sourceId<=0) { $errors[]='Enter the source transaction or journal id.'; }
        if ($reference==='' || $explanation==='') { $errors[]='Enter an evidence reference and explanation.'; }
        if (!in_array($matching,['clear','potential_464c','confirmed_464c'],true)) { $errors[]='Select the section 464C matching status.'; }
        if (!in_array($role,['director','adviser'],true) || $approver==='') { $errors[]='Record who approved the tax classification and their role.'; }
        if ($errors!==[]) { return ['success'=>false,'errors'=>array_values(array_unique($errors))]; }
        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_ct600a_events
              (company_id,accounting_period_id,ct_period_id,originating_ct_period_id,party_id,event_kind,event_date,origin_date,amount,
               source_type,source_id,evidence_reference,explanation,matching_status,approval_role,approved_by)
             VALUES (:company_id,:period_id,:ct_period_id,:origin_id,:party_id,:kind,:event_date,:origin_date,:amount,
                     :source_type,:source_id,:reference,:explanation,:matching,:role,:approved_by)',
            ['company_id'=>$companyId,'period_id'=>$periodId,'ct_period_id'=>$ctPeriodId,
             'origin_id'=>((int)($input['originating_ct_period_id']??0))?:$ctPeriodId,'party_id'=>$partyId,'kind'=>$kind,
             'event_date'=>$date,'origin_date'=>$originDate,'amount'=>$amount,'source_type'=>$sourceType,'source_id'=>$sourceId?:null,
             'reference'=>$reference,'explanation'=>$explanation,'matching'=>$matching,'role'=>$role,'approved_by'=>$approver]
        );
        $this->invalidateReadCache();
        return ['success'=>true,'errors'=>[]];
    }

    /** @return array<string,mixed> */
    public function deleteEvent(int $companyId, int $accountingPeriodId, int $eventId): array
    {
        $row=\InterfaceDB::fetchOne('SELECT id FROM corporation_tax_ct600a_events WHERE id=:id AND company_id=:company_id AND accounting_period_id=:period_id',
            ['id'=>$eventId,'company_id'=>$companyId,'period_id'=>$accountingPeriodId]);
        if (!is_array($row)) { return ['success'=>false,'errors'=>['The CT600A event could not be found.']]; }
        \InterfaceDB::prepareExecute('DELETE FROM corporation_tax_ct600a_events WHERE id=:id',['id'=>$eventId]);
        $this->invalidateReadCache();
        return ['success'=>true,'errors'=>[]];
    }

    private function invalidateReadCache(): void
    {
        \eel_accounts\Support\RequestCache::forgetNamespace('tax.ct600a');
    }

    private function reviewStatus(int $companyId, int $accountingPeriodId): array
    {
        $key = \eel_accounts\Support\RequestCache::key('review-status', $companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            fn(): array => $this->reviewStatusUncached($companyId, $accountingPeriodId)
        );
    }

    private function reviewStatusUncached(int $companyId, int $accountingPeriodId): array
    {
        $row=\InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_ct600a_accounting_reviews
             WHERE company_id=:company_id AND accounting_period_id=:period_id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) { return ['stored'=>false,'current'=>false,'complete'=>false,'answers'=>array_fill_keys(self::ANSWER_KEYS,'unresolved'),'errors'=>['Complete and approve the section 464A review.']]; }
        $answers=json_decode((string)$row['answers_json'],true); $answers=is_array($answers)?$answers:[];
        $storedManifest=json_decode((string)$row['evidence_manifest_json'],true); $storedManifest=is_array($storedManifest)?$storedManifest:[];
        $currentManifest=$this->accountingPeriodEvidenceManifest($companyId, $accountingPeriodId);
        $basis=['review_version'=>self::REVIEW_VERSION,'company_id'=>$companyId,'accounting_period_id'=>$accountingPeriodId,
            'answers'=>$answers,'approver_role'=>(string)$row['approver_role'],
            'approved_by'=>(string)$row['approved_by'],'confirmation_note'=>(string)($row['confirmation_note']??''),
            'evidence_manifest'=>$storedManifest];
        $current=(string)$row['review_version']===self::REVIEW_VERSION
            && hash_equals((string)$row['basis_hash'],hash('sha256',$this->canonicalJson($basis)))
            && hash_equals(hash('sha256',$this->canonicalJson($storedManifest)),hash('sha256',$this->canonicalJson($currentManifest)));
        $errors=[];
        foreach (self::ANSWER_KEYS as $key) {
            $answer=(string)($answers[$key]??'');
            if (!in_array($answer, ['yes', 'no'], true)) {
                $errors[]=$this->reviewQuestions()[$key].' Answer Yes or No.';
            } elseif ($answer === 'yes') {
                $errors[]=$this->reviewQuestions()[$key].' Resolve this through the posted transaction or journal evidence before filing.';
            }
        }
        if (!$current) { $errors[]='The section 464A review is stale because its underlying evidence changed.'; }
        return ['stored'=>true,'current'=>$current,'complete'=>$errors===[],'answers'=>$answers,'errors'=>array_values(array_unique($errors)),
            'approver_role'=>(string)$row['approver_role'],'approved_by'=>(string)$row['approved_by'],
            'confirmed_at'=>(string)$row['confirmed_at'],'confirmation_note'=>(string)($row['confirmation_note']??''),
            'evidence_manifest'=>$currentManifest,'basis_hash'=>(string)$row['basis_hash']];
    }

    private function evidenceManifest(array $s455,array $events): array
    {
        $eventBasis=array_map(static fn(array $event):array=>[
            'id'=>(int)$event['id'],'kind'=>(string)$event['event_kind'],'date'=>(string)$event['event_date'],
            'origin_date'=>(string)($event['origin_date']??''),
            'amount'=>round((float)$event['amount'],2),'party_id'=>(int)$event['party_id'],
            'source_type'=>(string)$event['source_type'],'source_id'=>(int)($event['source_id']??0),
            'matching_status'=>(string)$event['matching_status'],'updated_at'=>(string)($event['updated_at']??''),
        ],$events);
        return ['manifest_version'=>'ct600a-evidence-v1','s455_basis_hash'=>(string)($s455['basis_hash']??''),
            's455_movement_count'=>count((array)($s455['movements']??$s455['basis']['movements']??[])),
            's455_errors'=>array_values((array)($s455['errors']??[])),
            'events_sha256'=>hash('sha256',$this->canonicalJson($eventBasis)),'event_count'=>count($eventBasis)];
    }

    private function accountingPeriodEvidenceManifest(int $companyId, int $accountingPeriodId): array
    {
        $key = \eel_accounts\Support\RequestCache::key('evidence-manifest', $companyId, $accountingPeriodId);
        return (array)\eel_accounts\Support\RequestCache::remember(
            'tax.ct600a',
            $key,
            fn(): array => $this->accountingPeriodEvidenceManifestUncached($companyId, $accountingPeriodId)
        );
    }

    private function accountingPeriodEvidenceManifestUncached(int $companyId, int $accountingPeriodId): array
    {
        $periods = \InterfaceDB::fetchAll(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id=:company_id AND accounting_period_id=:period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        $evidence = [];
        foreach ($periods as $period) {
            $ctPeriodId = (int)($period['id'] ?? 0);
            if ($ctPeriodId <= 0) {
                continue;
            }
            $evidence[] = ['ct_period_id' => $ctPeriodId]
                + $this->evidenceManifest(
                    (new S455ReviewService())->calculate($companyId, $accountingPeriodId, $ctPeriodId),
                    $this->events($companyId, $accountingPeriodId, $ctPeriodId)
                );
        }

        return [
            'manifest_version' => 'ct600a-accounting-period-evidence-v1',
            'ct_periods' => $evidence,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function events(int $companyId,int $accountingPeriodId,int $ctPeriodId): array
    {
        $rows=\InterfaceDB::fetchAll(
            'SELECT e.*, p.legal_name AS party_name, o.period_start AS origin_period_start, o.period_end AS origin_period_end
             FROM corporation_tax_ct600a_events e INNER JOIN company_parties p ON p.id=e.party_id
             LEFT JOIN corporation_tax_periods o ON o.id=e.originating_ct_period_id
             WHERE e.company_id=:company_id AND e.accounting_period_id=:period_id AND e.ct_period_id=:ct_period_id
             ORDER BY e.event_date,e.id',
            ['company_id'=>$companyId,'period_id'=>$accountingPeriodId,'ct_period_id'=>$ctPeriodId]);
        foreach($rows as &$row){$row['rate']=$this->rateForDate((string)($row['origin_date']?:$row['event_date']))??0.0;} unset($row);
        return $rows;
    }

    private function addPart1(array &$rows,int $partyId,string $name,float $amount,float $rate): void
    {
        $key=(string)$partyId; if(!isset($rows[$key])){$rows[$key]=['party_id'=>$partyId,'name'=>$name,'amount'=>0.0,'tax'=>0.0];}
        $rows[$key]['amount']=round((float)$rows[$key]['amount']+$amount,2);
        $rows[$key]['tax']=round((float)$rows[$key]['tax']+round($amount*$rate,2),2);
    }

    private function reducePart1(array &$rows,int $partyId,float $amount,float $rate): void
    {
        $key=(string)$partyId;
        if(!isset($rows[$key])){return;}
        $applied=min((float)$rows[$key]['amount'],$amount);
        $rows[$key]['amount']=round((float)$rows[$key]['amount']-$applied,2);
        $rows[$key]['tax']=round(max(0.0,(float)$rows[$key]['tax']-round($applied*$rate,2)),2);
        if((float)$rows[$key]['amount']<0.005){unset($rows[$key]);}
    }

    private function reliefTax(array $rows): float
    {
        return round(array_sum(array_map(static fn(array $row):float=>round(((float)$row['amount_repaid']+(float)$row['amount_released_or_written_off'])*(float)$row['rate'],2),$rows)),2);
    }

    private function reliefSection(array $rows,float $relief): array
    {
        return ['rows'=>array_values($rows),'total_repaid'=>round(array_sum(array_column($rows,'amount_repaid')),2),
            'total_released_or_written_off'=>round(array_sum(array_column($rows,'amount_released_or_written_off')),2),
            'total'=>round(array_sum(array_map(static fn(array $r):float=>(float)$r['amount_repaid']+(float)$r['amount_released_or_written_off'],$rows)),2),
            'relief_due'=>$relief];
    }

    private function hasBeforeEndRepayment(array $s455,string $end): bool
    {
        foreach((array)($s455['repayment_allocations']??$s455['basis']['repayment_allocations']??[]) as $row){if((string)($row['repayment_date']??'')<=$end){return true;}}
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function withReliefDueDates(int $companyId, array $rows, string $dateKey = 'repayment_date'): array
    {
        $accountingPeriods = \InterfaceDB::fetchAll(
            'SELECT id, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start, id',
            ['company_id' => $companyId]
        );
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $date = (string)($row[$dateKey] ?? '');
            foreach ($accountingPeriods as $accountingPeriod) {
                if ($date < (string)$accountingPeriod['period_start'] || $date > (string)$accountingPeriod['period_end']) {
                    continue;
                }
                $row['repayment_accounting_period_id'] = (int)$accountingPeriod['id'];
                $row['repayment_accounting_period_end'] = (string)$accountingPeriod['period_end'];
                $row['relief_due_date'] = (new \DateTimeImmutable((string)$accountingPeriod['period_end']))
                    ->modify('+9 months +1 day')
                    ->format('Y-m-d');
                break;
            }
        }
        unset($row);

        return array_values(array_filter($rows, 'is_array'));
    }

    private function laterReliefIsDue(array $evidence, string $asOf): bool
    {
        $dueDate = (string)($evidence['relief_due_date'] ?? '');
        if (!$this->validDate($dueDate)) {
            $repaymentPeriodEnd = (string)($evidence['repayment_accounting_period_end'] ?? '');
            if (!$this->validDate($repaymentPeriodEnd)) {
                return false;
            }
            $dueDate = (new \DateTimeImmutable($repaymentPeriodEnd))
                ->modify('+9 months +1 day')
                ->format('Y-m-d');
        }

        return $this->validDate($asOf) && $asOf >= $dueDate;
    }

    private function period(int $companyId,int $accountingPeriodId,int $ctPeriodId): ?array
    {
        if ($ctPeriodId < 0) {
            $reference = CorporationTaxPeriodService::decodeTransientReferenceId($ctPeriodId);
            if ($reference === null || (int)$reference['accounting_period_id'] !== $accountingPeriodId) {
                return null;
            }
            $projection = (new CorporationTaxPeriodService())->projectForAccountingPeriod(
                $companyId,
                $accountingPeriodId
            );
            foreach ((array)($projection['periods'] ?? []) as $period) {
                if ((int)($period['id'] ?? 0) === $ctPeriodId) {
                    return $period;
                }
            }
            return null;
        }

        $row=\InterfaceDB::fetchOne('SELECT id,sequence_no,period_start,period_end,status FROM corporation_tax_periods WHERE id=:id AND company_id=:company_id AND accounting_period_id=:period_id',
            ['id'=>$ctPeriodId,'company_id'=>$companyId,'period_id'=>$accountingPeriodId]); return is_array($row)?$row:null;
    }

    private function postedJournalExists(int $companyId,int $journalId): bool
    { return (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals WHERE id=:id AND company_id=:company_id AND is_posted=1',['id'=>$journalId,'company_id'=>$companyId])===1; }
    private function rateForDate(string $date): ?float
    { $v=\InterfaceDB::fetchColumn('SELECT rate FROM s455_rate_rules WHERE is_active=1 AND effective_from<=:d AND (effective_to IS NULL OR effective_to>=:d) ORDER BY effective_from DESC,id DESC LIMIT 1',['d'=>$date]); return $v===false||$v===null?null:(float)$v; }
    private function validDate(string $date): bool
    { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date); return $d!==false&&$d->format('Y-m-d')===$date; }
    private function schemaReady(): bool
    { return \InterfaceDB::tableExists('corporation_tax_ct600a_events')&&\InterfaceDB::tableExists('corporation_tax_ct600a_accounting_reviews'); }
    private function filingBasisCanonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $normalise($child); }
            return $item;
        };
        return (string)json_encode(
            $normalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
    private function canonicalJson(array $value): string
    {
        $sort=function(mixed $item)use(&$sort):mixed{if(!is_array($item)){return $item;}if(!array_is_list($item)){ksort($item,SORT_STRING);}foreach($item as $k=>$v){$item[$k]=$sort($v);}return $item;};
        return (string)json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
}
