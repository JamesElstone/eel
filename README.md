# eelKit Framework

eelKit is a small PHP application framework for building authenticated internal tools quickly. It currently ships with a working account area, role/card permissions, MFA setup, session/device checks, audit history, anti-fraud request metadata, and a lightweight card/page rendering model.

The project is deliberately simple: no package manager is required for the current codebase, the web entrypoint is plain PHP, and the included test runner can be executed directly with PHP.

## Benefits

- Secure account bootstrap flow for the first user.
- Password hashing with Argon2id and a server-side pepper.
- Time-based one-time password MFA setup and verification (supports FreeOTP).
- Session regeneration, CSRF protection, AJAX nonce protection, and device-bound session checks.
- Role based access to page cards, including a built-in Admin role.
- User login history and account audit tables.
- Centralised request, response, page, card, action, configuration, and database helper classes.
- Lightweight AJAX refresh model for cards and flash messages.
- Built-in SVG chart rendering for common dashboard and reporting graphs, without external chart libraries.
- Built-in developer test runner with broad class coverage.

## Requirements

- PHP 8.4 or newer is recommended.
- PHP extensions: `pdo`, `pdo_odbc` or another PDO driver that matches your DSN, `mbstring`, `json`, and `session`.
- MariaDB 10.11 or compatible MySQL/MariaDB server.
- A web server that can serve `web_root` as the document root.

## Project Layout

- `web_root/index.php` - main web entrypoint.
- `web_root/classes` - framework, service (including user services), store, repository, and database classes.
- `web_root/content/pages` - page definitions.
- `web_root/content/cards` - card definitions rendered inside pages.
- `web_root/content/actions` - shared card action handlers.
- `web_root/classes/service/ChartService.php` - internal chart renderer.
- `secure/app.php` - application configuration, hydrated automatically on first run.
- `db_schema/eelKit.schema.sql` - full database schema for a new install.
- `tools/php/reset_password.php` - A CLI password reset helper.
- `tools/php/setupDb.php` - A CLI helper for setup, database configuration, migrations, and external IP refresh.
- `tools/php/setExternalIP.php` - A CLI helper for storing external IP configuration.
- `web_root/tests` - project test runner and test cases.
- `secure` - local secrets such as generated security keys and bootstrap code.
- `file_logs` - local log output.

## Installation

1. Clone or copy the project to the server.

2. Configure the web server so `web_root` is the public document root. Do not expose `secure`, `db_schema`, `tools`, or `file_logs` as web-accessible directories.

3. Create the database:

   ```sql
   CREATE DATABASE eelKit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. Configure a PDO DSN through the setup tool. If `secure/app.php` does not exist yet, visiting the app or running a tool will create it from the built-in defaults:

   ```php
   'db' => [
       'dsn' => 'odbc:eelkit',
       'user' => '',
       'pass' => '',
       'logfile' => '',
   ],
   ```

   The setup tool asks for database settings if `db.dsn` is empty. You can also pass settings directly, for example `php tools/php/setupDb.php --driver=mysql --host=127.0.0.1 --database=eelkit --user=root`.

5. Run the database setup tool. It makes sure `secure/app.php` exists, configures the database if needed, runs migrations, loads the baseline schema first if the configured database has no eelKit application tables, and then refreshes the external IP setting:

   ```bash
   php tools/php/setupDb.php
   ```

6. Make sure the PHP process can write to:

   - `secure`
   - `file_logs`, if SQL logging is enabled
   - any configured upload directory

7. Visit the application in a browser. If no users exist, eelKit creates a bootstrap code file at:

   ```text
   secure/bootstrap_code.txt
   ```

   Use that code on the first-account setup screen. The file is removed after the first user is created.

8. Set `developer_options` to `false` in `secure/app.php` for production use once setup and diagnostics are complete.

## Database Schema Notes

The schema uses InnoDB foreign keys for user-owned records:

- `role_card_permissions.role_id` references `roles.id`.
- `user_account_audit.affected_user_id` and `actor_user_id` reference `users.id`.
- `user_login_rate_limits.user_id` references `users.id` and is set to `NULL` if the user is deleted.
- `user_logon_history.user_id` references `users.id` and is set to `NULL` if the user is deleted.
- `user_totp.user_id` references `users.id` and cascades on user deletion.

`users.role_id` intentionally allows the reserved value `-1`, which represents the built-in Admin role in PHP. Positive values represent rows in `roles`. The schema includes a check constraint for that convention, but it does not add a foreign key for `users.role_id` because a normal foreign key cannot express "either the built-in sentinel or a valid role row" without changing the current application contract.

## Migrations

`db_schema/eelKit.schema.sql` is the baseline schema for a new database. The migration tool loads it automatically only when none of the eelKit application tables exist.

Incremental upgrades live in `db_schema/migrations` and are applied in filename order by:

```bash
php tools/php/setupDb.php --migrate-only
```

For first-time setup, prefer:

```bash
php tools/php/setupDb.php
```

The migration runner creates a `schema_migrations` table and records each applied SQL file. Keep migration filenames ordered with the date and a sequence number, for example:

```text
2026_05_08_001_schema_integrity.sql
2026_05_08_002_add_upload_metadata.sql
```

For existing databases that already match the original baseline, insert the baseline marker into `schema_migrations` once, then run the migration tool:

```sql
INSERT INTO schema_migrations (migration)
VALUES ('2026_05_07_001_initial_schema.sql');
```

## Graph Support

eelKit includes an internal chart service, `ChartService`, for rendering graphs directly from PHP card output without external JavaScript charting libraries.

Current graph types:

- Bar graph.
- Stacked bar graph with multiple series.
- Line graph with support for up to five series.
- Pie chart.
- Donut chart.
- Gauge.
- Sankey diagram for value flows, such as income sources flowing into total value and then into allocations.
- Calendar heat map.
- Monthly heat map.

The `Example Graphs` page demonstrates the available graph cards and can be used as a reference for adding chart output to application-specific cards.

## Running Tests

Run the full local suite with:

```bash
php web_root/tests/index.php
```

The runner returns JSON and exits with a non-zero code if any test fails.

## Useful Tools

Reset a user's password from the command line:

```bash
php tools/php/reset_password.php
```

Set or refresh external IP related configuration:

```bash
php tools/php/setExternalIP.php
```

Hydrate `secure/app.php` and initialise or migrate the configured database:

```bash
php tools/php/setupDb.php
```

## Production Checklist

- Serve only `web_root` publicly.
- Set `developer_options` to `false`.
- Disable SQL logging unless actively diagnosing an issue.
- Keep `secure/*.keys` out of version control and backed up securely.
- Use HTTPS so secure cookies can be enabled automatically.
- Restrict file permissions on `secure` and configuration files.
- Run the test suite after schema or framework changes.

## License

eelKit is licensed under the BSD 3-Clause License. See `LICENSE` for details.
