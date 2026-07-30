<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    GovTalkTransmissionHistoryAction::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        GovTalkTransmissionHistoryAction $action
    ): void {
        $harness->check(
            GovTalkTransmissionHistoryAction::class,
            'requires POST, CSRF and administrator-authorized private downloads',
            static function () use ($harness, $action): void {
                $get = new RequestFramework(
                    ['intent' => 'download_protocol_evidence'],
                    [],
                    ['REQUEST_METHOD' => 'GET'],
                    [],
                    []
                );
                $getResult = $action->handle($get, createTestPageServiceFramework());
                $harness->assertFalse($getResult->isSuccess());
                $harness->assertTrue(str_contains(
                    strtolower((string)($getResult->flashMessages()[0]['message'] ?? '')),
                    'post request'
                ));

                $post = new RequestFramework(
                    [],
                    ['intent' => 'download_protocol_evidence'],
                    ['REQUEST_METHOD' => 'POST'],
                    [],
                    []
                );
                $postResult = $action->handle($post, createTestPageServiceFramework());
                $harness->assertFalse($postResult->isSuccess());
                $harness->assertTrue(str_contains(
                    strtolower((string)($postResult->flashMessages()[0]['message'] ?? '')),
                    'security token'
                ));

                $source = (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content'
                    . DIRECTORY_SEPARATOR . 'actions'
                    . DIRECTORY_SEPARATOR . 'GovTalkTransmissionHistoryAction.php'
                );
                foreach ([
                    'RoleAssignmentService::ADMIN_ROLE_ID',
                    'Cache-Control: no-store, private',
                    'Pragma: no-cache',
                    'evidenceFileForCompany',
                    'recordEvidenceDownload',
                ] as $control) {
                    $harness->assertTrue(str_contains($source, $control));
                }
            }
        );
    }
);
