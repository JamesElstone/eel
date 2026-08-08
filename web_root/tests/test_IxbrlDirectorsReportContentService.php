<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlDirectorsReportContentService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlDirectorsReportContentService $service
    ): void {
        $harness->check($service::class, 'splits confirmation notes by lines and sentence punctuation without losing Unicode', static function () use ($harness, $service): void {
            $sentences = new ReflectionMethod($service::class, 'sentences');
            $sentences->setAccessible(true);
            $harness->assertSame([
                'First sentence.',
                'Second sentence!',
                'Third question?',
                'Café résumé without punctuation',
            ], $sentences->invoke(
                $service,
                "First sentence. Second sentence!\nThird question?\r\nCafé résumé without punctuation"
            ));
        });

        $harness->check($service::class, 'returns a deterministic blank source when no context is selected', static function () use ($harness, $service): void {
            $result = $service->fetch(0, 0);
            $harness->assertFalse((bool)$result['available']);
            $harness->assertTrue((bool)$result['review_notes_blank']);
            $harness->assertSame(hash('sha256', ''), $result['review_notes_hash']);
            $harness->assertSame([], $result['confirmation_sentences']);
        });
    }
);
