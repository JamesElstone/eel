<?php
declare(strict_types=1);

final class FrcTaxonomyAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ((string)$request->input('intent', '') !== 'refresh_frc_taxonomy') { return new ActionResultFramework(false, ['frc.taxonomy'], [['type' => 'error', 'message' => 'Unknown FRC taxonomy action.']]); }
        try {
            $result = (new \eel_accounts\Service\FrcTaxonomyPackageService())->refreshAndInstall();
            return new ActionResultFramework(!empty($result['success']), ['frc.taxonomy', 'ixbrl.readiness', 'ixbrl.generation', 'page.context'], [['type' => 'success', 'message' => 'The FRC accounts taxonomy package was downloaded, verified and activated for offline Arelle validation.']]);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['frc.taxonomy'], [['type' => 'error', 'message' => 'FRC taxonomy refresh failed: ' . $exception->getMessage()]]);
        }
    }
}
