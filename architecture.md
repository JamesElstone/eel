# EEL Architecture

## 1. System Overview

EEL is a bookkeeping and corporation tax preparation system for small UK limited companies preparing FRS 105 micro-entity accounts.

Its core job is to convert multiple financial input sources into a unified, auditable, double-entry ledger. Reports, trial balance, FRS 105 micro-entity statutory accounts, iXBRL preview/output, and CT600 outputs should be derived from that ledger.

The bank feed is not the source of truth. The ledger is.

---

## 2. Core Data Flow

```text
Input sources
    ↓
Staging / validation
    ↓
Transactions or register entries
    ↓
Journals
    ↓
Journal lines
    ↓
Trial balance / reports / accounts / tax outputs
```

Typical bank CSV flow:

```text
statement_uploads
    ↓
statement_import_mappings
    ↓
statement_import_rows
    ↓
transactions
    ↓
journals
    ↓
journal_lines
```

All financial sources must eventually resolve into balanced journal entries.

---

## 3. Input Sources

The system supports more than bank CSV imports.

Current source types:

- `bank_csv`
- `director_loan_register`
- `expense_register`
- `manual`

### bank_csv

Imported bank statement data, currently from ANNA Money CSV files.

Bank CSV rows represent transactions that touched the company bank account.

### director_loan_register

Tracks money introduced to or withdrawn from the business by the director.

Examples:

```text
Director pays money into business bank:
Dr Bank
Cr Director Loan Liability

Company repays director:
Dr Director Loan Liability
Cr Bank
```

### expense_register

Tracks business costs personally paid by the director.

These may not appear in bank CSV data until later repayment.

Example:

```text
Director personally buys tools for the company:
Dr Tools & Small Equipment
Cr Director Loan Liability
```

### manual

Manual journals for corrections, adjustments, accruals, corporation tax, opening balances, or exceptional cases.

Manual entries must still be auditable and balanced.

---

## 4. Ledger Model

The ledger is the accounting source of truth.

Core tables:

- `journals`
- `journal_lines`
- `nominal_accounts`
- `nominal_account_subtypes`

A journal represents one accounting event.

A journal line represents one debit or credit posting to a nominal account.

Every journal must balance:

```text
Total debits = total credits
```

Example bank purchase:

```text
Dr Materials
Cr Bank
```

Example sale:

```text
Dr Bank
Cr Sales
```

Example personally paid expense:

```text
Dr Tools & Small Equipment
Cr Director Loan Liability
```

---

## 5. Nominal Accounts

Nominal accounts classify ledger postings.

Top-level account types:

- `asset`
- `liability`
- `income`
- `cost_of_sales`
- `expense`
- `equity`

Examples:

- `1000 Bank`
- `2000 VAT Control`
- `2100 Director Loan Liability`
- `4000 Sales`
- `5000 Materials`
- `6070 Tools & Small Equipment`
- `9999 Uncategorised`

Do not duplicate nominal account logic outside shared services or repositories.

---

## 6. Transactions

The `transactions` table stores imported bank statement transactions.

Transactions are not the final accounting truth by themselves. They are source data that must be categorised and posted into journals.

Important transaction fields:

- `company_id`
- `accounting_period_id`
- `statement_upload_id`
- `account_id`
- `txn_date`
- `description`
- `amount`
- `dedupe_hash`
- `nominal_account_id`
- `category_status`

A categorised transaction should be capable of producing a journal.

---

## 7. Staging and Import

Bank CSV imports should not go directly into final transactions.

Upload pipeline:

```text
statement_uploads
    ↓
statement_import_mappings
    ↓
statement_import_rows
    ↓
transactions
```

### statement_uploads

Tracks uploaded files.

Important fields:

- `company_id`
- `accounting_period_id`
- `account_id`
- `statement_month`
- `file_sha256`
- `workflow_status`

### statement_import_rows

Stores parsed rows before commit.

Important fields:

- `raw_json`
- `chosen_txn_date`
- `normalised_description`
- `normalised_amount`
- `row_hash`
- `validation_status`
- `is_duplicate_within_upload`
- `is_duplicate_existing`
- `committed_transaction_id`

Staging exists so imports can be mapped, validated, reviewed, and safely committed.

---

## 8. Deduplication

Duplicate financial data is dangerous. The system uses both file-level and row-level deduplication.

### File-level deduplication

`statement_uploads.file_sha256` stores a SHA-256 hash of the uploaded file.

Constraint:

```text
UNIQUE(company_id, file_sha256)
```

This prevents the exact same file being uploaded twice for the same company.

### Row-level deduplication

`statement_import_rows.row_hash` identifies duplicate staged rows.

`transactions.dedupe_hash` identifies committed duplicate transactions.

Constraint:

```text
UNIQUE(company_id, dedupe_hash)
```

Dedupe hashes should be deterministic and based on stable source fields such as:

- company
- account
- date
- amount
- description
- reference
- counterparty
- card

Never use database IDs or timestamps in dedupe hashes.

---

## 9. Categorisation

Categorisation assigns imported transactions to nominal accounts.

Core table:

- `categorisation_rules`

A rule can match fields such as:

- description
- reference
- name
- type
- card
- any

Supported match types:

- contains
- equals
- starts_with
- regex

Rules have priority. Lower or earlier priority should be evaluated before fallback behaviour.

Categorisation status:

- `uncategorised`
- `auto`
- `manual`

Manual categorisation should be audited.

Core audit table:

- `transaction_category_audit`

Uncategorised transactions should remain visible until resolved.

---

## 10. Journals From Bank Transactions

A bank transaction creates one side of the double entry automatically. The selected nominal account creates the other side.

Negative bank amount:

```text
Dr Expense / Asset / Liability
Cr Bank
```

Positive bank amount:

```text
Dr Bank
Cr Income / Liability / Asset
```

Examples:

Bank charge:

```text
Dr Bank Charges
Cr Bank
```

Sales receipt:

```text
Dr Bank
Cr Sales
```

Director repayment by company:

```text
Dr Director Loan Liability
Cr Bank
```

Director introduces money to company:

```text
Dr Bank
Cr Director Loan Liability
```

---

## 11. Director Loan and Expense Claims

The system distinguishes source registers from final accounting treatment.

Director loan register and expense register may both post to `Director Loan Liability`.

This allows separate operational tracking while keeping the accounts simpler.

Examples:

Personal business expense:

```text
Dr Relevant Expense
Cr Director Loan Liability
```

Repayment through bank:

```text
Dr Director Loan Liability
Cr Bank
```

Avoid duplicating repayments if they already exist in bank CSV data. Register entries should be matched to bank transactions where appropriate.

---

## 12. Accounting Periods and CT Periods

The `accounting_periods` table currently stores accounting periods, not necessarily statutory accounting periods.

Important rule:

```text
accounting_periods = accounting periods
```

Corporation Tax periods are derived from confirmed accounting periods.

Never assume suggested accounting periods are legally confirmed.

Accounting periods may need to be split into multiple CT periods where HMRC rules require it.

---

## 13. Companies House Data

Companies House data is imported and normalised separately from internal bookkeeping data.

Core tables:

- `companies_house_documents`
- `companies_house_document_contexts`
- `companies_house_taxonomy_concepts`
- `companies_house_document_facts`

Companies House facts are useful for comparison, prior-year data, validation, and accounts extraction.

They should not silently overwrite internal ledger data.

---

## 14. Settings

Company-specific settings live in `company_settings`.

Examples:

- `default_currency`
- `default_bank_nominal_id`
- `director_loan_nominal_id`
- `vat_nominal_id`
- `uncategorised_nominal_id`
- `uploads_path`
- `enable_duplicate_file_check`
- `enable_duplicate_row_check`
- `lock_posted_periods`

Settings should be read through shared services or repositories, not duplicated in page or card logic.

---

## 15. UI Architecture

The app is moving toward a page/card architecture.

Pages orchestrate context.

Cards render reusable UI sections.

Business logic should not live in cards or views.

Cards may define the data contracts they need. Services should resolve those contracts and add the results to the context passed to the card.

Shared AJAX handling should transport requests and responses, not interpret accounting rules.

---

## 16. Service Architecture

Business logic belongs in services.

Repositories should handle data access.

Views and cards should render already-prepared data.

Preferred layering:

```text
UI / Card / Page
    ↓
Service
    ↓
Repository
    ↓
Database
```

Do not create parallel implementations of existing accounting logic.

Search for existing services before adding new ones.

---

## 17. Auditability

Accounting data must remain traceable.

The system should preserve:

- uploaded source file
- staged source row
- committed transaction
- categorisation changes
- journal entry
- journal lines
- source references

No silent mutation of historical accounting data.

Corrections should be made through auditable changes, reversals, or adjustment journals.

---

## 18. Invariants

These rules protect the accounting engine:

- Ledger is the source of truth
- Every journal must balance
- Every financial output must be derived from ledger data or clearly marked external reference data
- Imported source data must remain traceable
- Historical data must not be destructively changed without explicit approval
- SQL must preserve referential integrity
- CT periods are derived from confirmed accounting periods
- Bank CSV is only one input source, not the full accounting record

---

## 19. What EEL Is Not

EEL is not:

- a live bank-feed system
- single-entry bookkeeping
- a spreadsheet replacement
- a system where bank transactions alone define the accounts
- a tool that silently rewrites accounting history

EEL is a ledger-first bookkeeping system with import tools feeding an auditable accounting model.
