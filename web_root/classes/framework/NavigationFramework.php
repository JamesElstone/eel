<?php
declare(strict_types=1);

final class NavigationFramework
{
    private const DEFAULT_ORDER = [
        'dashboard' => 10,
        'uploads' => 20,
        'transactions' => 30,
        'expenses' => 40,
        'directorLoan' => 50,
        'trialBalance' => 60,
        'yearEnd' => 70,
        'companies' => 80,
        'settings' => 90,
    ];

    private readonly string $pagesDirectory;
    private readonly string $currentPageKey;
    private readonly string $baseUrl;
    private ?array $availableIconPaths = null;

    public function __construct(string $pagesDirectory, string $currentPageKey, string $baseUrl = '/?page=')
    {
        $this->pagesDirectory = rtrim($pagesDirectory, '\\/');
        $this->currentPageKey = trim($currentPageKey);
        $this->baseUrl = $baseUrl;
    }

    public function build(): array
    {
        if (!is_dir($this->pagesDirectory)) {
            return [];
        }

        $entries = scandir($this->pagesDirectory);
        if (!is_array($entries)) {
            return [];
        }

        $items = [];

        foreach ($entries as $filename) {
            if (!$this->isPageFile($filename)) {
                continue;
            }

            $pageKey = $this->pageKeyFromFilename($filename);
            if ($pageKey === '') {
                continue;
            }

            $label = $this->labelFromPageKey($pageKey);
            $items[] = [
                'key' => $pageKey,
                'label' => $label,
                'url' => $this->baseUrl . rawurlencode($pageKey),
                'icon_path' => $this->iconPathForPageKey($pageKey),
                'is_active' => strcasecmp($pageKey, $this->currentPageKey) === 0,
                'order' => $this->orderForPageKey($pageKey),
                'short' => strtoupper((string)substr(preg_replace('/[^A-Za-z0-9]/', '', $label) ?? '', 0, 1)),
            ];
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                $orderComparison = ($left['order'] ?? 1000) <=> ($right['order'] ?? 1000);
                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                return strcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
            }
        );

        return $items;
    }

    private function isPageFile(string $filename): bool
    {
        if ($filename === '.' || $filename === '..') {
            return false;
        }

        $fullPath = $this->pagesDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($fullPath)) {
            return false;
        }

        if (!str_ends_with($filename, '.php')) {
            return false;
        }

        $basename = pathinfo($filename, PATHINFO_FILENAME);

        if ($basename === '' || str_starts_with($basename, '_') || str_ends_with($basename, '.nav')) {
            return false;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $basename) === 1;
    }

    private function pageKeyFromFilename(string $filename): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $basename) === 1 ? $basename : '';
    }

    private function labelFromPageKey(string $pageKey): string
    {
        $label = preg_replace('/(?<=\p{Ll}|\d)(\p{Lu})/u', ' $1', $pageKey);
        $label = preg_replace('/[_\-]+/', ' ', (string)$label);
        $label = trim((string)$label);

        return $label === '' ? $pageKey : ucwords($label);
    }

    private function iconPathForPageKey(string $pageKey): ?string
    {
        $label = $this->labelFromPageKey($pageKey);
        $availableIcons = $this->availableIconPaths();

        foreach ($this->iconCandidates($pageKey, $label) as $iconKey) {
            if (isset($availableIcons[$iconKey])) {
                return $availableIcons[$iconKey];
            }
        }

        return null;
    }

    private function orderForPageKey(string $pageKey): int
    {
        return self::DEFAULT_ORDER[$pageKey] ?? 1000;
    }

    private function iconCandidates(string $pageKey, string $label): array
    {
        $candidates = [];

        foreach ($this->iconDirectories() as $directory) {
            foreach ($this->iconFileNames($pageKey, $label) as $filename) {
                $path = $directory . DIRECTORY_SEPARATOR . $filename;
                $normalisedPath = $this->normalisePath($path);
                if ($normalisedPath === null) {
                    continue;
                }

                $candidates[] = strtolower($normalisedPath);
            }
        }

        return array_values(array_unique($candidates));
    }

    private function availableIconPaths(): array
    {
        if (is_array($this->availableIconPaths)) {
            return $this->availableIconPaths;
        }

        $paths = [];

        foreach ($this->iconDirectories() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!is_string($entry) || !str_ends_with(strtolower($entry), '.svg')) {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                $normalisedPath = $this->normalisePath($path);
                $webPath = $this->toWebPath($path);

                if ($normalisedPath === null || $webPath === null) {
                    continue;
                }

                $paths[strtolower($normalisedPath)] = $webPath;
            }
        }

        return $this->availableIconPaths = $paths;
    }

    private function iconFileNames(string $pageKey, string $label): array
    {
        $names = [];
        $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $label));
        $slug = trim($slug, '-');

        foreach ([
            $label . '.svg',
            strtolower($label) . '.svg',
            $slug !== '' ? $slug . '.svg' : null,
            $pageKey . '.svg',
        ] as $filename) {
            if (is_string($filename) && $filename !== '') {
                $names[] = $filename;
            }
        }

        return array_values(array_unique($names));
    }

    private function iconDirectories(): array
    {
        $directories = [rtrim($this->pagesDirectory, '\\/')];

        if (defined('APP_ROOT')) {
            $directories[] = rtrim(APP_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'svg';
        }

        return array_values(array_unique(array_filter($directories, static fn (mixed $value): bool => is_string($value) && $value !== '')));
    }

    private function normalisePath(string $filesystemPath): ?string
    {
        $resolved = realpath($filesystemPath);
        if ($resolved === false) {
            $resolved = $filesystemPath;
        }

        $normalised = str_replace('\\', '/', $resolved);

        return $normalised === '' ? null : $normalised;
    }

    private function toWebPath(string $filesystemPath): ?string
    {
        $rootPath = defined('APP_ROOT') ? APP_ROOT : null;
        if (!is_string($rootPath) || $rootPath === '') {
            return null;
        }

        $normalisedRoot = str_replace('\\', '/', rtrim($rootPath, '\\/'));
        $normalisedPath = str_replace('\\', '/', $filesystemPath);

        if (!str_starts_with($normalisedPath, $normalisedRoot . '/')) {
            return null;
        }

        return '/' . ltrim(substr($normalisedPath, strlen($normalisedRoot)), '/');
    }
}
