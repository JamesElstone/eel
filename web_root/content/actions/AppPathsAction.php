<?php
declare(strict_types=1);

final class AppPathsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = $request->post('intent', '');

        if (!in_array($intent, ['check', 'create'], true)) {
            return ActionResultFramework::none();
        }

        $pathStatus = $this->buildPathStatus((new \eel_accounts\Service\AccountingContextService())->authCompanyId(), $intent === 'create');

        // array $changedFacts = [],
        // array $flashMessages = [],
        // array $query = [],
        // array $context = []

        return ActionResultFramework::success(
            changedFacts: ['check.file.paths'],
            flashMessages: [$pathStatus['message']],
            context: ['path_status' => $pathStatus]
        );
    }

    private function buildPathStatus(int $companyId, bool $createMissingPaths = false): array
    {
        $fileCheckService = new \eel_accounts\Service\FileCheckService();
        $items = $this->CalculatePathItems($fileCheckService, $companyId, $createMissingPaths);
        $state = 'ok';
        $message = $createMissingPaths
            ? 'Missing paths were created where needed.'
            : 'All tested paths are ready.';

        if ($createMissingPaths && $companyId <= 0) {
            return [
                'state' => 'warn',
                'message' => 'Select a company before creating company upload paths.',
                'debug' => $fileCheckService->getPathDebug(),
                'items' => $items,
            ];
        }

        foreach ($items as $item) {
            $itemState = $this->normaliseItemState($item['state'] ?? false);

            if ($itemState === 'bad') {
                $state = 'bad';
                $message = $createMissingPaths
                    ? 'One or more tested paths still need attention after attempting to create them.'
                    : 'One or more tested paths need attention.';
                break;
            }

            if ($itemState === 'warn' && $state === 'ok') {
                $state = 'warn';
                $message = $createMissingPaths
                    ? 'Select a company before creating company upload paths.'
                    : 'Some path checks are waiting for a company selection.';
            }
        }

        return [
            'state' => $state,
            'message' => $message,
            'debug' => $fileCheckService->getPathDebug(),
            'items' => $items,
        ];
    }

    private function CalculatePathItems(\eel_accounts\Service\FileCheckService $fileCheckService, int $companyId, bool $createMissingPaths = false): array
    {
        $items = [];
        $items[] = $this->buildPathItem(
            'Upload base directory',
            $fileCheckService->getUpload(),
            $fileCheckService->inspectUploadBase(),
            $fileCheckService
        );

        if ($companyId <= 0) {
            $items[] = [
                'title' => 'Company upload directory',
                'state' => 'warn',
                'path' => '',
                'detail' => 'Select a company before checking the company upload directory.',
            ];

            return $items;
        }

        $directories = $fileCheckService->getCompanyUploadDirectories($companyId);

        if ($createMissingPaths) {
            try {
                $fileCheckService->ensureCompanyUploadDirectories($companyId);
            } catch (RuntimeException) {
                // The follow-up inspection reports the resulting state and detail.
            }
        }

        $inspections = $fileCheckService->inspectCompanyUploadDirectories($companyId);
        $items[] = $this->buildPathItem(
            'Company upload directory',
            $directories['company'],
            $inspections['company'],
            $fileCheckService
        );
        $items[] = $this->buildPathItem(
            'Statement upload directory',
            $directories['statement'],
            $inspections['statement'],
            $fileCheckService
        );
        $items[] = $this->buildPathItem(
            'Expense receipt directory',
            $directories['expense'],
            $inspections['expense'],
            $fileCheckService
        );
        $items[] = $this->buildPathItem(
            'Transaction receipt directory',
            $directories['receipt'],
            $inspections['receipt'],
            $fileCheckService
        );

        return $items;
    }

    private function buildPathItem(
        string $title,
        string $path,
        array $inspection,
        \eel_accounts\Service\FileCheckService $fileCheckService
    ): array {
        return [
            'title' => $title,
            'state' => $inspection['state'],
            'path' => $fileCheckService->getPathDebug() ? $path : '',
            'detail' => $inspection['detail'],
        ];
    }

    private function normaliseItemState(mixed $state): string
    {
        if ($state === 'warn') {
            return 'warn';
        }

        return $state === true || $state === 'ok' ? 'ok' : 'bad';
    }
}
