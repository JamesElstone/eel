<?php
declare(strict_types=1);

final class _test_targetCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'test_target';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'accounts',
                'service' => CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company_id',
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
        $message = (string)($error['message'] ?? 'Unknown card service error.');
        $type = (string)($error['type'] ?? 'error');

        return '<div class="panel-soft">
            <strong>' . HelperFramework::escape(ucwords(str_replace('_', ' ', $type))) . '</strong>
            <div class="helper">' . HelperFramework::escape($message) . '</div>
        </div>';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $shared = (array)($page['shared_demo_context'] ?? []);
        $accounts = (array)($context['services']['accounts'] ?? []);
        $accountsError = $context['service_errors']['accounts'] ?? null;
        $itemsHtml = '';
        $accountsHtml = '';

        foreach ((array)($shared['items'] ?? []) as $item) {
            $itemsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)$item) . '</strong>
                <span>Read from the shared context payload prepared by the source card.</span>
            </div>';
        }

        foreach ($accounts as $account) {
            $accountsHtml .= '<div class="list-item">
                <strong>' . HelperFramework::escape((string)($account['account_name'] ?? 'Unknown account')) . '</strong>
                <span>' . HelperFramework::escape((string)($account['account_type'] ?? '')) . '</span>
            </div>';
        }

        if ($accountsHtml === '' && is_array($accountsError) && isset($accountsError['rendered'])) {
            $accountsHtml = (string)$accountsError['rendered'];
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">Context consumer</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
                <span class="status-pill">' . HelperFramework::escape((string)($shared['status'] ?? 'Unknown')) . '</span>
            </div>
            <div class="card-body stack">
                <p><strong>' . HelperFramework::escape((string)($shared['title'] ?? 'No title')) . '</strong></p>
                <p class="helper">' . HelperFramework::escape((string)($shared['summary'] ?? 'No summary available.')) . '</p>
                <div class="panel-soft">
                    <strong>Passed note</strong>
                    <div class="helper">' . HelperFramework::escape((string)($shared['note'] ?? '')) . '</div>
                </div>
                <div class="list">' . $itemsHtml . '</div>
                <div class="stack">
                    <strong>Resolved company accounts</strong>
                    <div class="helper">This card resolves the same service by class name from its own card-local declaration.</div>
                    <div class="list">' . $accountsHtml . '</div>
                </div>
            </div>
        </div>';
    }
}

