<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/** Live party terms, with immutable accounting-period snapshots. */
final class ParticipatorLoanPartyTermsService
{
    private const TERMS = 'participator_loan_party_terms';
    private const AUDIT = 'participator_loan_party_terms_audit';
    private const SNAPSHOTS = 'participator_loan_party_term_snapshots';

    public function fetchTerms(int $companyId, int $partyId, ?int $accountingPeriodId = null): array
    {
        $party = $this->party($companyId, $partyId);
        if ($party === null) return $this->error('The selected participator loan party does not belong to this company.');
        $locked = $accountingPeriodId !== null && $accountingPeriodId > 0
            && (new YearEndLockService())->isLocked($companyId, $accountingPeriodId);
        $terms = $locked ? $this->snapshot($companyId, $accountingPeriodId, $partyId) : null;
        $terms ??= $this->live($companyId, $partyId);
        return ['success' => true, 'party' => $party, 'terms' => $terms ?? $this->defaults(), 'explicit' => $terms !== null,
            'is_locked' => $locked, 'schema_ready' => $this->ready(), 'accounting_period_id' => $accountingPeriodId];
    }

    /** Terms card source: each party with an assigned loan line up to today. */
    public function fetchTermsWorkspace(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->ready()) return $this->error('Run the participator loan party terms migration.');
        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        if ((int)$controls['asset'] <= 0 || (int)$controls['liability'] <= 0) return ['success' => true, 'parties' => [], 'schema_ready' => true, 'is_locked' => false];
        $rows = \InterfaceDB::fetchAll(
            'SELECT DISTINCT p.id, p.legal_name, p.party_type, p.linked_director_id
             FROM journal_lines jl INNER JOIN journals j ON j.id = jl.journal_id
             INNER JOIN company_parties p ON p.id = jl.party_id AND p.company_id = j.company_id
             WHERE j.company_id = :company_id AND j.is_posted = 1 AND j.journal_date <= :today
               AND jl.nominal_account_id IN (:asset_nominal_id, :liability_nominal_id)
               AND jl.party_id IS NOT NULL ORDER BY p.legal_name, p.id',
            ['company_id' => $companyId, 'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'asset_nominal_id'=>(int)$controls['asset'], 'liability_nominal_id'=>(int)$controls['liability']]
        );
        $parties = [];
        foreach ($rows as $party) {
            $item = $this->fetchTerms($companyId, (int)$party['id'], $accountingPeriodId);
            if (!empty($item['success'])) $parties[] = $item;
        }
        return ['success' => true, 'parties' => $parties, 'schema_ready' => true,
            'is_locked' => (new YearEndLockService())->isLocked($companyId, $accountingPeriodId)];
    }

    public function save(int $companyId, int $partyId, array $input, string $changedBy = 'web_app'): array
    {
        if (!$this->ready()) return $this->error('Run the participator loan party terms migration.');
        if ($this->party($companyId, $partyId) === null) return $this->error('The selected participator loan party does not belong to this company.');
        $terms = $this->normalise($input);
        if ($terms === null) return $this->error('Enter valid participator loan terms.');
        $old = $this->live($companyId, $partyId);
        $oldRevision = (int)($old['revision'] ?? 0);
        $json = json_encode($terms, JSON_THROW_ON_ERROR);
        if ($old !== null && json_encode($this->withoutMeta($old), JSON_THROW_ON_ERROR) === $json) return ['success' => true, 'changed' => false, 'terms' => $old];
        \InterfaceDB::transaction(function () use ($companyId, $partyId, $terms, $old, $oldRevision, $changedBy, $json): void {
            $params = $terms + ['company_id' => $companyId, 'party_id' => $partyId, 'actor' => substr($changedBy, 0, 100), 'revision' => $oldRevision + 1];
            if ($old !== null) {
                \InterfaceDB::prepareExecute('UPDATE ' . self::TERMS . ' SET interest_rate_percent=:interest_rate_percent, security_type=:security_type, repayable_on_demand=:repayable_on_demand, repayment_timing=:repayment_timing, deferment_right_confirmed=:deferment_right_confirmed, set_off_right_confirmed=:set_off_right_confirmed, settlement_intention=:settlement_intention, revision=:revision, updated_by=:actor, updated_at=CURRENT_TIMESTAMP WHERE company_id=:company_id AND party_id=:party_id', $params);
            } else {
                \InterfaceDB::prepareExecute('INSERT INTO ' . self::TERMS . ' (company_id,party_id,interest_rate_percent,security_type,repayable_on_demand,repayment_timing,deferment_right_confirmed,set_off_right_confirmed,settlement_intention,revision,created_by,updated_by) VALUES (:company_id,:party_id,:interest_rate_percent,:security_type,:repayable_on_demand,:repayment_timing,:deferment_right_confirmed,:set_off_right_confirmed,:settlement_intention,:revision,:actor,:actor)', $params);
            }
            \InterfaceDB::prepareExecute('INSERT INTO ' . self::AUDIT . ' (company_id,party_id,old_terms_json,new_terms_json,old_revision,new_revision,changed_by) VALUES (:company_id,:party_id,:old,:new,:old_revision,:revision,:actor)', ['company_id'=>$companyId,'party_id'=>$partyId,'old'=>$old === null ? null : json_encode($this->withoutMeta($old), JSON_THROW_ON_ERROR),'new'=>$json,'old_revision'=>$oldRevision,'revision'=>$oldRevision+1,'actor'=>substr($changedBy,0,100)]);
        });
        \eel_accounts\Support\RequestCache::clear();
        return ['success' => true, 'changed' => true, 'terms' => $this->live($companyId, $partyId)];
    }

    public function snapshotPeriod(int $companyId, int $accountingPeriodId, string $actor = 'web_app'): array
    {
        if (!$this->ready()) return $this->error('Run the participator loan party terms migration before locking periods.');
        $statement = (new DirectorLoanService())->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) return $this->error((string)(($statement['errors'] ?? [])[0] ?? 'Participator loan statement unavailable.'));
        $nominalId = (int)($statement['liability_nominal']['id'] ?? 0) ?: null;
        foreach ((array)$statement['per_director'] as $position) {
            $partyId = (int)($position['director_id'] ?? 0);
            if ($partyId <= 0 || (abs((float)($position['gross_asset'] ?? 0)) < .005 && abs((float)($position['gross_liability'] ?? 0)) < .005 && empty($position['has_movement']))) continue;
            $terms = $this->live($companyId, $partyId) ?? $this->defaults();
            \InterfaceDB::prepareExecute('INSERT INTO ' . self::SNAPSHOTS . ' (company_id,accounting_period_id,party_id,liability_nominal_account_id,terms_json,created_by) SELECT :company_id,:period,:party_id,:nominal,:terms,:actor WHERE NOT EXISTS (SELECT 1 FROM ' . self::SNAPSHOTS . ' existing WHERE existing.company_id=:company_id AND existing.accounting_period_id=:period AND existing.party_id=:party_id)', ['company_id'=>$companyId,'period'=>$accountingPeriodId,'party_id'=>$partyId,'nominal'=>$nominalId,'terms'=>json_encode($this->withoutMeta($terms), JSON_THROW_ON_ERROR),'actor'=>substr($actor,0,100)]);
        }
        return ['success' => true];
    }

    public function resolved(int $companyId, int $accountingPeriodId, int $partyId): array { return (array)($this->fetchTerms($companyId, $partyId, $accountingPeriodId)['terms'] ?? $this->defaults()); }
    private function live(int $companyId, int $partyId): ?array { $row=\InterfaceDB::fetchOne('SELECT * FROM '.self::TERMS.' WHERE company_id=:company_id AND party_id=:party_id LIMIT 1',['company_id'=>$companyId,'party_id'=>$partyId]); return is_array($row)?$this->normalise($row)+['revision'=>(int)($row['revision']??0)]:null; }
    private function snapshot(int $companyId, int $periodId, int $partyId): ?array { $row=\InterfaceDB::fetchOne('SELECT terms_json FROM '.self::SNAPSHOTS.' WHERE company_id=:company_id AND accounting_period_id=:period_id AND party_id=:party_id LIMIT 1',['company_id'=>$companyId,'period_id'=>$periodId,'party_id'=>$partyId]); if (!is_array($row)) return null; $terms=json_decode((string)$row['terms_json'],true); return is_array($terms)?$this->normalise($terms):null; }
    private function party(int $companyId,int $partyId): ?array { $row=\InterfaceDB::fetchOne('SELECT id,legal_name,party_type,linked_director_id FROM company_parties WHERE id=:party_id AND company_id=:company_id LIMIT 1',['party_id'=>$partyId,'company_id'=>$companyId]); return is_array($row)?$row:null; }
    private function defaults(): array { return ['interest_rate_percent'=>0.0,'security_type'=>'unsecured','repayable_on_demand'=>true,'repayment_timing'=>'within_12_months','deferment_right_confirmed'=>false,'set_off_right_confirmed'=>false,'settlement_intention'=>'independently']; }
    private function normalise(array $v): ?array { $rate=(float)($v['interest_rate_percent']??0); $security=(string)($v['security_type']??'unsecured'); $timing=(string)($v['repayment_timing']??'within_12_months'); $settlement=(string)($v['settlement_intention']??'independently'); if ($rate<0||$rate>100||!in_array($security,['secured','unsecured'],true)||!in_array($timing,['within_12_months','after_12_months'],true)||!in_array($settlement,['net','simultaneous','independently'],true)) return null; return ['interest_rate_percent'=>round($rate,4),'security_type'=>$security,'repayable_on_demand'=>!empty($v['repayable_on_demand']) ? 1 : 0,'repayment_timing'=>$timing,'deferment_right_confirmed'=>!empty($v['deferment_right_confirmed']) ? 1 : 0,'set_off_right_confirmed'=>!empty($v['set_off_right_confirmed']) ? 1 : 0,'settlement_intention'=>$settlement]; }
    private function withoutMeta(array $v): array { $out=$this->normalise($v); return $out ?? $this->defaults(); }
    private function ready(): bool { return \InterfaceDB::tableExists(self::TERMS)&&\InterfaceDB::tableExists(self::AUDIT)&&\InterfaceDB::tableExists(self::SNAPSHOTS); }
    private function error(string $message): array { return ['success'=>false,'errors'=>[$message]]; }
}
