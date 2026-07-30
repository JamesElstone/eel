<?php
declare(strict_types=1);
namespace eel_accounts\Service;

final class Ct600GenerationService
{
    public function generate(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $approval = (new IxbrlAccountsFilingApprovalService())->status($companyId, $accountingPeriodId);
        $record = (array)($approval['approval'] ?? []);
        if (($approval['state'] ?? '') !== 'current' || empty($record['declaration_confirmed'])) {
            return ['success'=>false,'errors'=>['A current approved filing basis with Corporation Tax authorisation is required.']];
        }
        $package = (new HmrcSubmissionPackageService())->prepareForSubmission($companyId, $ctPeriodId, 'LIVE', [
            'declarant_name'=>(string)$record['declarant_name'], 'declarant_status'=>(string)$record['declarant_status'],
            'declaration_confirmed'=>true,'authority_confirmed'=>true,'original_unfiled_confirmed'=>true,
        ]);
        if (empty($package['ok'])) return ['success'=>false,'errors'=>(array)($package['errors']??[])];
        $model=(array)($package['source_manifest']??[]);
        $number=preg_replace('/[^A-Za-z0-9]/','',(string)($model['company_number'] ?? '')) ?: (string)$companyId;
        $period=(array)($model['period']??[]);
        $hash=(string)$package['body_sha256']; $uploads=(array)\eel_accounts\Store\AccountingConfigurationStore::uploads();
        $base=rtrim((string)($uploads['upload_base_dir']??''),'\\/');
        if ($base==='') return ['success'=>false,'errors'=>['Configure uploads.upload_base_dir before generating CT600 XML.']];
        $dir=$base.DIRECTORY_SEPARATOR.$number.DIRECTORY_SEPARATOR.'xml';
        if (!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) return ['success'=>false,'errors'=>['The CT600 XML directory could not be created.']];
        $name='ct600_'.$number.'_'.$accountingPeriodId.'_'.(int)$record['id'].'_'.(string)($period['start_date']??'').'_' .(string)($period['end_date']??'').'_'.$hash.'.xml';
        $path=$dir.DIRECTORY_SEPARATOR.$name; $xml=(string)$package['filing_body_xml'];
        if (!is_file($path) && file_put_contents($path,$xml,LOCK_EX)!==strlen($xml)) return ['success'=>false,'errors'=>['The CT600 XML could not be stored.']];
        \InterfaceDB::prepareExecute('INSERT IGNORE INTO ct600_generated_artifacts (company_id,accounting_period_id,ct_period_id,filing_approval_id,filing_approval_hash,source_manifest_sha256,output_path,output_filename,output_sha256) VALUES (:c,:a,:ct,:approval,:approval_hash,:manifest,:path,:name,:hash)', ['c'=>$companyId,'a'=>$accountingPeriodId,'ct'=>$ctPeriodId,'approval'=>(int)$record['id'],'approval_hash'=>(string)$record['basis_hash'],'manifest'=>(string)$package['source_manifest_sha256'],'path'=>$path,'name'=>$name,'hash'=>$hash]);
        return ['success'=>true,'path'=>$path,'filename'=>$name,'sha256'=>$hash,'errors'=>[]];
    }
}
