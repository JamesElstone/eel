<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $reflection = new ReflectionClass(
            \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface::class
        );
        foreach (['configurationStatus', 'prepareSubmissionRequest', 'submit', 'poll', 'delete', 'parseArchivedResponse'] as $method) {
            $h->assertTrue($reflection->hasMethod($method));
        }
        foreach (['poll', 'delete'] as $method) {
            $parameters = $reflection->getMethod($method)->getParameters();
            $h->assertSame(
                'expectedOriginalSubmissionTransactionId',
                $parameters[4]->getName()
            );
            $h->assertFalse($parameters[4]->isOptional());
            $h->assertTrue($parameters[5]->isOptional());
            $h->assertSame('boundConversationTransactionIds', $parameters[6]->getName());
            $h->assertTrue($parameters[6]->isOptional());
        }
        $archivedParameters = $reflection
            ->getMethod('parseArchivedResponse')
            ->getParameters();
        $h->assertSame(
            'expectedOriginalSubmissionTransactionId',
            $archivedParameters[4]->getName()
        );
        $h->assertSame('expectedTransactionId', $archivedParameters[5]->getName());
        $h->assertFalse($archivedParameters[4]->isOptional());
        $h->assertFalse($archivedParameters[5]->isOptional());
        $h->assertSame(
            'boundConversationTransactionIds',
            $archivedParameters[6]->getName()
        );
        $h->assertTrue($archivedParameters[6]->isOptional());
    }
);
