<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'govtalk_transmission_history.php';

final class _govtalk_exchangesCard extends _govtalk_transmission_historyCard
{
    public function key(): string { return 'govtalk_exchanges'; }
    public function title(): string { return 'XML Exchange History'; }

    public function helper(array $context): string
    {
        return 'XML Exchange History shows all GovTalk exchanges for the selected company. Use the filters to narrow the results. Outbound XML may contain authentication details, so downloads are private, integrity-checked and never cached.';
    }

    public function services(): array
    {
        return [[
            'key' => 'govtalk_exchange_history',
            'service' => \eel_accounts\Service\GovTalkTransmissionHistoryService::class,
            'method' => 'exchangeHistory',
            'params' => [
                'companyId' => ':company.id',
                'authority' => ':govtalk_history.authority',
                'environment' => ':govtalk_history.environment',
                'conversationAuthority' => ':govtalk_history.conversation_authority',
                'conversationId' => ':govtalk_history.conversation_id',
            ],
        ]];
    }

    protected function additionalInvalidationFacts(): array
    {
        return [
            'companies.house.accounts.submission',
            'hmrc.ct600.submissions',
            'page.context',
            'govtalk.exchanges.selection',
        ];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '<div class="notice warning">Select a company and accounting period to review XML exchange history.</div>';
        }
        return $this->exchangeTable(
            $context,
            $companyId,
            (array)(($context['services'] ?? [])['govtalk_exchange_history'] ?? []),
            (array)($context['govtalk_history'] ?? [])
        );
    }
}
