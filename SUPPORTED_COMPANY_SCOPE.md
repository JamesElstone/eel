# Supported Company Scope

This document defines the current supported scope of EEL Accounts.

EEL Accounts is intentionally focused on producing reliable statutory accounts and Corporation Tax submissions for a well-defined class of UK companies. Where a company or filing falls outside this supported scope, the software will explain why and disable the affected filing features rather than producing results that may be incomplete or inaccurate.

The supported scope expands over time as additional functionality is implemented and tested.

---

# Company Types

## Supported

* UK Private Company Limited by Shares
* Registered with Companies House
* Active on the Companies House register
* UK Corporation Tax companies with a valid HMRC UTR
* Micro-entities preparing accounts under **FRS 105**

## Not Currently Supported

* LLPs
* Companies limited by guarantee
* Public limited companies (PLCs)
* Unlimited companies
* Overseas companies
* Charities
* Community Interest Companies (CICs)
* Companies preparing accounts under FRS 102 or IFRS

---

# Financial Reporting

## Supported

* FRS 105 statutory accounts
* Micro-entity balance sheet
* Profit and Loss Account
* Notes required by FRS 105
* Director's Report where applicable

## Not Currently Supported

* FRS 102
* IFRS
* Consolidated accounts
* Group accounts
* Parent company accounts
* Audit reporting

---

# Corporation Tax

## Supported

* CT600
* CT600A
* iXBRL accounts generation
* iXBRL computations
* GovTalk XML generation
* HMRC submission workflow integration

## Planned

The following may be supported in future releases:

* Additional CT600 supplementary pages
* Additional tax computations

---

# Companies House

## Supported

* Company authentication
* Company profile retrieval
* Officer information
* Filing history retrieval
* Electronic filing workflow integration for supported accounts

---

# VAT

## Current Position

VAT registered businesses are not currently supported.

VAT accounting, VAT returns and Making Tax Digital (MTD) functionality are outside the current scope of EEL Accounts.

---

# Director and Participator Loan Accounts

## Supported

* Director and participator loan accounts
* Loan advances
* Loan repayments
* Credit balances
* Debit balances
* Director Loan Statement
* Automatic statutory disclosure generation
* CT600A calculations

---

# Payroll

Payroll processing is outside the current scope of EEL Accounts.

---

# Banking

## Supported

* CSV bank statement import
* Trade account import
* Double-entry bookkeeping
* Transaction categorisation
* Bank reconciliation

---

# Electronic Filing

## Implemented

* CT600 and CT600A payload generation
* iXBRL accounts and computations
* GovTalk XML generation
* Local XML and iXBRL validation
* Submission workflow integration

## Recognition Status

HMRC live filing has not yet completed the software recognition and live-submission process. Filing functionality must therefore be treated as under development and must not be represented as HMRC-approved or production-recognised.

Developer credentials are required to enable electronic filing.

---

# Validation

EEL Accounts continuously validates whether a company remains within its supported scope.

If a company falls outside the supported scope:

* statutory accounts generation may be disabled;
* Corporation Tax filing may be disabled;
* Companies House filing may be disabled;
* the software explains which condition is unsupported; and
* no filing is enabled for submission that EEL Accounts cannot confidently support.

---

# Design Principles

EEL Accounts is developed according to the following principles:

* Correctness takes priority over feature count.
* Filing should only be enabled where the software can produce compliant output.
* Every submitted figure should be traceable back to the underlying accounting records.
* Validation should identify unsupported situations before filing.
* The software should never knowingly generate misleading statutory filings.
* Supported scope should expand through testing and implementation rather than assumption.

---

# Version Information

| Item                   | Supported       |
| ---------------------- | --------------- |
| Accounting Standard    | FRS 105         |
| Corporation Tax Return | CT600           |
| Supplementary Pages    | CT600A          |
| Accounts Format        | Micro-entity    |
| Filing                 | Companies House |
| Filing                 | HMRC            |

---

This document describes the supported scope of the current release of EEL Accounts and may change as new functionality becomes available.
