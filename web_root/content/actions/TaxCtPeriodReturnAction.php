<?php
declare(strict_types=1);

final class TaxCtPeriodReturnAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $companyId = (int)$request->input('company_id', 0);
        $accountingPeriodId = (int)$request->input('accounting_period_id', 0);
        $ctPeriodId = (int)$request->input('ct_period_id', 0);
        $intent = trim((string)$request->input('intent', ''));
        try {
            if ($intent === 'download') {
                $this->download($companyId, $accountingPeriodId, $ctPeriodId);
            }
            $service = new \eel_accounts\Service\IxbrlTaxComputationService();
            $result = match ($intent) {
                'generate' => $service->generateFilingExport($companyId, $accountingPeriodId, $ctPeriodId),
                'validate' => $service->validateFilingExport($companyId, $accountingPeriodId, $ctPeriodId),
                default => ['success' => false, 'errors' => ['Unknown CT-period iXBRL action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }
        $messages = [];
        foreach (!empty($result['success']) ? [$intent === 'validate' ? 'Tax computation iXBRL validation passed.' : 'Tax computation iXBRL generated and validated.'] : (array)($result['errors'] ?? ['Generation failed.']) as $message) {
            $messages[] = ['type' => !empty($result['success']) ? 'success' : 'error', 'message' => (string)$message];
        }
        return new ActionResultFramework(!empty($result['success']), ['tax.ct.ixbrl', 'page.context'], $messages, ['ct_period_id' => (string)$ctPeriodId]);
    }

    private function download(int $companyId, int $accountingPeriodId, int $ctPeriodId): never
    {
        if ($companyId <= 0 || $companyId !== (new \eel_accounts\Service\AccountingContextService())->authCompanyId()) {
            header('Content-Type: text/plain; charset=utf-8', true, 403); echo 'The company is not available in the current accounting context.'; exit;
        }
        $status = (new \eel_accounts\Service\IxbrlTaxComputationService())->status($companyId, $accountingPeriodId, $ctPeriodId);
        if (empty($status['fresh'])) {
            header('Content-Type: text/plain; charset=utf-8', true, 409); echo 'The CT-period iXBRL artifact is stale or unavailable.'; exit;
        }
        $run = (array)$status['run'];
        $path = (string)($run['generated_path'] ?? '');
        if (!is_file($path)) {
            header('Content-Type: text/plain; charset=utf-8', true, 404); echo 'The CT-period iXBRL artifact was not found.'; exit;
        }
        header('Content-Type: application/xhtml+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename((string)$run['generated_filename'])) . '"');
        $size = filesize($path); if (is_int($size)) { header('Content-Length: ' . $size); }
        readfile($path); exit;
    }
}
