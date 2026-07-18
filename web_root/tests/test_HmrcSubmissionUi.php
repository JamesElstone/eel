<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = (string)file_get_contents($root . '/web_root/content/pages/HMRC.php');
$card = (string)file_get_contents($root . '/web_root/content/cards/hmrc_submission_unavailable.php');
$action = (string)file_get_contents($root . '/web_root/content/actions/HmrcSubmissionAction.php');

if (!str_contains($page, "'hmrc_submission_unavailable'")) {
    throw new RuntimeException('HMRC Submit tab does not contain the placeholder card.');
}
foreach (['hmrc_submission_overview', 'hmrc_submission_controls', 'hmrc_submission_log',
          'hmrc_submission_history', 'hmrc_submission_supplementary'] as $removedCard) {
    if (str_contains($page, $removedCard)) {
        throw new RuntimeException('HMRC page still references removed card: ' . $removedCard);
    }
}
if (!str_contains($card, 'public function services(): array { return []; }')
    || !str_contains($card, 'CT600 submission is not implemented.')
    || preg_match('/<form|<button|card_action|data-ajax/i', $card) === 1) {
    throw new RuntimeException('HMRC submission placeholder is not service-free and inert.');
}
if (!str_contains($action, 'CT600 submission is not implemented.')
    || preg_match('/InterfaceDB|HmrcCt|GovTalk|stream|header\s*\(/', $action) === 1) {
    throw new RuntimeException('HMRC submission action is not fail-closed.');
}

echo "PASS HMRC submission page and action are inert and fail closed.\n";
