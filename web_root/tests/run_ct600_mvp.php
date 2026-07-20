<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'SelectedTestRunner.php';

$patterns = [
    'test_Ct600*.php',
    'test_CtFilingMappingService.php',
    'test_CtPeriodFilingModelService.php',
    'test_CsrfFormCoverage.php',
    'test_HmrcCorporationTaxSubmissionService.php',
    'test_HmrcCtComputationCatalogueService.php',
    'test_HmrcCtTransactionEngineClient.php',
    'test_HmrcSubmissionPackageService.php',
    'test_HmrcSubmissionUi.php',
    'test_IxbrlBuilderCards.php',
    'test_IxbrlTaxComputationService.php',
    'test_PageArchitecture.php',
];
$files = [];
foreach ($patterns as $pattern) {
    $matches = glob(__DIR__ . DIRECTORY_SEPARATOR . $pattern);
    if (is_array($matches)) {
        $files = array_merge($files, $matches);
    }
}

eel_accounts_run_selected_tests($files);
