<?php
declare(strict_types=1);

final class ArelleDownloadAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ((string)$request->input('intent', '') !== 'download_arelle') {
            return new ActionResultFramework(false, ['arelle.download'], [['type' => 'error', 'message' => 'Unknown Arelle download action.']]);
        }

        try {
            $result = (new \eel_accounts\Service\ArelleDownloadService())->downloadAndInstall();
            return new ActionResultFramework(true, ['arelle.download', 'ixbrl.readiness', 'ixbrl.generation', 'page.context'], [[
                'type' => 'success',
                'message' => 'Arelle ' . $result['version'] . ' was downloaded, smoke-tested and configured for this server.',
            ]]);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['arelle.download'], [['type' => 'error', 'message' => 'Arelle download failed: ' . $exception->getMessage()]]);
        }
    }
}
