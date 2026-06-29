<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$classDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes';
$testsDirectory = __DIR__;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($classDirectory));
$missingTests = [];
$legacyTestNames = [
    'AccountingContextService.php' => ['test_AccountingContextService.php', 'test_CompanyStore.php'],
    'CardBaseFramework.php' => ['test_CardBaseFramework.php', 'test_BaseCardFramework.php'],
    'Frs105ValidationService.php' => ['test_IxbrlReadinessService.php', 'test_TrialBalanceValidationService.php'],
    'NullSiteContextProviderFramework.php' => ['test_SiteContextFramework.php'],
    'PageBaseFramework.php' => ['test_PageBaseFramework.php', 'test_BasePageFramework.php'],
    'PageContextFramework.php' => ['test_PageContextFramework.php', 'test_BaseModulePageFramework.php'],
    'SiteContextCoordinatorFramework.php' => ['test_SiteContextFramework.php'],
    'SiteContextProviderInterface.php' => ['test_SiteContextFramework.php'],
    'SiteContextRendererFramework.php' => ['test_SiteContextFramework.php'],
    'SiteContextResultFramework.php' => ['test_SiteContextFramework.php'],
    'TableColumnFramework.php' => ['test_TableFramework.php'],
    'TableExportFramework.php' => ['test_TableFramework.php'],
];

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }

    $candidateTests = $legacyTestNames[$fileInfo->getFilename()] ?? ['test_' . $fileInfo->getBasename('.php') . '.php'];
    $matchingTestFound = false;

    foreach ($candidateTests as $candidateTest) {
        if (is_file($testsDirectory . DIRECTORY_SEPARATOR . $candidateTest)) {
            $matchingTestFound = true;
            break;
        }
    }

    if (!$matchingTestFound) {
        $missingTests[] = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
    }
}

sort($missingTests);

if ($missingTests !== []) {
    throw new RuntimeException(
        'Missing matching test files for class PHP files: ' . implode(', ', $missingTests)
    );
}

test_output_line('ClassFileCoverage: every /classes PHP file has a matching test file.');
