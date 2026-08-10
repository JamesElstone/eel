<!--
  EEL Accounts
  Copyright (c) 2026 James Elstone
  Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
  See LICENSE file for details.
-->
# eel

**eel** is an open-source self hosted web based bookkeeping and corporation tax preparation tool designed for UK micro-entity companies preparing accounts under FRS 105.

Its goal is simple:

> Upload bank and trade supplier statements and expense claims -> categorise transactions -> produce statutory accounts - > Corporation Tax computations and the electronic filing to HMRC and Companies House.

## Documentation

- [Supported Company Scope](SUPPORTED_COMPANY_SCOPE.md)
- [FRC 2026 iXBRL Taxonomy Compatibility](IXBRL_TAXONOMY_COMPATIBILITY.md)
- [FRC 2026 Categorical and Dimensional Fact Strategy](IXBRL_CATEGORICAL_FACTS.md)
- [Software Quality Management](SOFTWARE_QUALITY_MANAGEMENT.md)
- [Mathematical Basis](MATHEMATIC_BASIS.md)
- [PHP Requirements](PHP_REQUIREMENTS.md)
- [Architecture](ARCHITECTURE.md)
- [Third Party API Credentials Required](CREDENTIALS_INFORMATION.md)
- [Licence and Third-Party Notices](LICENSE)
- [Terms](TERMS.md)

## Background

eel turns raw bank and trade supplier statements and expense claims into structured financial outputs suitable for FRS 105 micro-entity statutory reporting to both Companies House and HMRC, using a repeatable, evidence-based approach. Todate, it has been successfuly used to submit to Companies House (LIVE) and HMRC Corporation Tax (TEST).

eel is a self hosted web application with built in security and two factor authentication.

## CSV Input

Instead of relying on bank APIs, eel works with **CSV statement uploads**, making it simple, portable, and fully self-hosted.

As a minimum:
- Bank and Trade account transaction CSVs must have the following columns:
```csv
Date, Description, Amount, Balance
```

- Expense Claims can be entered manually, but can be imported using the format of:
```csv
Date, Description, Amount
```

---

## Supported Company Scope

EEL Accounts is currently designed for UK micro-entity companies with straightforward Corporation Tax affairs. To generate statutory accounts and Corporation Tax submissions, the company must meet all of the following requirements:

- Be registered with Companies House and have a valid company registration number.
- Be active on the Companies House register.
- Qualify as a micro-entity and prepare statutory accounts under FRS 105, the Financial Reporting Standard applicable to the Micro-entities Regime. This generally means meeting at least two of the following thresholds:
  - Annual turnover not exceeding **GBP 1,000,000**
  - Balance sheet total not exceeding **GBP 500,000**
  - An average of **10 or fewer employees**
- Have a valid HMRC Unique Taxpayer Reference (UTR), linked to an active Government Gateway ID registered for Corporation Tax Returns.
- Not be VAT registered.
- Not require statutory disclosures that are not currently supported by EEL Accounts, including financial commitments, guarantees or contingent liabilities. Where unsupported disclosures are identified, statutory accounts generation and Corporation Tax filing are disabled. Director's loan disclosures are generated automatically from the chronological Director Loan Statement.
- Have Companies House and HMRC developer credentials configured to enable electronic filing.
- Does not support HMRC Service Agent Accounts.

**EEL Accounts continuously validates whether a company remains within its supported scope. If the company falls outside that scope, statutory accounts generation and electronic filing are disabled rather than producing filings that may be incomplete or inaccurate. The software provides a clear explanation of the unsupported condition and the filing features that have been disabled, allowing the issue to be resolved before filing. If there is a scenario that is not supported that you would like to use, raise an issue and we will review if this can be implemented.**

For details of the types of companies supported by EEL Accounts, see the [Supported Company Scope](SUPPORTED_COMPANY_SCOPE.md).

   For MariaDB through ODBC, configure the named DSN itself with `CHARSET=utf8mb4` on both Windows and FreeBSD. After setup, run `tools/bin/dbUnicodeDiagnostic.sh` (or `tools\bat\dbUnicodeDiagnostic.bat` on Windows) to verify parameterised Unicode and JSON values round-trip byte-for-byte. A successful round trip is the authoritative check for named DSNs because PDO cannot read their ODBC `CHARSET` setting directly.

5. Run the database setup tool. It makes sure `secure/app.php` exists, configures the database if needed, runs migrations, loads the baseline schema first if the configured database has no eelKit application tables, and then refreshes the external IP setting:

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
   - Tax owed to HMRC
   - Statutory Accounts generation

6. **Produce iXBRL**
   - Structured output suitable for CT600 submission

---

## Key Features

- CSV-based workflow (no bank API dependency)
- Transaction deduplication (file + row level)
- Rule-based categorisation engine
- Manual override with audit trail
- Double-entry accounting model journal
- Director's Loan support (s455, s464A and 464C support)
- Prepayments over an arbitrary timespan crossing accounting periods
- Charitable Donations
- Company owned Vehicles
- Dividends claimed
- Expense claim support
- Fixed asset depreciation support; amortisation is not currently modelled separately and can be considered later if a real use case appears
- Tax year/accounting period management
- iXBRL, XML and GovTalk generation pipeline

---

## Architecture

- **OS:** Windows or FreeBSD
- **Backend:** PHP
- **Database:** MariaDB
- **Framework:** eelKit (included)
- **Frontend:** Web UI (upload, categorise, review)
- **API:** Self-hosted REST endpoints

The system is built around a central ledger model, with multiple input sources feeding into journals and nominal accounts.

---

## Data Model Highlights

> Expense Claims and Transactions -> Categorise to Journal -> Year End approvals -> Accounting Facts -> iXBRL, XML and GovTalk generation -> Transmission to Test, Test in Live, and Live environments for both Companies House and HMRC -> Historic Evidence bundle when dealing with return queries.

---

## Project Goal

To provide a transparent, developer-friendly alternative to traditional accounting software by:

- Keeping full control of financial data
- Avoiding vendor lock-in
- Making tax logic explicit, transparent and inspectable
- Producing compliant outputs for HMRC and Companies House submissions

---

## Upstream eelKit

eelKit is maintained separately and imported into this repository as the upstream framework. Framework-level changes should be made in the eelKit project first, then merged back into eel.

eelKit provides a secure AJAX framework with Two Factor Authentication, CSRF anti-replay and Session Authentication.

eel keeps accounting behaviour in app-owned services, repositories, pages, cards, actions, schema, and migrations. Generic framework behaviour is in eelKit.

---

## Running Tests

Run the full local suite with:

```bash
php web_root/tests/index.php
```

The runner returns JSON and exits with a non-zero code if any test fails. This can take up to 8 minutes to run over 3,000+ tests ranging from simple assertions to the processing of a test only Golden Company's Account and iXBRL, XML and GovTalk artefact generation.

Skipped tests are reported separately and are never counted as passed. Test code can attach a machine-readable category when skipping:

```php
$harness->skip('MariaDB-specific assertion running under SQLite.', 'database-engine');
```

Normal runs remain healthy when tests are skipped. For readiness checks, enable strict skip handling and explicitly allow only expected environmental categories:

```bash
php web_root/tests/index.php \
  --strict-skips \
  --allow-skip-category=database-engine \
  --allow-skip-category=external-service
```

In strict mode, any skipped test whose category is not listed makes the run unhealthy and produces a non-zero exit code. Legacy one-argument `skip()` calls use the `unclassified` category.

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

> This project is currently in **Pre-Release**. Expect slightly rough edges and ongoing changes.
