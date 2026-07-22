<?php
declare(strict_types=1);

final class LoanReviewAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ((string)$request->input('intent', '') !== 'acknowledge_future_loan_attribution_warning') {
            return ActionResultFramework::none();
        }
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        try {
            $result = (new \eel_accounts\Service\LoanReviewService())->acknowledgeFutureAttributionWarning(
                $companyId,
                $accountingPeriodId,
                $this->actor($request)
            );
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }
        $success = !empty($result['success']);
        $messages = $success
            ? [['type' => 'success', 'message' => 'Future repayment-attribution warning acknowledged.']]
            : array_map(static fn(mixed $error): array => ['type' => 'error', 'message' => (string)$error], (array)($result['errors'] ?? ['The warning could not be acknowledged.']));

        return new ActionResultFramework(
            $success,
            ['director.loan.state', 'tax.s455', 'tax.workings', 'year.end.checklist', 'page.context'],
            $messages
        );
    }

    private function actor(RequestFramework $request): string
    {
        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
            return $userId > 0 ? 'user:' . $userId : 'web_app';
        } catch (Throwable) {
            return 'web_app';
        }
    }
}
