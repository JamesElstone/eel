# Upload Storage

This directory is the default private upload store for EEL Accounts.

It is intentionally outside `web_root` so uploaded accounting files, bank CSVs,
receipts, and generated local document copies cannot be served directly by the
web server.

## Purpose

Files stored here are company-specific and are linked to database records. The
database remains the source of truth for what each file means; this directory is
only the file payload storage.

Typical stored files include:

- uploaded bank statement CSV files;
- uploaded expense receipt files;
- downloaded transaction receipt/document copies.
- uploaded manual asset evidence files.

## Directory Shape

The base path is configured in `secure/app.php`:

```php
'uploads' => [
    'upload_base_dir' => '...',
    'statement_relative_path' => './statements/',
    'expense_receipts_relative_path' => './expense_receipts/',
    'transaction_receipts_relative_path' => './transaction_receipts/',
]
```

Company folders are created under the upload base directory using the selected
company's company number. The usual layout is:

```text
files/
  {company-number}/
    statements/
    expense_receipts/
    transaction_receipts/
    manual_asset_evidence/
    companies_house/
      test/
        {six-digit-submission-number}/
      live/
        {six-digit-submission-number}/
    hmrc/
      til/
        {local-submission-reference}/
      live/
        {local-submission-reference}/
```

## Safety Rules

- Do not configure the web server to serve this directory directly.
- Do not store files here unless they belong to an EEL Accounts company record.
- Do not manually delete company files unless the related database state has
  also been considered.
- Back up this directory with the database, because the two are linked.
- Keep filesystem permissions restricted to the application/runtime user and
  trusted administrators.
- Treat authority transmission folders as immutable filing evidence. Each
  transmission directory contains the exact sent and received XML plus a
  SHA-256 manifest; do not serve these files directly or edit them by hand.

Use the application screens and services for cleanup where possible so database
references and files stay in sync.
