<?php
declare(strict_types=1);

final class _context_dumpCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'context_dump';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'missingContext',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':missing_company_id',
                    'activeOnly' => true,
                ],
            ],
        ];
    }

    public function invalidationFacts(): array
    {
        return ['test.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        $errorSummary = '';

        foreach ((array)($context['service_errors'] ?? []) as $serviceKey => $error) {
            if (!is_array($error) || !isset($error['rendered'])) {
                continue;
            }

            $errorSummary .= '<div class="helper">' . HelperFramework::escape((string)$error['rendered']) . '</div>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
                    <h2 class="card-title">Full context dump</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">This is the full card-local context array, including wrapped page context, resolved services, and rendered error states.</p>
                ' . $errorSummary . '
                <pre class="panel-soft" style="margin: 0; overflow: auto; white-space: pre-wrap;">' . HelperFramework::escape($this->dumpContext($context)) . '</pre>
            </div>
        </div>';
    }

    private function dumpContext(array $context): string
    {
        ob_start();
        var_dump($context);

        return trim((string)ob_get_clean());
    }
}

