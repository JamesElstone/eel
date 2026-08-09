<?php
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Contract\CharityRegistryClientInterface;

final class CharitableDonationService
{
    public const NOMINAL_CODE = '6160';
    public const SUBTYPE_CODE = 'charitable_donations';

    /** @var array<string,CharityRegistryClientInterface> */
    private array $clients;

    /** @param array<string,CharityRegistryClientInterface>|null $clients */
    public function __construct(?array $clients = null)
    {
        $this->clients = $clients ?? [
            'cc_ew' => new \eel_accounts\Client\CharityCommissionRegistryClient(),
            'oscr' => new \eel_accounts\Client\OscrCharityRegistryClient(),
            'ccni' => new \eel_accounts\Client\CcniCharityRegistryClient(),
        ];
    }

    public function nominalAccountId(): int
    {
        return (int)(\InterfaceDB::fetchColumn(
            'SELECT na.id FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.code = :code AND nas.code = :subtype AND na.is_active = 1 LIMIT 1',
            ['code' => self::NOMINAL_CODE, 'subtype' => self::SUBTYPE_CODE]
        ) ?: 0);
    }

    public function isDonationNominal(?int $nominalAccountId): bool
    {
        if (($nominalAccountId ?? 0) <= 0) return false;
        return (int)(\InterfaceDB::fetchColumn(
            'SELECT EXISTS(SELECT 1 FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id AND na.code = :code AND nas.code = :subtype)',
            ['id' => $nominalAccountId, 'code' => self::NOMINAL_CODE, 'subtype' => self::SUBTYPE_CODE]
        ) ?: 0) === 1;
    }

    /** @return list<array<string,mixed>> */
    public function withoutDonationNominal(array $nominals): array
    {
        return array_values(array_filter($nominals, fn(mixed $row): bool =>
            !is_array($row) || !$this->isDonationRow($row)
        ));
    }

    public function isDonationRow(array $row): bool
    {
        return (string)($row['code'] ?? '') === self::NOMINAL_CODE
            && (string)($row['subtype_code'] ?? $row['account_subtype_code'] ?? self::SUBTYPE_CODE) === self::SUBTYPE_CODE;
    }

    /** @return array{success:bool,records:list<array<string,mixed>>,errors:list<string>,response_sha256:string} */
    public function lookup(string $authority, string $registrationNumber): array
    {
        $authority = strtolower(trim($authority));
        $client = $this->clients[$authority] ?? null;
        if (!$client instanceof CharityRegistryClientInterface) {
            return ['success' => false, 'records' => [], 'errors' => ['Choose a supported UK charity register.'], 'response_sha256' => ''];
        }
        return $client->lookup($registrationNumber);
    }

    /** @return list<string> */
    public function transactionEligibilityErrors(array $transaction, int $nominalAccountId): array
    {
        if (!$this->isDonationNominal($nominalAccountId)) return [];
        $errors = [];
        if ((string)($transaction['source_account_type'] ?? '') !== CompanyAccountService::TYPE_BANK) {
            $errors[] = 'Charitable Donations can only be used for a bank-account transaction.';
        }
        if ((float)($transaction['amount'] ?? 0) >= 0) {
            $errors[] = 'Charitable Donations can only be used for an outgoing bank transaction.';
        }
        if ((int)($transaction['has_transaction_split'] ?? 0) === 1 || $this->transactionHasSplit((int)($transaction['id'] ?? 0))) {
            $errors[] = 'Charitable Donations cannot be used on a split bank transaction.';
        }
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1 || (int)($transaction['transfer_account_id'] ?? 0) > 0) {
            $errors[] = 'An inter-account transfer cannot be a charitable donation.';
        }
        return $errors;
    }

    /** @return array{success:bool,record?:array<string,mixed>,errors:list<string>,response_sha256:string} */
    public function verifyTransaction(array $transaction, int $nominalAccountId, string $authority, string $registrationNumber, string $entitySuffix = ''): array
    {
        $errors = $this->transactionEligibilityErrors($transaction, $nominalAccountId);
        if ($errors !== []) return ['success' => false, 'errors' => $errors, 'response_sha256' => ''];
        $lookup = $this->lookup($authority, $registrationNumber);
        if (empty($lookup['success'])) return ['success' => false, 'errors' => (array)$lookup['errors'], 'response_sha256' => ''];

        $records = (array)$lookup['records'];
        if (count($records) > 1 && trim($entitySuffix) === '') {
            return ['success' => false, 'errors' => ['This number has linked registered entities. Choose the exact charity entity and verify again.'], 'response_sha256' => (string)$lookup['response_sha256'], 'records' => $records];
        }
        $record = null;
        foreach ($records as $candidate) {
            if (!is_array($candidate)) continue;
            if (count($records) === 1 || (string)($candidate['entity_suffix'] ?? '') === trim($entitySuffix)) {
                $record = $candidate; break;
            }
        }
        if (!is_array($record)) return ['success' => false, 'errors' => ['Choose a valid linked charity entity.'], 'response_sha256' => (string)$lookup['response_sha256'], 'records' => $records];

        $date = (string)($transaction['txn_date'] ?? '');
        $registeredOn = (string)($record['registered_on'] ?? '');
        $removedOn = (string)($record['removed_on'] ?? '');
        if ($registeredOn !== '' && $registeredOn > $date) $errors[] = 'The charity was not registered on the transaction date.';
        if ($removedOn !== '' && $removedOn <= $date) $errors[] = 'The charity was no longer registered on the transaction date.';
        $status = strtolower(trim((string)($record['registry_status'] ?? '')));
        if ($removedOn === '' && (str_contains($status, 'removed') || str_contains($status, 'ceased'))) {
            $errors[] = 'The register does not establish that this charity was active on the transaction date.';
        }
        return ['success' => $errors === [], 'record' => $record, 'errors' => $errors, 'response_sha256' => (string)$lookup['response_sha256']];
    }

    public function recordVerification(array $transaction, int $nominalAccountId, array $record, string $responseSha256, string $verifiedBy): int
    {
        if (!\InterfaceDB::tableExists('transaction_charitable_donation_verifications')) {
            throw new \RuntimeException('Run the registered charitable donations migration before verifying donations.');
        }
        $basis = $this->basisHash($transaction, $nominalAccountId);
        \InterfaceDB::prepare(
            'INSERT INTO transaction_charitable_donation_verifications (
                company_id, accounting_period_id, transaction_id, authority, registration_number,
                entity_suffix, registered_name, registry_status, registered_on, removed_on,
                source_url, verified_by, response_sha256, basis_sha256
             ) VALUES (
                :company_id, :accounting_period_id, :transaction_id, :authority, :registration_number,
                :entity_suffix, :registered_name, :registry_status, :registered_on, :removed_on,
                :source_url, :verified_by, :response_sha256, :basis_sha256
             )'
        )->execute([
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'transaction_id' => (int)$transaction['id'],
            'authority' => (string)$record['authority'],
            'registration_number' => (string)$record['registration_number'],
            'entity_suffix' => (string)($record['entity_suffix'] ?? ''),
            'registered_name' => (string)$record['registered_name'],
            'registry_status' => (string)$record['registry_status'],
            'registered_on' => $record['registered_on'] ?? null,
            'removed_on' => $record['removed_on'] ?? null,
            'source_url' => (string)$record['source_url'],
            'verified_by' => substr(trim($verifiedBy) !== '' ? trim($verifiedBy) : 'system', 0, 100),
            'response_sha256' => preg_match('/^[a-f0-9]{64}$/', $responseSha256) === 1 ? $responseSha256 : hash('sha256', $responseSha256),
            'basis_sha256' => $basis,
        ]);
        return (int)(\InterfaceDB::fetchColumn(
            strtolower(\InterfaceDB::driverName()) === 'sqlite'
                ? 'SELECT last_insert_rowid()'
                : 'SELECT LAST_INSERT_ID()'
        ) ?: 0);
    }

    public function currentVerification(int $transactionId): ?array
    {
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('transaction_charitable_donation_verifications')) return null;
        $transaction = (new TransactionCategorisationService())->fetchTransaction($transactionId);
        if (!is_array($transaction) || !$this->isDonationNominal((int)($transaction['nominal_account_id'] ?? 0))) return null;
        return $this->verificationForBasis($transaction, (int)$transaction['nominal_account_id']);
    }

    public function verificationForBasis(array $transaction, int $nominalAccountId): ?array
    {
        $transactionId = (int)($transaction['id'] ?? 0);
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('transaction_charitable_donation_verifications')) return null;
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM transaction_charitable_donation_verifications WHERE transaction_id = :transaction_id ORDER BY id DESC LIMIT 1',
            ['transaction_id' => $transactionId]
        );
        if (!is_array($row) || !hash_equals((string)$row['basis_sha256'], $this->basisHash($transaction, $nominalAccountId))) return null;
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchCurrentForPeriod(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('transaction_charitable_donation_verifications')) return [];
        $rows = \InterfaceDB::fetchAll(
            'SELECT t.id AS transaction_id FROM transactions t WHERE t.company_id = :company_id AND t.accounting_period_id = :accounting_period_id AND t.nominal_account_id = :nominal_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'nominal_id' => $this->nominalAccountId()]
        ) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $current = $this->currentVerification((int)$row['transaction_id']);
            if ($current !== null) $result[(int)$row['transaction_id']] = $current;
        }
        return $result;
    }

    /** @return array{total:float,rows:list<array<string,mixed>>} */
    public function qualifyingPaidForPeriod(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        $verifications = $this->fetchCurrentForPeriod($companyId, $accountingPeriodId);
        if ($verifications === []) return ['total' => 0.0, 'rows' => []];
        $rows = [];
        $total = 0.0;
        foreach ($verifications as $transactionId => $verification) {
            $transaction = (new TransactionCategorisationService())->fetchTransaction((int)$transactionId);
            $date = (string)($transaction['txn_date'] ?? '');
            if (!is_array($transaction) || $date < $periodStart || $date > $periodEnd) continue;
            $posted = (int)(\InterfaceDB::fetchColumn(
                'SELECT EXISTS(SELECT 1 FROM journals WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND source_type = :source_type AND source_ref = :source_ref AND is_posted = 1)',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$transactionId]
            ) ?: 0) === 1;
            if (!$posted) continue;
            $amount = round(abs((float)$transaction['amount']), 2);
            $total = round($total + $amount, 2);
            $rows[] = array_merge($verification, ['transaction_id' => (int)$transactionId, 'txn_date' => $date, 'amount' => $amount]);
        }
        return ['total' => $total, 'rows' => $rows];
    }

    public function basisHash(array $transaction, int $nominalAccountId): string
    {
        return hash('sha256', \eel_accounts\Support\Utf8::json([
            'transaction_id' => (int)($transaction['id'] ?? 0),
            'company_id' => (int)($transaction['company_id'] ?? 0),
            'accounting_period_id' => (int)($transaction['accounting_period_id'] ?? 0),
            'txn_date' => (string)($transaction['txn_date'] ?? ''),
            'amount' => number_format((float)($transaction['amount'] ?? 0), 2, '.', ''),
            'nominal_account_id' => $nominalAccountId,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function transactionHasSplit(int $transactionId): bool
    {
        if ($transactionId <= 0 || !\InterfaceDB::tableExists('transaction_splits')) return false;
        return (int)(\InterfaceDB::fetchColumn('SELECT EXISTS(SELECT 1 FROM transaction_splits WHERE transaction_id = :id)', ['id' => $transactionId]) ?: 0) === 1;
    }
}
