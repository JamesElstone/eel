<?php
/**
 * Dry-run-first Director Loan subledger backfill.
 *
 * Usage:
 *   php tools/eel_accounts/backfill_director_loan_subledger.php
 *   php tools/eel_accounts/backfill_director_loan_subledger.php --apply
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$result = (new \eel_accounts\Service\DirectorLoanSubledgerBackfillService())->run($apply);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['success']) ? 0 : 1);
