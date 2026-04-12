# eel

**eel** is an open-source bookkeeping and corporation tax preparation tool designed for small UK companies.

Its goal is simple:

> Upload bank statements → categorise transactions → produce an iXBRL file for electronic CT600 submission to HMRC and Companies House Web Filling.

---

## Overview

eel turns raw bank statement data into structured financial outputs suitable for statutory reporting.

Instead of relying on bank APIs, eel works with **CSV statement uploads** (e.g. from Anna Money), making it simple, portable, and fully self-hosted.

---

## Core Workflow

1. **Upload bank statements (CSV)**
   - Monthly files (e.g. 12 per year)
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
     - Director’s loan entries
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
- Director’s Loan support
- Expense claim support
- Tax year management
- iXBRL generation pipeline

---

## Architecture

- **Backend:** PHP
- **Database:** MariaDB
- **Frontend:** Web UI (upload, categorise, review)
- **API:** Self-hosted REST endpoints

The system is built around a central ledger model, with multiple input sources feeding into journals and nominal accounts.

---

## Data Model Highlights

- `statement_uploads` → tracks uploaded files  
- `statement_import_rows` → raw parsed data  
- `transactions` → deduplicated records  
- `journals` + `journal_lines` → double-entry ledger  
- `nominal_accounts` → chart of accounts  
- `categorisation_rules` → automation layer  
- `tax_years` → accounting periods  

This structure enables a clean progression from raw data to statutory outputs.

---

## Project Goal

To provide a transparent, developer-friendly alternative to traditional accounting software by:

- Keeping full control of financial data
- Avoiding vendor lock-in
- Making tax logic explicit and inspectable
- Producing compliant outputs for HMRC submission

---

## License

This project is open source and free to use.

## Disclaimer

This software is provided “as is”, without warranty of any kind.

It is not accounting or tax advice. Users are responsible for verifying outputs and ensuring compliance with HMRC requirements.
> This project is currently in **alpha**. Expect rough edges and ongoing changes. 