<?php
declare(strict_types=1);

final class ArelleDownloadAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = (string)$request->input('intent', '');
        if ($intent === 'check_arelle_version') {
            try {
                $version = (new \eel_accounts\Service\ArelleDownloadService())->latestVersion();
                return new ActionResultFramework(true, ['arelle.download', 'page.context'], [['type' => 'success', 'message' => 'The latest Arelle release is ' . $version . '.']], [], ['arelle_download' => ['latest_version' => $version]]);
            } catch (Throwable $exception) {
                return new ActionResultFramework(false, ['arelle.download', 'page.context'], [['type' => 'error', 'message' => 'Arelle version check failed: ' . $exception->getMessage()]]);
            }
        }
        if ($intent !== 'download_arelle') { return new ActionResultFramework(false, ['arelle.download'], [['type' => 'error', 'message' => 'Unknown Arelle download action.']]); }
        try {
            @set_time_limit(600);
            $progress = $services->actionProgress();
            $progress->report('Checking the latest Arelle release.', 0);
            $service = new \eel_accounts\Service\ArelleDownloadService();
            $latestVersion = $service->latestVersion();
            $installed = $service->status();
            $installedVersion = $this->versionNumber((string)($installed['version'] ?? ''));
            if (!empty($installed['installed']) && $installedVersion !== null && $installedVersion === $latestVersion) {
                $progress->report('Arelle ' . $latestVersion . ' is already installed.', 100);
                return new ActionResultFramework(true, ['arelle.download', 'page.context'], [['type' => 'success', 'message' => 'Arelle ' . $latestVersion . ' is already installed and current.']], [], ['arelle_download' => ['latest_version' => $latestVersion]]);
            }
            $progress->report('Installing Arelle ' . $latestVersion . '.', 5);
            $lastOutputAt = 0.0;
            $result = $service->downloadAndInstall(
                static function (string $output) use ($progress, &$lastOutputAt): void {
                    $message = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $output));
                    if ($message !== '' && microtime(true) - $lastOutputAt >= 0.5) {
                        $progress->report('Arelle installer: ' . substr($message, 0, 240));
                        $lastOutputAt = microtime(true);
                    }
                },
                $latestVersion
            );
            $progress->report('Arelle download and verification complete.', 100);
            return new ActionResultFramework(true, ['arelle.download', 'ixbrl.readiness', 'ixbrl.generation', 'page.context'], [['type' => 'success', 'message' => 'Arelle ' . $result['version'] . ' was downloaded, smoke-tested and configured for this server.']], [], ['arelle_download' => ['latest_version' => $latestVersion]]);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['arelle.download'], [['type' => 'error', 'message' => 'Arelle download failed: ' . $exception->getMessage()]]);
        }
    }

    private function versionNumber(string $value): ?string
    {
        return preg_match('/\b(\d+\.\d+\.\d+)\b/', $value, $match) === 1 ? $match[1] : null;
    }
}