<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\YearEndSectionApprovalService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\YearEndSectionApprovalService $service
): void {
    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'includes question wording, versions, options, and answers in the approval basis', static function () use ($harness, $service): void {
        $basisMethod = new ReflectionMethod($service, 'approvalBasis');
        $bundle = [
            'check_code' => 'director_loan_year_end_review',
            'facts' => ['entry_count' => 3, 'potential_s455_exposure_amount' => '125.00'],
            'questions' => [[
                'id' => 'ct600a.missing_parties',
                'prompt' => 'Are any participators missing?',
                'version' => 'question-v1',
                'type' => 'choice',
                'options' => ['no' => 'No', 'yes' => 'Yes'],
                'required' => true,
                'required_value' => 'no',
            ]],
        ];
        $basis = $basisMethod->invoke($service, $bundle, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame('Are any participators missing?', (string)$basis['questions'][0]['prompt']);
        $harness->assertSame('No', (string)$basis['questions'][0]['options']['no']);
        $harness->assertSame('no', (string)$basis['answers']['ct600a.missing_parties']);

        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = [
            'basis_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
            'basis_hash' => $acknowledgements->hashBasis($basis),
        ];
        $harness->assertSame('current', (string)$acknowledgements->evaluate(
            $acknowledgement,
            $basis,
            false,
            \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
        )['state']);

        $bundle['questions'][0]['prompt'] = 'Are any participators or associates missing?';
        $changedBasis = $basisMethod->invoke($service, $bundle, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame('stale', (string)$acknowledgements->evaluate(
            $acknowledgement,
            $changedBasis,
            false,
            \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
        )['state']);
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'enforces each declared required gate before approval', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'validateAnswers');
        $questions = [[
            'id' => 'ct600a.missing_parties',
            'prompt' => 'Are any participators missing?',
            'type' => 'choice',
            'options' => ['no' => 'No', 'yes' => 'Yes'],
            'required' => true,
            'required_value' => 'no',
        ]];
        $blocked = (array)$method->invoke($service, $questions, ['ct600a.missing_parties' => 'yes']);
        $harness->assertSame(false, !empty($blocked['success']));
        $approved = (array)$method->invoke($service, $questions, ['ct600a.missing_parties' => 'no']);
        $harness->assertSame(true, !empty($approved['success']));
        $harness->assertSame('no', (string)$approved['answers']['ct600a.missing_parties']);
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'builds the P&L bundle directly from the prepared retained earnings context', static function () use ($harness, $service): void {
        $method = new ReflectionMethod($service, 'retainedEarningsBundle');
        $bundle = (array)$method->invoke($service, 12, 34, [
            'available' => true,
            'can_acknowledge' => true,
            'summary' => ['current_profit_loss' => 125.50],
            'journal_lines' => [['nominal_account_id' => 7, 'debit' => 125.50, 'credit' => 0]],
            'reserve_review' => ['available' => true, 'snapshot_current' => true, 'rows' => []],
            'prior_period_dependency' => ['satisfied' => true],
            'accounting_period' => ['id' => 34],
        ]);

        $harness->assertSame('retained_earnings_close_confirmation', (string)$bundle['check_code']);
        $harness->assertSame(125.50, (float)((($bundle['facts']['facts'] ?? [])['summary'] ?? [])['current_profit_loss'] ?? 0));
        $harness->assertSame(34, (int)(($bundle['display']['accounting_period'] ?? [])['id'] ?? 0));

        $source = (string)file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'YearEndSectionApprovalService.php');
        $start = strpos($source, 'private function retainedEarningsBundle(');
        $end = strpos($source, 'private function retainedEarningsDisplay(', $start !== false ? $start : 0);
        $provider = $start !== false && $end !== false ? substr($source, $start, $end - $start) : '';
        $harness->assertSame(false, str_contains($provider, 'fetchChecklist('));
    });

    $harness->check(\eel_accounts\Service\YearEndSectionApprovalService::class, 'ships the persistent bundle migration and master-schema definition', static function () use ($harness): void {
        $root = dirname(__DIR__, 2);
        $schema = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql');
        $migration = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_24_001_year_end_section_review_bundles.sql');
        $harness->assertSame(true, str_contains($schema, 'CREATE TABLE `year_end_section_review_bundles`'));
        $harness->assertSame(true, str_contains($schema, "'2026_07_24_001_year_end_section_review_bundles.sql'"));
        $harness->assertSame(true, str_contains($migration, 'UNIQUE KEY `uq_year_end_section_review_bundle`'));
        $harness->assertSame(true, str_contains($migration, '`bundle_json` longtext NOT NULL'));
    });
});
