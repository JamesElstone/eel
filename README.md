<!--
  EEL Accounts
  Copyright (c) 2026 James Elstone
  Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
  See LICENSE file for details.
-->
# eel

**eel** is an open-source bookkeeping and corporation tax preparation tool designed for UK micro-entity companies preparing accounts under FRS 105.

Its goal is simple:

> Upload bank statements -> categorise transactions -> produce an iXBRL file for electronic CT600 submission to HMRC and Companies House Web Filing.

## Requirements

eel turns raw bank statement data into structured financial outputs suitable for FRS 105 micro-entity statutory reporting.

Instead of relying on bank APIs, eel works with **CSV statement uploads**, making it simple, portable, and fully self-hosted.

eel now uses **eelKit** as its upstream application framework. eelKit provides the shared page, card, action, service, authentication, rendering, configuration, and AJAX plumbing. eel keeps the accounting-specific pages, cards, services, repositories, database schema, migrations, and selected company/accounting period context.

---

## Required Company Scope

Supported Company Scope

EEL Accounts is currently designed for UK micro-entity companies with straightforward Corporation Tax affairs. To generate statutory accounts and Corporation Tax submissions, the company must meet all of the following requirements:

- Be registered with Companies House and have a valid company registration number.
- Be active on the Companies House register.
- Qualify as a micro-entity and prepare statutory accounts under FRS 105, the Financial Reporting Standard applicable to the Micro-entities Regime. This generally means meeting at least two of the following thresholds:
   - Annual turnover not exceeding GBP 1,000,000
   - Balance sheet total not exceeding GBP 500,000
   - An average of 10 or fewer employees
   - Have a valid HMRC Unique Taxpayer Reference (UTR).
   - Not be VAT registered.
   - Not require statutory disclosures that are not currently supported by EEL Accounts, including financial commitments, guarantees or contingent liabilities. Where unsupported disclosures are identified, statutory accounts generation and Corporation Tax filing are disabled. Director's loan disclosures are generated automatically from the chronological Director Loan Statement.
   - Have Companies House and HMRC developer credentials configured to enable electronic filing.

**EEL Accounts continuously validates whether a company remains within its supported scope. If the company falls outside that scope, statutory accounts generation and electronic filing are disabled rather than producing filings that may be incomplete or inaccurate. The software provides a clear explanation of the unsupported condition and the filing features that have been disabled, allowing the issue to be resolved before filing.**

For details of the types of companies supported by EEL Accounts, see the [Supported Company Scope](SUPPORTED_COMPANY_SCOPE.md).

---

## Core Workflow

1. **Upload bank statements (CSV)**
   - Monthly files
   - Stored and parsed into a structured database

2. **Deduplicate transactions**
   - Prevents duplicate imports if files are uploaded twice
   - Uses row-level hashing for safety

3. **Categorise transactions**
   - Assign each transaction to a nominal account
   - Supports:
     - Auto-rules (pattern matching)
     - Manual categorisation

4. **Build the ledger**
   - Transactions are converted into double-entry journals
   - Supports multiple sources:
     - Bank CSV imports
     - Director's loan entries
     - Expense claims
     - Manual journals

5. **Generate financial outputs**
   - Trial Balance
   - Profit & Loss
   - Balance Sheet

6. **Produce iXBRL**
   - Structured output suitable for CT600 submission

---

## Key Features

- CSV-based workflow (no bank API dependency)
- Transaction deduplication (file + row level)
- Rule-based categorisation engine
- Manual override with audit trail
- Double-entry accounting model
- Director's Loan support
- Expense claim support
- Fixed asset depreciation support; amortisation is not currently modelled separately and can be considered later if a real use case appears
- Tax year/accounting period management
- iXBRL generation pipeline

---

## Architecture

- **Backend:** PHP
- **Database:** MariaDB
- **Framework:** eelKit
- **Frontend:** Web UI (upload, categorise, review)
- **API:** Self-hosted REST endpoints

The system is built around a central ledger model, with multiple input sources feeding into journals and nominal accounts.

---

## Data Model Highlights

- `statement_uploads` -> tracks uploaded files
- `statement_import_rows` -> raw parsed data
- `transactions` -> deduplicated records
- `journals` + `journal_lines` -> double-entry ledger
- `nominal_accounts` -> chart of accounts
- `categorisation_rules` -> automation layer
- `accounting_periods` -> accounting periods

This structure enables a clean progression from raw data to statutory outputs.

---

## Project Goal

To provide a transparent, developer-friendly alternative to traditional accounting software by:

- Keeping full control of financial data
- Avoiding vendor lock-in
- Making tax logic explicit and inspectable
- Producing compliant outputs for HMRC submission

---

## Upstream eelKit

eelKit is maintained separately and imported into this repository as the upstream framework. Framework-level changes should be made in the eelKit project first, then merged back into eel.

eel should keep accounting behaviour in app-owned services, repositories, pages, cards, actions, schema, and migrations. Generic framework behaviour belongs in eelKit.

---

## License

This repository is mixed-licensed.

- EEL Accounts application-specific files are licensed under the GNU Affero General Public License v3.0 (AGPLv3).
- eelKit framework files are licensed under the BSD 3-Clause License.
- Bundled font files are licensed under the SIL Open Font License 1.1 (OFL).
- Arelle integration files live under `third_party/arelle/`; Arelle itself is Apache-2.0 licensed and is installed locally into git-ignored runtime folders when needed.

See `LICENSE` for the licence index and file-level notices for the licence that applies to each file.

Separate project, support, hosting, consultancy, or commercial terms are set out in `TERMS.md`.

### Third-Party Assets

This project uses the Inter and Roboto fonts, licensed under the SIL Open Font License 1.1 (OFL). The font files and their licences are included in `web_root/fonts`.

The optional Arelle iXBRL validator integration is kept under `third_party/arelle/` to make the third-party licence boundary explicit. Run `third_party\arelle\bin\install_arelle.bat` on Windows to create a local validator runtime.

---

## Disclaimer

This software is provided "as is", without warranty of any kind.

This software is not accounting, tax, legal, or professional advice. Users are responsible for independently verifying all outputs and for ensuring compliance with all applicable HMRC requirements before relying on, filing, or submitting any information produced by the software.

This software is not authorised, endorsed, certified, or approved by HMRC. It has not yet been approved through a successful live submission to HMRC and should therefore be treated as early-stage software under active development. No representation is made that its outputs will be accepted by HMRC, Companies House, or any other authority.

> This project is currently in **alpha**. Expect rough edges and ongoing changes.
