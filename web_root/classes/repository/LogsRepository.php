<?php
declare(strict_types=1);

final class LogsRepository
{
    public function fetchRecentLogonHistory(int $limit = 100): array
    {
        if (!InterfaceDB::tableExists('user_logon_history')) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT
                history.id,
                history.user_id,
                history.attempted_email_address,
                history.event_type,
                history.success,
                history.reason,
                history.session_token_hash,
                history.device_id,
                history.ip_address,
                history.user_agent,
                history.browser_label,
                history.occurred_at,
                COALESCE(users.display_name, \'\') AS user_display_name
             FROM user_logon_history history
             LEFT JOIN users
                ON users.id = history.user_id
             ORDER BY history.occurred_at DESC, history.id DESC
             LIMIT ' . $this->normaliseLimit($limit)
        );
    }

    public function fetchRecentTransactionCategoryAudit(int $limit = 100): array
    {
        if (!InterfaceDB::tableExists('transaction_category_audit')) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.transaction_id,
                audit.old_nominal_account_id,
                audit.new_nominal_account_id,
                audit.old_category_status,
                audit.new_category_status,
                audit.old_auto_rule_id,
                audit.new_auto_rule_id,
                audit.old_is_auto_excluded,
                audit.new_is_auto_excluded,
                audit.changed_by,
                audit.changed_at,
                audit.reason,
                transactions.company_id,
                transactions.tax_year_id,
                transactions.txn_date,
                transactions.description AS transaction_description,
                transactions.amount AS transaction_amount,
                COALESCE(old_nominal.name, \'\') AS old_nominal_name,
                COALESCE(new_nominal.name, \'\') AS new_nominal_name
             FROM transaction_category_audit audit
             INNER JOIN transactions
                ON transactions.id = audit.transaction_id
             LEFT JOIN nominal_accounts old_nominal
                ON old_nominal.id = audit.old_nominal_account_id
             LEFT JOIN nominal_accounts new_nominal
                ON new_nominal.id = audit.new_nominal_account_id
             ORDER BY audit.changed_at DESC, audit.id DESC
             LIMIT ' . $this->normaliseLimit($limit)
        );
    }

    public function fetchRecentYearEndAudit(int $limit = 100): array
    {
        if (!InterfaceDB::tableExists('year_end_audit_log')) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.company_id,
                audit.tax_year_id,
                audit.action,
                audit.action_by,
                audit.action_at,
                audit.old_value_json,
                audit.new_value_json,
                audit.notes,
                COALESCE(companies.name, \'\') AS company_name,
                COALESCE(tax_years.period_start, NULL) AS tax_year_start,
                COALESCE(tax_years.period_end, NULL) AS tax_year_end
             FROM year_end_audit_log audit
             LEFT JOIN companies
                ON companies.id = audit.company_id
             LEFT JOIN tax_years
                ON tax_years.id = audit.tax_year_id
             ORDER BY audit.action_at DESC, audit.id DESC
             LIMIT ' . $this->normaliseLimit($limit)
        );
    }

    private function normaliseLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }
}
