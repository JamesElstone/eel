# Tools

The `tools` directory contains command line helpers for setting up, maintaining, and managing an eelKit installation.

## Directory Structure

```text
tools/
  bat/        Windows Command Prompt wrappers
  bin/        Unix shell wrappers for Linux, macOS, Git Bash, and similar shells
  php/        PHP implementations used by the wrappers
  README.md   This guide
```

Run the wrapper that matches your shell from the project root. The wrappers resolve the PHP scripts relative to their own location, so they can be run from any current working directory.

## Requirements

- PHP must be available on your `PATH`.
- Database-related tools require a valid application configuration in `secure/app.php`.
- Git-related tools require Git to be available on your `PATH`.

## setupDb

Creates the stored application configuration if needed, loads the baseline schema for an empty database, and applies pending SQL migrations.

Linux, macOS, or Git Bash:

```sh
tools/bin/setupDb.sh
```

Windows Command Prompt:

```bat
tools\bat\setupDb.bat
```

Direct PHP:

```sh
php tools/php/setupDb.php
```

## migrateDb

Applies pending SQL migrations from `db_schema/migrations`. If the configured database is empty, it first loads `db_schema/eelKit.schema.sql`.

Linux, macOS, or Git Bash:

```sh
tools/bin/migrateDb.sh
```

Windows Command Prompt:

```bat
tools\bat\migrateDb.bat
```

Direct PHP:

```sh
php tools/php/migrateDb.php
```

## setDbConfig

Updates `db.dsn`, `db.user`, and `db.pass` in `secure/app.php`. It can run interactively or accept options.

Interactive:

```sh
tools/bin/setDbConfig.sh
```

Windows Command Prompt:

```bat
tools\bat\setDbConfig.bat
```

Example direct PHP options:

```sh
php tools/php/setDbConfig.php --driver=mysql --host=127.0.0.1 --database=eelkit --user=root
php tools/php/setDbConfig.php --driver=odbc --odbc-name=eelkit
php tools/php/setDbConfig.php --driver=sqlite --sqlite-path=secure/eelkit.sqlite
php tools/php/setDbConfig.php --dsn="mysql:host=127.0.0.1;dbname=eelkit;charset=utf8mb4" --user=root
```

Use `--help` to show the available options:

```sh
php tools/php/setDbConfig.php --help
```

## setExternalIP

Looks up the current public IP address and stores it as `antifraud.vendor_public_ip` in `secure/app.php`.

Linux, macOS, or Git Bash:

```sh
tools/bin/setExternalIP.sh
```

Windows Command Prompt:

```bat
tools\bat\setExternalIP.bat
```

Direct PHP:

```sh
php tools/php/setExternalIP.php
```

## resetPassword

Connects to the configured database, finds a user by display name or email address, resets the user's password, and optionally resets OTP setup.

Linux, macOS, or Git Bash:

```sh
tools/bin/resetPassword.sh
```

Windows Command Prompt:

```bat
tools\bat\resetPassword.bat
```

Direct PHP:

```sh
php tools/php/reset_password.php
```

## projectGit

Helps create a project repository from eelKit while keeping eelKit available as an upstream remote. It can also import later eelKit changes from that upstream remote.

Initialize a project origin:

```sh
tools/bin/projectGit.sh init git@github.com:you/yourProjectRepo.git
```

Windows Command Prompt:

```bat
tools\bat\projectGit.bat init git@github.com:you/yourProjectRepo.git
```

Import upstream eelKit changes:

```sh
tools/bin/projectGit.sh import
tools/bin/projectGit.sh import upstream main --rebase
```

Direct PHP:

```sh
php tools/php/projectGit.php import
```

See `tools/projectGit.md` for the detailed workflow.
