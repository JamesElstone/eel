<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
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
            $page = (new PageFactoryFramework())->create($pageKey);

            $this->assertTrue($page instanceof PageInterfaceFramework);
            $this->assertSame($pageKey, $page->id());
            $this->assertTrue(is_string($page->title()));
            $this->assertTrue(is_string($page->subtitle()));
            $this->assertTrue(is_bool($page->showsTaxYearSelector()));
            $this->assertTrue(is_array($page->services()));
            $this->assertTrue(is_array($page->cards()));

            foreach ($page->cards() as $cardKey) {
                $this->assertTrue(is_string($cardKey));
                $this->assertTrue(class_exists(HelperFramework::cardKeyToClassName($cardKey)));
            }

            $companyAccount = new CompanyAccountService();
            $companyStore = new CompanyStore();
            $response = $page->handle(
                RequestFramework::fromGlobals(),
                new PageServiceFramework([
                    'company_account' => $companyAccount,
                    CompanyAccountService::class => $companyAccount,
                    CompanyStore::class => $companyStore,
                ])
            );

            $this->assertTrue($response instanceof ResponseFramework);

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

