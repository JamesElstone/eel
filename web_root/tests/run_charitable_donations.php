<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'SelectedTestRunner.php';

$files = array_map(
    static fn(string $name): string => __DIR__ . DIRECTORY_SEPARATOR . $name,
    [
        'test_CharityRegistryClients.php',
        'test_CharityRegistryOutbound.php',
        'test_CharitableDonationService.php',
        'test_CorporationTaxTreatmentRuleService.php',
        'test_CorporationTaxComputationService.php',
        'test_CorporationTaxHardGateService.php',
        'test_Ct600ReturnModelService.php',
        'test_Ct600BuilderService.php',
        'test_CtFilingMappingService.php',
        'test_FilingEvidenceCards.php',
        'test_GoldenCharitableDonationLifecycle.php',
    ]
);

eel_accounts_run_selected_tests($files);
