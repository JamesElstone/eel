<!--
  EEL Accounts
  Copyright (c) 2026 James Elstone
  Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
  See LICENSE file for details.
-->
# eel

**eel** is an open-source bookkeeping and corporation tax preparation tool designed for small UK companies.

Its goal is simple:

> Upload bank statements -> categorise transactions -> produce an iXBRL file for electronic CT600 submission to HMRC and Companies House Web Filing.

---

## Overview

eel turns raw bank statement data into structured financial outputs suitable for statutory reporting.

Instead of relying on bank APIs, eel works with **CSV statement uploads**, making it simple, portable, and fully self-hosted.

eel now uses **eelKit** as its upstream application framework. eelKit provides the shared page, card, action, service, authentication, rendering, configuration, and AJAX plumbing. eel keeps the accounting-specific pages, cards, services, repositories, database schema, migrations, and selected company/accounting period context.

---

## Company Requirements

To use this system, the company must meet the following criteria:

- Be registered with Companies House and have a valid company registration number
- Be active on Companies House (not dissolved or dormant)
- Have a valid UTR (Unique Taxpayer Reference) issued by HMRC
- Not be VAT registered and remain below VAT registration thresholds
- Have (free) developer API access configured for:
  - HMRC
  - Companies House

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
- `tax_years` -> accounting periods

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

See `LICENSE` for the licence index and file-level notices for the licence that applies to each file.

Separate project, support, hosting, consultancy, or commercial terms are set out in `TERMS.md`.

### Third-Party Assets

This project uses the Inter and Roboto fonts, licensed under the SIL Open Font License 1.1 (OFL). The font files and their licences are included in `web_root/fonts`.

---

## Disclaimer

This software is provided "as is", without warranty of any kind.

It is not accounting or tax advice. Users are responsible for verifying outputs and ensuring compliance with HMRC requirements.

> This project is currently in **alpha**. Expect rough edges and ongoing changes.
