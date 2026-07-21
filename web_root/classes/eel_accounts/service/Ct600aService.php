<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class Ct600aService
{
    public const REVIEW_VERSION = 'ct600a-review-v1';
    public const MODEL_VERSION = 'ct600a-model-v1';
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
        if (!$this->schemaReady()) {
            return ['available' => false, 'errors' => ['Run the CT600A and filing-scope migration.'], 'periods' => []];
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end, status FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        return [
            'available' => true,
            'errors' => [],
            'questions' => $this->reviewQuestions(),
            'parties' => (new OwnershipPartyService())->fetchSummary($companyId)['parties'] ?? [],
            'periods' => array_map(fn(array $period): array => $this->build(
                $companyId,
                $accountingPeriodId,
                (int)$period['id']
            ), $periods),
        ];
    }

    /** @return array<string,mixed> */
    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if (!$this->schemaReady()) {
            return ['available' => false, 'errors' => ['Run the CT600A and filing-scope migration.']];
        }
        $period = $this->period($companyId, $accountingPeriodId, $ctPeriodId);
        if ($period === null) {
            return ['available' => false, 'errors' => ['The CT period could not be found.']];
        }
        $s455 = (new S455ReviewService())->calculate($companyId, $accountingPeriodId, $ctPeriodId);
        $events = $this->events($companyId, $accountingPeriodId, $ctPeriodId);
        $review = $this->reviewStatus($companyId, $accountingPeriodId, $ctPeriodId, $s455, $events);
        $model = $this->buildFromEvidence($period, $s455, $events, $review, (new \DateTimeImmutable('now'))->format('Y-m-d'));
        return $model + [
            'available' => true,
            'sequence_no' => (int)$period['sequence_no'],
            'ct_period_id' => $ctPeriodId,
            'period_start' => (string)$period['period_start'],
            'period_end' => (string)$period['period_end'],
            'review' => $review,
            'events' => $events,
            's455' => $s455,
        ];
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
        if ($unattributedLoanMovementCount > 0) {
            $blocking[] = $this->unattributedLoanMovementMessage();
        }
        foreach ((array)($review['errors'] ?? []) as $error) { $blocking[] = (string)$error; }

        foreach ((array)($s455['lots'] ?? $s455['basis']['lots'] ?? []) as $lot) {
            if (!is_array($lot) || (string)($lot['origin_date'] ?? '') < $start || (string)($lot['origin_date'] ?? '') > $end) { continue; }
            $amount = round((float)($lot['remaining_at_period_end'] ?? 0), 2);
            if ($amount < 0.005) { continue; }
            $partyId = (int)($lot['party_id'] ?? 0);
            $this->addPart1($part1ByParty, $partyId, (string)($lot['party_name'] ?? 'Participator'), $amount, (float)($lot['rate'] ?? 0));
        }
        foreach ((array)($s455['repayment_allocations'] ?? $s455['basis']['repayment_allocations'] ?? []) as $allocation) {
            if (!is_array($allocation)) { continue; }
            $date = (string)($allocation['repayment_date'] ?? '');
            if ($date <= $end) { continue; }
            $row = [
                'name' => (string)($allocation['party_name'] ?? 'Participator'),
                'party_id' => (int)($allocation['party_id'] ?? 0),
                'amount_repaid' => round((float)($allocation['amount'] ?? 0), 2),
                'amount_released_or_written_off' => 0.0,
                'date' => $date,
                'rate' => (float)($allocation['rate'] ?? 0),
                'source' => 'bank_transaction',
            ];
            if ($returnAlreadyFiled) {
                $separateL2pClaims[] = $row;
                continue;
            }
            if ($date < $deadline) { $reliefEarly[] = $row; }
            elseif ($asOf >= (new \DateTimeImmutable($end))->modify('+21 months')->format('Y-m-d')) { $reliefLater[] = $row; }
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
                    'amount_released_or_written_off' => 0.0, 'date' => $date, 'rate' => $rate, 'source' => $kind];
                if ($returnAlreadyFiled) {
                    $separateL2pClaims[] = $event;
                    continue;
                }
                if ($date > $end && $date < $deadline) { $reliefEarly[] = $row; }
                elseif ($date >= $deadline && $asOf >= (new \DateTimeImmutable($end))->modify('+21 months')->format('Y-m-d')) { $reliefLater[] = $row; }
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
                    'date' => $date, 'rate' => $rate, 'source' => $kind];
                if ($returnAlreadyFiled) {
                    $separateL2pClaims[] = $event;
                    continue;
                }
                if ($date > $end && $date < $deadline) { $reliefEarly[] = $row; }
                elseif ($date >= $deadline && $asOf >= (new \DateTimeImmutable($end))->modify('+21 months')->format('Y-m-d')) { $reliefLater[] = $row; }
            }
        }

        $part1 = array_values($part1ByParty);
        $a15 = round(array_sum(array_column($part1, 'amount')), 2);
        $a20 = round(array_sum(array_column($part1, 'tax')), 2);
        $a45 = $this->reliefTax($reliefEarly);
        $a70 = $this->reliefTax($reliefLater);
        $a75 = round($openingOutstanding - $openingReductionsAtPeriodEnd + $a15, 2);
        $a75 = max(0.0, $a75);
        $a80 = round(max(0.0, $a20 - $a45 - $a70), 2);
        $required = $a15 >= 0.005 || $a45 >= 0.005 || $a70 >= 0.005;
        if ($required && (string)($s455['window_status'] ?? $s455['basis']['window_status'] ?? '') === 'provisional_window_open') {
            $blocking[] = 'The nine-month CT600A evidence window remains open until ' . $deadline . '.';
        }
        if (empty($review['current']) || empty($review['complete'])) {
            $blocking[] = 'A current, complete section 464A review is required.';
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
            'blocking_errors' => array_values(array_unique(array_filter(array_map('strval', $blocking)))),
            'evidence_warnings' => array_values(array_unique($evidenceWarnings)),
        ];
        $json = $this->canonicalJson($model);
        return $model + ['basis_hash' => hash('sha256', $json), 'complete' => $model['blocking_errors'] === []];
    }

    private function unattributedLoanMovementMessage(): string
    {
        return 'If this CT period has a section 455 charge, '
            . 'correctly coding repayments in a following accounting period may reduce the tax due by establishing repayment relief.';
    }

    /** @return array<string,mixed> */
    public function saveReview(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $answers, string $role, string $approver, string $note): array
    {
        $role = trim($role); $approver = trim($approver);
        if (!in_array($role, ['director', 'adviser'], true) || $approver === '') {
            return ['success' => false, 'errors' => ['Select the approver role and enter the approver name.']];
        }
        $normalised = [];
        foreach (self::ANSWER_KEYS as $key) {
            $value = (string)($answers[$key] ?? 'unresolved');
            if (!in_array($value, ['yes', 'no', 'unresolved'], true)) { $value = 'unresolved'; }
            $normalised[$key] = $value;
        }
        $s455 = (new S455ReviewService())->calculate($companyId, $accountingPeriodId, $ctPeriodId);
        $events = $this->events($companyId, $accountingPeriodId, $ctPeriodId);
        $manifest = $this->evidenceManifest($s455, $events);
        $basis = ['review_version' => self::REVIEW_VERSION, 'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId, 'ct_period_id' => $ctPeriodId,
            'answers' => $normalised, 'approver_role' => $role, 'approved_by' => $approver,
            'confirmation_note' => trim($note), 'evidence_manifest' => $manifest];
        $basisHash = hash('sha256', $this->canonicalJson($basis));
        $sql = 'INSERT INTO corporation_tax_ct600a_reviews
              (company_id, accounting_period_id, ct_period_id, review_version, answers_json, approver_role,
               approved_by, confirmation_note, evidence_manifest_json, basis_hash, confirmed_at)
             VALUES (:company_id,:period_id,:ct_period_id,:review_version,:answers_json,:role,:approved_by,
                     :note,:manifest,:basis_hash,CURRENT_TIMESTAMP)';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(ct_period_id) DO UPDATE SET review_version=excluded.review_version, answers_json=excluded.answers_json,
               approver_role=excluded.approver_role, approved_by=excluded.approved_by, confirmation_note=excluded.confirmation_note,
               evidence_manifest_json=excluded.evidence_manifest_json, basis_hash=excluded.basis_hash, confirmed_at=CURRENT_TIMESTAMP'
            : ' ON DUPLICATE KEY UPDATE review_version=VALUES(review_version), answers_json=VALUES(answers_json),
               approver_role=VALUES(approver_role), approved_by=VALUES(approved_by), confirmation_note=VALUES(confirmation_note),
               evidence_manifest_json=VALUES(evidence_manifest_json), basis_hash=VALUES(basis_hash), confirmed_at=CURRENT_TIMESTAMP';
        \InterfaceDB::prepareExecute(
            $sql,
            ['company_id'=>$companyId,'period_id'=>$accountingPeriodId,'ct_period_id'=>$ctPeriodId,
             'review_version'=>self::REVIEW_VERSION,'answers_json'=>$this->canonicalJson($normalised),'role'=>$role,
             'approved_by'=>$approver,'note'=>trim($note) !== '' ? trim($note) : null,
             'manifest'=>$this->canonicalJson($manifest),'basis_hash'=>$basisHash]
        );
        $status = $this->reviewStatus($companyId, $accountingPeriodId, $ctPeriodId, $s455, $events);
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
        return ['success'=>true,'errors'=>[]];
    }

    /** @return array<string,mixed> */
    public function deleteEvent(int $companyId, int $accountingPeriodId, int $eventId): array
    {
        $row=\InterfaceDB::fetchOne('SELECT id FROM corporation_tax_ct600a_events WHERE id=:id AND company_id=:company_id AND accounting_period_id=:period_id',
            ['id'=>$eventId,'company_id'=>$companyId,'period_id'=>$accountingPeriodId]);
        if (!is_array($row)) { return ['success'=>false,'errors'=>['The CT600A event could not be found.']]; }
        \InterfaceDB::prepareExecute('DELETE FROM corporation_tax_ct600a_events WHERE id=:id',['id'=>$eventId]);
        return ['success'=>true,'errors'=>[]];
    }

    private function reviewStatus(int $companyId,int $accountingPeriodId,int $ctPeriodId,array $s455,array $events): array
    {
        $row=\InterfaceDB::fetchOne('SELECT * FROM corporation_tax_ct600a_reviews WHERE ct_period_id=:id',['id'=>$ctPeriodId]);
        if (!is_array($row)) { return ['stored'=>false,'current'=>false,'complete'=>false,'answers'=>array_fill_keys(self::ANSWER_KEYS,'unresolved'),'errors'=>['Complete and approve the section 464A review.']]; }
        $answers=json_decode((string)$row['answers_json'],true); $answers=is_array($answers)?$answers:[];
        $storedManifest=json_decode((string)$row['evidence_manifest_json'],true); $storedManifest=is_array($storedManifest)?$storedManifest:[];
        $currentManifest=$this->evidenceManifest($s455,$events);
        $basis=['review_version'=>self::REVIEW_VERSION,'company_id'=>$companyId,'accounting_period_id'=>$accountingPeriodId,
            'ct_period_id'=>$ctPeriodId,'answers'=>$answers,'approver_role'=>(string)$row['approver_role'],
            'approved_by'=>(string)$row['approved_by'],'confirmation_note'=>(string)($row['confirmation_note']??''),
            'evidence_manifest'=>$storedManifest];
        $current=(string)$row['review_version']===self::REVIEW_VERSION
            && hash_equals((string)$row['basis_hash'],hash('sha256',$this->canonicalJson($basis)))
            && hash_equals(hash('sha256',$this->canonicalJson($storedManifest)),hash('sha256',$this->canonicalJson($currentManifest)));
        $errors=[]; $hasBenefit=array_filter($events,static fn(array $event):bool=>(string)$event['event_kind']==='s464a_benefit')!==[];
        foreach (self::ANSWER_KEYS as $key) {
            $answer=(string)($answers[$key]??'unresolved');
            if ($answer==='unresolved') { $errors[]=$this->reviewQuestions()[$key].' This remains unresolved.'; }
            elseif ($answer==='yes' && !$hasBenefit) { $errors[]='The positive '.$key.' answer requires a supported section 464A benefit record or must be resolved.'; }
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

    private function period(int $companyId,int $accountingPeriodId,int $ctPeriodId): ?array
    {
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
    { return \InterfaceDB::tableExists('corporation_tax_ct600a_events')&&\InterfaceDB::tableExists('corporation_tax_ct600a_reviews'); }
    private function canonicalJson(array $value): string
    {
        $sort=function(mixed $item)use(&$sort):mixed{if(!is_array($item)){return $item;}if(!array_is_list($item)){ksort($item,SORT_STRING);}foreach($item as $k=>$v){$item[$k]=$sort($v);}return $item;};
        return (string)json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
}
