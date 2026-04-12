<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestPagesHarness
{
    public function run(): void
    {
        $pageFiles = $this->pageFiles();
        $this->assertTrue($pageFiles !== []);

        foreach ($pageFiles as $pageFile) {
            $pageKey = basename($pageFile, '.php');
            $page = (new WebPageFactory())->create($pageKey);

            $this->assertTrue($page instanceof WebPageInterface);
            $this->assertSame($pageKey, $page->id());
            $this->assertTrue(is_string($page->title()));
            $this->assertTrue(is_string($page->subtitle()));
            $this->assertTrue(is_array($page->services()));
            $this->assertTrue(is_array($page->cards()));

            foreach ($page->cards() as $cardKey) {
                $this->assertTrue(is_string($cardKey));
                $this->assertTrue(class_exists(FrameworkHelper::cardKeyToClassName($cardKey)));
            }

            $response = $page->handle(
                WebRequest::fromGlobals(),
                new WebPageService(['company_account' => new stdClass()])
            );

            $this->assertTrue($response instanceof WebResponse);

            test_output_line('Pages: ' . $pageKey . ' meets the shared page contract.');
        }
    }

    /**
     * @return list<string>
     */
    private function pageFiles(): array
    {
        $files = glob(APP_PAGES . '*.php');

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

(new TestPagesHarness())->run();
