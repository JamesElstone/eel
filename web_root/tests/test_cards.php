<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestCardsHarness
{
    public function run(): void
    {
        $cardFiles = $this->cardFiles();
        $this->assertTrue($cardFiles !== []);

        foreach ($cardFiles as $cardFile) {
            $cardKey = basename($cardFile, '.php');
            $className = HelperFramework::cardKeyToClassName($cardKey);

            $this->assertTrue(class_exists($className));

            $card = new $className();
            $this->assertTrue($card instanceof CardInterfaceFramework);
            $this->assertSame($cardKey, $card->key());
            $this->assertTrue(is_array($card->services()));
            $this->assertTrue(is_array($card->invalidationFacts()));
            $this->assertTrue(is_string($card->handleError('demo', ['type' => 'error', 'message' => 'Demo'], [])));
            $this->assertTrue(is_string($card->render([])));

            test_output_line('Cards: ' . $cardKey . ' meets the shared card contract.');
        }
    }

    /**
     * @return list<string>
     */
    private function cardFiles(): array
    {
        $files = glob(APP_CARDS . '*.php');

        if ($files === false) {
            return [];
        }

        sort($files);

        return array_values(array_filter($files, 'is_file'));
    }

    private function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Assertion failed. Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }

    private function assertTrue(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed. Expected condition to be true.');
        }
    }
}

(new TestCardsHarness())->run();

