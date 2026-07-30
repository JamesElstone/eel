<?php
declare(strict_types=1);
namespace eel_accounts\Service;

final class Ct600ReturnAuthorisationService
{
    public const STATUSES = ['Director','Company Secretary','Authorised Agent','Authorised Employee','Tax Agent or Accountant','Liquidator'];
    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        return (array)(\InterfaceDB::fetchOne('SELECT * FROM ct600_return_authorisations WHERE company_id=:company_id AND accounting_period_id=:period_id LIMIT 1', ['company_id'=>$companyId,'period_id'=>$accountingPeriodId]) ?: []);
    }
    public function save(int $companyId, int $accountingPeriodId, array $input, string $actor): array
    {
        $status=trim((string)($input['declarant_status']??''));
        $confirmed=static fn(string $key): bool => !empty($input[$key]) && in_array((string)$input[$key],['1','on','true'],true);
        $errors=[];
        if (!in_array($status,self::STATUSES,true)) $errors[]='Select a valid declarant status or capacity.';
        foreach (['original_unfiled_confirmed','authority_confirmed','declaration_confirmed'] as $key) if (!$confirmed($key)) $errors[]='Confirm every Corporation Tax return authorisation statement.';
        if ($errors!==[]) return ['success'=>false,'errors'=>$errors];
        \InterfaceDB::prepareExecute('INSERT INTO ct600_return_authorisations (company_id,accounting_period_id,declarant_status,original_unfiled_confirmed,authority_confirmed,declaration_confirmed,saved_by) VALUES (:company_id,:period_id,:status,:original,:authority,:declaration,:actor) ON DUPLICATE KEY UPDATE declarant_status=VALUES(declarant_status),original_unfiled_confirmed=VALUES(original_unfiled_confirmed),authority_confirmed=VALUES(authority_confirmed),declaration_confirmed=VALUES(declaration_confirmed),saved_by=VALUES(saved_by),saved_at=CURRENT_TIMESTAMP', ['company_id'=>$companyId,'period_id'=>$accountingPeriodId,'status'=>$status,'original'=>1,'authority'=>1,'declaration'=>1,'actor'=>$actor]);
        return ['success'=>true,'errors'=>[]];
    }
    public function current(int $companyId,int $accountingPeriodId): array { $row=$this->fetch($companyId,$accountingPeriodId); return $row!==[] && !empty($row['original_unfiled_confirmed']) && !empty($row['authority_confirmed']) && !empty($row['declaration_confirmed']) ? $row : []; }
}
