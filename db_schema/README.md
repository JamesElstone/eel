# Database Schema

The `db_schema` directory contains the database baselines and incremental SQL
migrations used by EEL Accounts.

EEL Accounts now has two database layers:

- eelKit framework tables for users, roles, permissions, login history, OTP,
  activity, and application audit behaviour.
- EEL Accounts application tables for companies, accounting periods, uploaded
  statements, transactions, journals, nominal accounts, expenses, assets,
  Companies House data, HMRC obligations, iXBRL, year-end review, and tax
  reporting workflows.

## Directory Structure

```text
db_schema/
  eel_accounts.schema.sql     Full EEL Accounts MariaDB schema dump
  eel_accounts.nominals.sql   Nominal account and subtype seed data
  eelKit.schema.sql           eelKit framework baseline schema
  migrations/                 Ordered incremental SQL migrations
  README.md                   This guide
```

## Schema Files

`eel_accounts.schema.sql` is the full EEL Accounts schema baseline. It includes
the accounting tables and the framework tables needed by this application. It
is a MariaDB dump and includes `DROP TABLE` statements, so treat it as a fresh
database restore/import file, not as an upgrade script for a live database.

`eel_accounts.nominals.sql` contains the starter nominal account structure used
by EEL Accounts. It assumes the nominal tables already exist and is intended for
fresh database setup or deliberate reseeding. Do not run it blindly against a
database that already contains live accounting data.

`eelKit.schema.sql` is the eelKit framework baseline. The current eelKit setup
and migration tooling hydrates empty databases from this file when none of the
framework application tables exist.

## Migrations

`migrations/` contains one SQL file per schema change. Files are applied in
filename order, and each applied filename is recorded in `schema_migrations`.

Current naming style:

```text
YYYY_MM_DD_NNN_short_description.sql
```

Examples:

```text
2026_05_08_001_schema_integrity.sql
2026_05_08_002_force_password_change.sql
2026_05_08_003_user_otp_optional.sql
```

When adding a migration:

- Use the next sequence number for that date.
- Keep each migration focused on one schema change.
- Use transactions where the database engine supports them.
- Preserve accounting history and auditability.
- Do not drop tables, drop columns, or destructively rewrite accounting data
  without an explicit migration plan and approval.
- Do not rename an already-applied migration file, because the filename is the
  migration identity.
- Update `eel_accounts.schema.sql` when the EEL Accounts fresh-install baseline
  should include the same final structure.
- Update `eelKit.schema.sql` only for framework-owned schema changes that also
  belong upstream in eelKit.

## Running Migrations

Use the tools in `tools/` from the project root.

Set up configuration, hydrate an empty framework database, apply pending
migrations, and update the stored external IP:

```sh
php tools/php/setupDb.php
```

Apply pending migrations only, without changing database settings or the stored
external IP:

```sh
php tools/php/setupDb.php --migrate-only
php tools/php/migrateDb.php
```

Windows Command Prompt wrappers:

```bat
tools\bat\setupDb.bat
tools\bat\setupDb.bat --migrate-only
tools\bat\migrateDb.bat
```

Unix-like shell wrappers:

```sh
tools/bin/setupDb.sh
tools/bin/setupDb.sh --migrate-only
tools/bin/migrateDb.sh
```

## Fresh EEL Accounts Database

For a full EEL Accounts database bootstrap from the SQL baselines, use a new
empty database and import in this order:

```text
1. db_schema/eel_accounts.schema.sql
2. db_schema/eel_accounts.nominals.sql
3. pending files from db_schema/migrations, if any are not already recorded
```

The schema dump is not a narrow migration script. It should not be applied over
an existing production database.

## Runtime Configuration

The setup and migration tools use database settings from `secure/app.php`. To
change the configured database connection, run:

```sh
php tools/php/setupDb.php --configure-db
```

or use the shell wrappers:

```sh
tools/bin/setupDb.sh --configure-db
```

```bat
tools\bat\setupDb.bat --configure-db
```

## Notes For eelKit Updates

If a change is generic framework schema, make it in eelKit first and merge it
back into EEL Accounts. If a change is accounting-specific, keep it in EEL
Accounts and update the EEL schema/migrations here.
