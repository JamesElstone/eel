<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompaniesHouseIncorporationDocumentStatusService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\FileCheckService $fileCheckService = null,
    ) {
    }

    public function statusForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return $this->notDownloaded();
        }

        try {
            $directory = $this->fileCheckService()->getCompaniesHouseDirectory($companyId);
        } catch (\Throwable) {
            return $this->notDownloaded();
        }

        if (!is_dir($directory) || !is_readable($directory)) {
            return $this->notDownloaded();
        }

        $document = $this->latestIncorporationPdf($directory);

        if ($document === null) {
            return $this->notDownloaded();
        }

        return [
            'downloaded' => true,
            'downloaded_at' => date('Y-m-d H:i:s', (int)$document['mtime']),
            'filename' => (string)$document['filename'],
        ];
    }

    private function latestIncorporationPdf(string $directory): ?array
    {
        $items = scandir($directory);
        if ($items === false) {
            return null;
        }

        $matches = [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path) || !$this->isIncorporationPdf($item)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false) {
                continue;
            }

            $matches[] = [
                'filename' => $item,
                'mtime' => $mtime,
            ];
        }

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn(array $left, array $right): int => ((int)$right['mtime'] <=> (int)$left['mtime'])
                ?: strcmp((string)$right['filename'], (string)$left['filename'])
        );

        return $matches[0];
    }

    private function isIncorporationPdf(string $filename): bool
    {
        $filename = trim($filename);

        if (!str_ends_with(strtolower($filename), '.pdf')) {
            return false;
        }

        return preg_match('/(^|[_-])newinc([_.-]|$)/i', $filename) === 1
            || preg_match('/(^|[_-])incorporation([_.-]|$)/i', $filename) === 1;
    }

    private function notDownloaded(): array
    {
        return [
            'downloaded' => false,
            'downloaded_at' => '',
            'filename' => '',
        ];
    }

    private function fileCheckService(): \eel_accounts\Service\FileCheckService
    {
        return $this->fileCheckService ?? new \eel_accounts\Service\FileCheckService();
    }
}
