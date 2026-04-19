<?php
declare(strict_types=1);

final class CompanyDataResetService
{
    public function __construct() {
    }

    public function clearImportedAccountingData(int $companyId, string $confirmationValue, ?string $actor = null): array {
        $company = $this->fetchCompany($companyId);

        if ($company === null) {
            return [
                'success' => false,
                'errors' => ['The selected company could not be found.'],
                'counts' => [],
            ];
        }

        $expectedCompanyNumber = trim((string)($company['company_number'] ?? ''));

        if (trim($confirmationValue) !== $expectedCompanyNumber) {
            return [
                'success' => false,
                'errors' => ['Confirmation failed. Type the exact company number before deleting imported accounting data.'],
                'counts' => [],
            ];
        }

        $uploadIds = $this->fetchIds(
            'SELECT id
             FROM statement_uploads
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        $transactionIds = $this->fetchIds(
            'SELECT id
             FROM transactions
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        $journalIds = $this->fetchIds(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        $expenseClaimIds = $this->fetchIds(
            'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        $expenseClaimantIds = $this->fetchIds(
            'SELECT id
             FROM expense_claimants
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        $counts = [
            'expense_claim_lines' => $this->countRowsByIds('expense_claim_lines', 'expense_claim_id', $expenseClaimIds),
            'expense_claim_payment_links' => $this->countRowsByIds('expense_claim_payment_links', 'expense_claim_id', $expenseClaimIds),
            'expense_claimants' => count($expenseClaimantIds),
            'expense_claims' => count($expenseClaimIds),
            'statement_import_mappings' => $this->countRowsByIds('statement_import_mappings', 'upload_id', $uploadIds),
            'statement_import_rows' => $this->countRowsByIds('statement_import_rows', 'upload_id', $uploadIds),
            'statement_uploads' => count($uploadIds),
            'transaction_category_audit' => $this->countRowsByIds('transaction_category_audit', 'transaction_id', $transactionIds),
            'transactions' => count($transactionIds),
            'journal_lines' => $this->countRowsByIds('journal_lines', 'journal_id', $journalIds),
            'journals' => count($journalIds),
        ];

        $ownsTransaction = !InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            $this->deleteRowsByIds('expense_claim_payment_links', 'expense_claim_id', $expenseClaimIds);
            $this->deleteRowsByIds('expense_claim_lines', 'expense_claim_id', $expenseClaimIds);
            $this->deleteRowsByIds('expense_claims', 'id', $expenseClaimIds);
            $this->deleteRowsByIds('expense_claimants', 'id', $expenseClaimantIds);
            $this->deleteRowsByIds('journal_lines', 'journal_id', $journalIds);
            $this->deleteRowsByIds('journals', 'id', $journalIds);
            $this->deleteRowsByIds('transaction_category_audit', 'transaction_id', $transactionIds);
            $this->deleteRowsByIds('statement_import_rows', 'upload_id', $uploadIds);
            $this->deleteRowsByIds('statement_import_mappings', 'upload_id', $uploadIds);
            $this->deleteRowsByIds('transactions', 'id', $transactionIds);
            $this->deleteRowsByIds('statement_uploads', 'id', $uploadIds);

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['Imported accounting data could not be cleared: ' . $exception->getMessage()],
                'counts' => $counts,
            ];
        }

        error_log('[company_data_clear_down] ' . json_encode([
            'company_id' => $companyId,
            'company_number' => $expectedCompanyNumber,
            'actor' => $actor !== null && trim($actor) !== '' ? trim($actor) : 'unknown',
            'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'counts' => $counts,
        ], JSON_UNESCAPED_SLASHES));

        return [
            'success' => true,
            'errors' => [],
            'counts' => $counts,
            'company' => $company,
        ];
    }

    private function fetchCompany(int $companyId): ?array {
        if ($companyId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne( 'SELECT id,
                    company_name,
                    company_number
             FROM companies
             WHERE id = :company_id
             LIMIT 1', ['company_id' => $companyId]);

        return is_array($row) ? $row : null;
    }

    private function fetchIds(string $sql, array $params): array {
        return array_map('intval', InterfaceDB::prepareExecute( $sql, $params)->fetchAll(PDO::FETCH_COLUMN));
    }

    private function countRowsByIds(string $table, string $column, array $ids): int {
        if ($ids === []) {
            return 0;
        }

        $count = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $count += InterfaceDB::countIn($table, $column, $chunk);
        }

        return $count;
    }

    private function deleteRowsByIds(string $table, string $column, array $ids): void {
        if ($ids === []) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $stmt = InterfaceDB::prepare(
                'DELETE FROM ' . $table . '
                 WHERE ' . $column . ' IN (' . $placeholders . ')'
            );
            $stmt->execute($chunk);
        }
    }
}


