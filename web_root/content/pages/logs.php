<?php
declare(strict_types=1);

final class _logs extends BasePageFramework
{
    private const LOG_LIMIT = 200;

    public function id(): string
    {
        return 'logs';
    }

    public function title(): string
    {
        return 'Logs';
    }

    public function subtitle(): string
    {
        return 'Review system audit and history activity recorded by the application.';
    }

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function services(): array
    {
        return [];
    }

    public function cards(): array
    {
        return [
            'user_account_audit_log',
            'user_logon_history_log',
            'transaction_category_audit_log',
            'year_end_audit_log',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $logsRepository = new LogsRepository();

        return [
            'page_id' => 'logs',
            'page_cards' => $this->cards(),
            'audit_rows' => (new UserHistoryStore())->fetchRecentAccountAudit(self::LOG_LIMIT),
            'logon_rows' => $logsRepository->fetchRecentLogonHistory(self::LOG_LIMIT),
            'transaction_audit_rows' => $logsRepository->fetchRecentTransactionCategoryAudit(self::LOG_LIMIT),
            'year_end_audit_rows' => $logsRepository->fetchRecentYearEndAudit(self::LOG_LIMIT),
        ];
    }
}
