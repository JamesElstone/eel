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
                foreach(array_keys($service->reviewQuestions()) as $key){$answers[$key]=$request->input($key,'yes');}
                $result=$service->saveReview($companyId,$periodId,$answers,
                    'director',$this->actor($request),'');
                return $this->result(!empty($result['success']),(array)($result['errors']??[]),!empty($result['success'])?'Section 464A and 464C declaration saved. The filing basis must be approved again.':'');
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

    private function actor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        } catch (Throwable) {
        }

        return 'web_app';
    }
}
