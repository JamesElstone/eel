<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class Ct600aAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId=(int)$request->input('company_id',0);
        $periodId=(int)$request->input('accounting_period_id',0);
        $context=new \eel_accounts\Service\AccountingContextService();
        if($companyId!==$context->authCompanyId()||$periodId!==$context->authAccountingPeriodId()){
            return $this->result(false,['The submitted company or period does not match the authenticated accounting context.']);
        }
        $intent=trim((string)$request->input('intent',''));
        $service=new \eel_accounts\Service\Ct600aService();
        try{
            if($intent==='save_ct600a_review'){
                $answers=[];
                foreach(array_keys($service->reviewQuestions()) as $key){$answers[$key]=$request->input($key,'unresolved');}
                $result=$service->saveReview($companyId,$periodId,(int)$request->input('ct_period_id',0),$answers,
                    (string)$request->input('approver_role',''),(string)$request->input('approved_by',''),(string)$request->input('confirmation_note',''));
                return $this->result(!empty($result['success']),(array)($result['errors']??[]),!empty($result['success'])?'Section 464A review saved. The filing basis must be approved again.':'');
            }
            if($intent==='save_ct600a_event'){
                $input=[];
                foreach(['company_id','accounting_period_id','ct_period_id','originating_ct_period_id','party_id','event_kind','event_date','origin_date','amount','source_type','source_id','evidence_reference','explanation','matching_status','approval_role','approved_by'] as $key){$input[$key]=$request->input($key,null);}
                $result=$service->saveEvent($input);
                return $this->result(!empty($result['success']),(array)($result['errors']??[]),!empty($result['success'])?'CT600A evidence event added. Reconfirm the section 464A review.':'');
            }
            if($intent==='delete_ct600a_event'){
                $result=$service->deleteEvent($companyId,$periodId,(int)$request->input('event_id',0));
                return $this->result(!empty($result['success']),(array)($result['errors']??[]),!empty($result['success'])?'CT600A evidence event removed. Reconfirm the section 464A review.':'');
            }
            return $this->result(false,['Unknown CT600A action.']);
        }catch(Throwable $exception){return $this->result(false,[$exception->getMessage()]);}
    }

    private function result(bool $success,array $errors,string $message=''): ActionResultFramework
    {
        $flashes=[];
        foreach($errors as $error){$flashes[]=['type'=>'error','message'=>(string)$error];}
        if($success&&$message!==''){$flashes[]=['type'=>'success','message'=>$message];}
        return new ActionResultFramework($success,['tax.ct600a','tax.s455','ct.filing','ixbrl.readiness','ixbrl.disclosures','page.context'],$flashes);
    }
}
