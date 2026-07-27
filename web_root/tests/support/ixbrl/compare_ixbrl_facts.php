<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

use eel_accounts\Tests\Support\Ixbrl\IxbrlFactSnapshot;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'IxbrlFactSnapshot.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This regression helper is CLI-only.');
}

$beforePath = trim((string)($argv[1] ?? ''));
$afterPath = trim((string)($argv[2] ?? ''));
$reportPath = trim((string)($argv[3] ?? ''));
if ($beforePath === '' || $afterPath === '') {
    fwrite(STDERR, "Usage: php compare_ixbrl_facts.php BEFORE.xhtml AFTER.xhtml [REPORT.json]\n");
    exit(2);
}
$before = file_get_contents($beforePath);
$after = file_get_contents($afterPath);
if (!is_string($before) || !is_string($after)) {
    throw new RuntimeException('Both iXBRL XHTML files must be readable.');
}

$businessNamespace = 'http://xbrl.frc.org.uk/cd/2026-01-01/business';
$report = (new IxbrlFactSnapshot())->compare($before, $after, [
    '{' . $businessNamespace
        . '}StatementRespectsInWhichPreviouslyFiledReportDidNotComplyWithCompaniesAct2006',
    '{' . $businessNamespace . '}StatementSignificantAmendmentsToPreviouslyFiledReport',
]);
$json = json_encode(
    $report,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . PHP_EOL;
if ($reportPath !== '') {
    $directory = dirname($reportPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('The comparison report directory could not be created.');
    }
    if (file_put_contents($reportPath, $json) === false) {
        throw new RuntimeException('The comparison report could not be written.');
    }
}
fwrite(STDOUT, $json);
exit(!empty($report['passed']) ? 0 : 1);
