<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'Ct600SupplementaryAssessmentTestFixture.php';

use eel_accounts\Service\Ct600SupplementaryAssessmentContract;
use eel_accounts\Service\Ct600SupplementaryAssessmentRepository;
use eel_accounts\Service\Ct600SupplementaryEligibilityService;

/** @return array<string, mixed> */
function ct600_supplement_computation(array $ids): array
{
    return [
        'available' => true,
        'computation_run_id' => (int)$ids['computation_run_id'],
        'unknown_treatment_count' => 0,
        'other_treatment_count' => 0,
    ];
}

$harness = new GeneratedServiceClassTestHarness();

$harness->check(
    Ct600SupplementaryEligibilityService::class,
    'fails closed with a named not-assessed blocker for every unresolved matrix row',
    static function () use ($harness): void {
        $ids = ct600_supplement_seed();
        $service = new Ct600SupplementaryEligibilityService(
            new Ct600SupplementaryAssessmentRepository(),
            static fn(): array => ct600_supplement_no_director_exposure()
        );
        $result = $service->assess(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_computation($ids)
        );
        $harness->assertFalse($result['ok']);
        $harness->assertSame(null, $result['assessment_id']);
        $harness->assertCount(19, $result['matrix']);
        $harness->assertTrue(in_array(
            'CT600B has not been assessed for this locked computation.',
            $result['blockers'],
            true
        ));
        $harness->assertFalse(in_array(
            'CT600A has not been assessed for this locked computation.',
            $result['blockers'],
            true
        ));
    }
);

$harness->check(
    Ct600SupplementaryEligibilityService::class,
    'records a complete explicit admin matrix and exposes its id and immutable hash',
    static function () use ($harness): void {
        $ids = ct600_supplement_seed();
        $service = new Ct600SupplementaryEligibilityService(
            new Ct600SupplementaryAssessmentRepository(),
            static fn(): array => ct600_supplement_no_director_exposure()
        );
        $assessment = $service->recordAssessment(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_admin_answers(),
            'user:42',
            new DateTimeImmutable('2026-07-17 11:00:00')
        );
        $result = $service->assess(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_computation($ids)
        );
        $harness->assertTrue($result['ok']);
        $harness->assertSame((int)$assessment['id'], $result['assessment_id']);
        $harness->assertSame((string)$assessment['assessment_hash'], $result['assessment_hash']);
        $harness->assertTrue($result['assessment_hash_valid']);
        $harness->assertSame([], $result['required_pages']);
        $harness->assertSame([], $result['blockers']);
    }
);

$harness->check(
    Ct600SupplementaryEligibilityService::class,
    'names required pages and unknown scope rows exactly',
    static function () use ($harness): void {
        $ids = ct600_supplement_seed();
        $service = new Ct600SupplementaryEligibilityService(
            new Ct600SupplementaryAssessmentRepository(),
            static fn(): array => ct600_supplement_no_director_exposure()
        );
        $service->recordAssessment(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_admin_answers([
                'ct600l' => [
                    'status' => Ct600SupplementaryAssessmentContract::REQUIRED,
                    'detail' => 'The company has an R&D claim.',
                ],
                'unsupported_elections' => [
                    'status' => Ct600SupplementaryAssessmentContract::UNKNOWN,
                    'evidence_source' => '',
                    'evidence_ref' => '',
                    'detail' => '',
                ],
            ]),
            'user:42',
            new DateTimeImmutable('2026-07-17 11:01:00')
        );
        $result = $service->assess(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_computation($ids)
        );
        $harness->assertFalse($result['ok']);
        $harness->assertSame(['CT600L'], $result['required_pages']);
        $harness->assertTrue(in_array(
            'CT600L is required and unsupported in phase one: The company has an R&D claim.',
            $result['blockers'],
            true
        ));
        $harness->assertTrue(in_array(
            'Unsupported elections has not been assessed for this locked computation.',
            $result['blockers'],
            true
        ));
    }
);

$harness->check(
    Ct600SupplementaryEligibilityService::class,
    'forces CT600A from DirectorLoanService evidence and prevents a manual downgrade',
    static function () use ($harness): void {
        $ids = ct600_supplement_seed();
        $service = new Ct600SupplementaryEligibilityService(
            new Ct600SupplementaryAssessmentRepository(),
            static fn(): array => ct600_supplement_director_exposure()
        );
        $manualDowngradeRejected = false;
        $answers = ct600_supplement_admin_answers();
        $answers['ct600a'] = [
            'contract_key' => 'ct600a',
            'status' => Ct600SupplementaryAssessmentContract::NOT_REQUIRED,
        ];
        try {
            $service->recordAssessment(
                (int)$ids['company_id'],
                (int)$ids['accounting_period_id'],
                (int)$ids['ct_period_id'],
                $answers,
                'user:42'
            );
        } catch (DomainException $exception) {
            $manualDowngradeRejected = str_contains($exception->getMessage(), 'DirectorLoanService');
        }
        $harness->assertTrue($manualDowngradeRejected);

        $service->recordAssessment(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_admin_answers(),
            'user:42',
            new DateTimeImmutable('2026-07-17 11:02:00')
        );
        $result = $service->assess(
            (int)$ids['company_id'],
            (int)$ids['accounting_period_id'],
            (int)$ids['ct_period_id'],
            ct600_supplement_computation($ids)
        );
        $harness->assertSame(['CT600A'], $result['required_pages']);
        $harness->assertTrue(in_array(
            'CT600A is required and unsupported in phase one: The locked director-loan review reports a participator-loan exposure of £1,250.00.',
            $result['blockers'],
            true
        ));
    }
);
