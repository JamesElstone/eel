# EEL Accounts

EEL Accounts is a PHP accounting application for small company bookkeeping,
corporation tax preparation, VAT checks, Companies House data, HMRC
submission workflows, and year-end accounting review.

The application now consumes eelKit as its upstream framework. eelKit owns the
shared page, card, action, service, authentication, rendering, configuration,
and AJAX plumbing. EEL Accounts adds the accounting-specific pages, cards,
services, repositories, schema, migrations, and site context for selected
company and accounting period.

## Main Features

- Company management, including Companies House lookup and stored company data.
- Company and accounting period selectors via eelKit site context slots.
- Bank account setup, statement upload, transaction categorisation, and receipt
  attachment.
- Double-entry journal support and nominal ledger reporting.
- Trial balance, profit and loss, year-end readiness, and corporation tax
  computation workflows.
- Director loan, expenses, assets, dividends, VAT, and HMRC obligation screens.
- CT600/iXBRL preparation support and HMRC submission controls.
- Application settings, API mode checks, setup health checks, user management,
  roles, logs, activity feed, and developer diagnostics.

## Requirements

- PHP 8.4 or newer is recommended.
- MariaDB 10.11 or compatible MySQL/MariaDB server.
- PDO with the database driver used by the configured DSN.
- A web server that serves `web_root` as the public document root.
- Writable local paths for `secure`, `uploads`, and optional file logs.

## Project Layout

- `web_root/index.php` - eelKit-owned web entrypoint.
- `web_root/classes/framework` - eelKit framework layer.
- `web_root/classes/service` - shared and EEL Accounts service classes.
- `web_root/classes/repository` - database-facing repositories.
- `web_root/classes/store` - configuration and persistence stores.
- `web_root/content/pages` - page definitions.
- `web_root/content/cards` - card definitions rendered inside pages.
- `web_root/content/actions` - shared card and page action handlers.
- `db_schema/eel_accounts.schema.sql` - EEL Accounts database baseline.
- `db_schema/eel_accounts.nominals.sql` - nominal account seed data.
- `db_schema/eelKit.schema.sql` - eelKit framework database baseline.
- `db_schema/migrations` - incremental database migrations.
- `secure/app.php` - local runtime configuration.
- `tools` - setup, migration, password reset, IP refresh, and upstream import
  helpers.
- `uploads` - local company upload storage.
- `web_root/tests` - project test runner and tests.

## Configuration

Runtime configuration is stored outside the public document root in
`secure/app.php`. The application expects EEL Accounts settings such as:

- database DSN and credentials under `db`;
- upload storage paths under `uploads`;
- API key and security key file paths;
- HMRC and Companies House runtime modes;
- eelKit `site_context` configured to use `AccountingContextService`.

Do not serve `secure`, `uploads`, `db_schema`, `tools`, or `file_logs` as
public web directories.

## Database Setup

For a new install, configure the database connection and run the setup tool
from the project root:

```bash
php tools/php/setupDb.php
```

The setup tool creates or hydrates `secure/app.php` where needed, loads the
baseline schema for an empty database, applies pending migrations, and refreshes
the stored external IP setting used by anti-fraud headers.

To apply pending migrations only:

```bash
php tools/php/setupDb.php --migrate-only
```

On Windows Command Prompt, wrapper scripts are available under `tools\bat`.
On Unix-like shells, wrappers are available under `tools/bin`.

## Local Development

Serve `web_root` as the document root. In the local Codex environment this app
is usually available through:

```powershell
Invoke-WebRequest -Uri 'http://127.0.0.1/' -Headers @{ Host = 'eel.localhost' }
```

Developer diagnostics and the test runner require `developer_options` to be
enabled in configuration.

## Tests

Run the full local suite with:

```bash
php web_root/tests/index.php
```

The runner returns a non-zero exit code if any test fails.

## Upstream eelKit

eelKit is maintained separately and is imported into this repository as an
upstream framework. Framework-level changes should be made in the eelKit
project first, then merged back into EEL Accounts.

EEL Accounts should keep accounting behaviour in app-owned services,
repositories, pages, cards, and actions. Generic framework behaviour belongs in
eelKit.

## License

This repository is mixed-licensed. EEL Accounts application-specific files are
licensed under AGPLv3, eelKit framework files are licensed under the BSD
3-Clause License, and bundled fonts are licensed under the SIL Open Font
License 1.1. See `LICENSE` for the licence index and file-level notices for the
licence that applies to each file.
