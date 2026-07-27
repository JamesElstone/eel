<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
            'retires company-period presentation storage at the party-terms cutover',
            static function () use ($harness): void {
                $schema = (string)file_get_contents(
                    dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql'
                );
                $migration = (string)file_get_contents(
                    dirname(__DIR__, 2)
                    . DIRECTORY_SEPARATOR . 'db_schema'
                    . DIRECTORY_SEPARATOR . 'migrations'
                    . DIRECTORY_SEPARATOR . '2026_07_26_006_participator_loan_party_terms.sql'
                );

                $harness->assertFalse(str_contains(
                    $schema,
                    'CREATE TABLE `director_loan_reporting_presentations`'
                ));
                $harness->assertFalse(str_contains(
                    $schema,
                    'CREATE TABLE `director_loan_reporting_presentation_audit`'
                ));
                $harness->assertTrue(str_contains(
                    $schema,
                    'CREATE TABLE `participator_loan_party_terms`'
                ));
                $harness->assertTrue(str_contains(
                    $schema,
                    'CREATE TABLE `participator_loan_party_term_snapshots`'
                ));
                $harness->assertTrue(str_contains(
                    $migration,
                    'DROP TABLE IF EXISTS director_loan_reporting_presentations'
                ));
                $harness->assertTrue(str_contains(
                    $migration,
                    'DROP TABLE IF EXISTS director_loan_reporting_presentation_audit'
                ));
            }
        );
    }
);
