<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'SelectedTestRunner.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenFilingArtifactReviewPack.php';

$goldenArtifactReview = null;
if (GoldenFilingArtifactReviewPack::requested($_SERVER['argv'] ?? [])) {
    $goldenArtifactReview = GoldenFilingArtifactReviewPack::create(
        test_upload_base_directory(),
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'artefacts'
    );
    $GLOBALS['eel_accounts_golden_artifact_review'] = $goldenArtifactReview;
}

$files = array_map(
    static fn(string $name): string => __DIR__ . DIRECTORY_SEPARATOR . $name,
    [
        'test_GoldenAccountingOracle.php',
        'test_GoldenCt600aLifecycle.php',
        'test_GoldenYearEndLifecycle.php',
        'test_GoldenAccountingCardAuditDefects.php',
        'test_GoldenAccountsFixture.php',
        'test_GoldenFilingArtifactReviewPack.php',
        'test_GoldenTaxControlMatrix.php',
    ]
);

eel_accounts_run_selected_tests($files);

if ($goldenArtifactReview instanceof GoldenFilingArtifactReviewPack) {
    unset($GLOBALS['eel_accounts_golden_artifact_review']);
    $published = $goldenArtifactReview->publish();
    echo PHP_EOL . 'Golden filing artefact review: ' . (string)$published['index_path'] . PHP_EOL;
    if (empty($published['success'])) {
        fwrite(
            STDERR,
            'Golden filing artefact export was partial: '
                . implode(' ', (array)$published['errors']) . PHP_EOL
        );
        exit(1);
    }
}
